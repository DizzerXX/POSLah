import '../data/local_database.dart';
import 'backoff.dart';
import 'catalog_applier.dart';
import 'outbox.dart';
import 'sync_transport.dart';

/// The outcome of one drain attempt, for the debug screen and for tests.
class DrainReport {
  const DrainReport({
    this.batchesSent = 0,
    this.accepted = 0,
    this.duplicate = 0,
    this.rejected = 0,
    this.transientFailure,
  });

  final int batchesSent;
  final int accepted;
  final int duplicate;
  final int rejected;
  final String? transientFailure;

  /// A duplicate is a success. Counting it as anything else would make a healthy retry look
  /// like a problem.
  int get synced => accepted + duplicate;

  bool get isIdle => batchesSent == 0 && transientFailure == null;
}

/// Drains the outbox and applies the catalog delta.
///
/// Nothing in the checkout path calls into this class. A sale is complete when it is written
/// locally; whether this succeeds is invisible to the cashier and must stay that way. If a
/// checkout screen ever learns about sync state, something has leaked.
class SyncEngine {
  SyncEngine({
    required this.db,
    required this.outbox,
    required this.transport,
    required this.deviceId,
    this.batchSize = 50,
  });

  final LocalDatabase db;
  final Outbox outbox;
  final SyncTransport transport;
  final String deviceId;
  final int batchSize;

  bool _draining = false;

  /// Call once at launch, before anything else.
  ///
  /// Recovers entries stranded `in_flight` by a kill mid-batch. Without this the sales in
  /// that batch are silently marooned: no error, no retry, and a merchant whose day totals
  /// are quietly short.
  Future<int> recoverOnLaunch() => outbox.recoverStrandedEntries();

  /// Sends everything currently pending, one batch at a time.
  ///
  /// One in-flight batch per device, enforced by a plain guard rather than a lock: parallel
  /// batches would buy nothing here and would make ordering bugs non-deterministic, which is
  /// the worst property a sync bug can have.
  Future<DrainReport> drain({DateTime? now}) async {
    if (_draining) return const DrainReport();

    _draining = true;

    var batches = 0;
    var accepted = 0;
    var duplicate = 0;
    var rejected = 0;

    try {
      while (true) {
        final entries = await outbox.nextBatch(limit: batchSize, now: now);
        if (entries.isEmpty) break;

        await outbox.markInFlight(entries);

        final Map<String, dynamic> response;

        try {
          response = await transport.push(outbox.buildBatch(deviceId, entries));
        } on TransientSyncFailure catch (error) {
          // Back to pending with the backoff clock running. The sales are not lost; they
          // are waiting.
          await outbox.markAttemptFailed(entries, error.message, Backoff.delayForAttempt, now: now);

          return DrainReport(
            batchesSent: batches,
            accepted: accepted,
            duplicate: duplicate,
            rejected: rejected,
            transientFailure: error.message,
          );
        } on PermanentSyncFailure catch (error) {
          // The server refused the envelope itself. Retrying reproduces it exactly, so
          // these stop here and get surfaced rather than cycling forever.
          await outbox.markBatchRejected(entries, error.toString());
          rejected += entries.length;
          batches++;
          continue;
        }

        batches++;

        final results = (response['results'] as List<dynamic>).cast<Map<String, dynamic>>();

        for (final result in results) {
          final status = result['status'] as String;

          await outbox.applyResult(
            entryId: result['entry_id'] as String,
            status: status,
            reason: result['reason'] as String?,
            detail: result['detail'] as String?,
          );

          switch (status) {
            case 'accepted':
              accepted++;
            case 'duplicate':
              duplicate++;
            case 'rejected':
              rejected++;
          }
        }

        // A short batch means the queue is drained.
        if (entries.length < batchSize) break;
      }
    } finally {
      _draining = false;
    }

    return DrainReport(
      batchesSent: batches,
      accepted: accepted,
      duplicate: duplicate,
      rejected: rejected,
    );
  }

  /// Fetches the catalog delta and applies it locally.
  ///
  /// The cursor sent is the `server_time` the server returned last time, never this
  /// device's clock. A till running fast would otherwise skip rows silently, which is the
  /// worse of the two failure modes and the harder to notice.
  Future<CatalogApplyReport> pullCatalog() async {
    final since = await db.readState(SyncStateKeys.lastPullAt);

    final response = await transport.pull(since: since);

    final report = await CatalogApplier(db).apply(response);

    await db.writeState(SyncStateKeys.lastPullAt, response['server_time'] as String);

    // Entitlements are re-read on every pull, not just at login: a till left signed in for
    // three weeks must not still be running on three-week-old modules.
    await db.writeState(
      SyncStateKeys.enabledModules,
      (response['enabled_modules'] as List<dynamic>).join(','),
    );

    return report;
  }
}
