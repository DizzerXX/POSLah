import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../data/local_database.dart';
import 'backoff.dart';

/// The statuses an outbox entry moves through.
class OutboxStatus {
  static const pending = 'pending';

  /// Handed to a push attempt that has not come back yet. The dangerous state: if the app
  /// dies here, nothing else will ever move this row unless something explicitly recovers it.
  static const inFlight = 'in_flight';

  static const synced = 'synced';

  /// The server refused it and will refuse it again. Not retried; surfaced instead.
  static const rejected = 'rejected';
}

/// Dependency depth, mirroring the server's. An order must reach the server before the
/// ledger entry and stock movements that reference it.
const _dependencyDepth = <String, int>{
  'customer': 0,
  'time_entry': 0,
  'order': 1,
  'ledger_entry': 2,
  'stock_movement': 2,
};

/// The queue of local facts waiting to reach the server.
class Outbox {
  Outbox(this._db, {Uuid? uuid}) : _uuid = uuid ?? const Uuid();

  final LocalDatabase _db;
  final Uuid _uuid;

  /// Enqueues a snapshot of something that just happened.
  ///
  /// The payload is captured now, not read back at send time. Between enqueue and send the
  /// underlying row can change, and what must reach the server is what was true when the
  /// event occurred — the same reasoning behind the snapshot columns on an order line.
  Future<String> enqueue({
    required String entityType,
    required String entityId,
    required Map<String, dynamic> payload,
    String operation = 'create',
    DateTime? createdAt,
  }) async {
    final id = _uuid.v7();

    await _db.into(_db.syncOutbox).insert(
          SyncOutboxCompanion.insert(
            id: id,
            entityType: entityType,
            entityId: entityId,
            operation: Value(operation),
            payload: jsonEncode(payload),
            createdAt: createdAt ?? DateTime.now().toUtc(),
          ),
        );

    return id;
  }

  /// Reverts entries stranded `in_flight` back to `pending`.
  ///
  /// Called on every launch. If the app was killed mid-batch — force-quit, battery, an OS
  /// deciding it needed the memory — those entries would otherwise sit in a state nothing
  /// ever leaves, and the sales in them would never reach the server while the till showed
  /// no error at all.
  ///
  /// Resending is safe without any further thought: the ids are client-generated, so the
  /// server treats a second arrival as a duplicate. This recovery does not need to know
  /// whether the batch landed, which is exactly why it can be this blunt.
  Future<int> recoverStrandedEntries() {
    return (_db.update(_db.syncOutbox)..where((t) => t.syncStatus.equals(OutboxStatus.inFlight)))
        .write(const SyncOutboxCompanion(syncStatus: Value(OutboxStatus.pending)));
  }

  /// The next batch to send, oldest first, capped at [limit].
  ///
  /// Sorted by dependency depth before creation time. Sorting by time alone is very nearly
  /// right and occasionally wrong: a movement enqueued in the same millisecond as its order
  /// can sort ahead of it, and the server would reject it for a missing dependency.
  Future<List<SyncOutboxData>> nextBatch({int limit = 50, DateTime? now}) async {
    final at = now ?? DateTime.now().toUtc();

    final entries = await (_db.select(_db.syncOutbox)
          ..where((t) => t.syncStatus.equals(OutboxStatus.pending))
          ..where((t) => t.nextAttemptAt.isNull() | t.nextAttemptAt.isSmallerOrEqualValue(at))
          ..orderBy([(t) => OrderingTerm.asc(t.createdAt)])
          ..limit(limit))
        .get();

    entries.sort((a, b) {
      final depth = (_dependencyDepth[a.entityType] ?? 99).compareTo(
        _dependencyDepth[b.entityType] ?? 99,
      );

      return depth != 0 ? depth : a.createdAt.compareTo(b.createdAt);
    });

    return entries;
  }

  /// Marks a batch as in flight. One batch at a time per device: parallel batches buy
  /// nothing and make ordering bugs non-deterministic.
  Future<void> markInFlight(List<SyncOutboxData> entries) async {
    if (entries.isEmpty) return;

    await (_db.update(_db.syncOutbox)..where((t) => t.id.isIn(entries.map((e) => e.id))))
        .write(const SyncOutboxCompanion(syncStatus: Value(OutboxStatus.inFlight)));
  }

  /// Applies one per-entry result from the server.
  ///
  /// `duplicate` is a success. The row is already there, which is the outcome the whole
  /// client-generated-id design exists to make free — the till never has to ask whether an
  /// earlier attempt got through.
  Future<void> applyResult({
    required String entryId,
    required String status,
    String? reason,
    String? detail,
  }) async {
    final query = _db.update(_db.syncOutbox)..where((t) => t.id.equals(entryId));

    switch (status) {
      case 'accepted':
      case 'duplicate':
        await query.write(
          const SyncOutboxCompanion(
            syncStatus: Value(OutboxStatus.synced),
            lastError: Value(null),
            nextAttemptAt: Value(null),
          ),
        );
      case 'rejected':
        // A bug to fix, not a transient failure to wait out. It stops here and gets shown
        // in the debug screen; retrying would reproduce it forever.
        await query.write(
          SyncOutboxCompanion(
            syncStatus: const Value(OutboxStatus.rejected),
            lastError: Value([reason, detail].whereType<String>().join(': ')),
            nextAttemptAt: const Value(null),
          ),
        );
      default:
        throw ArgumentError('Unknown per-entry status: $status');
    }
  }

  /// The whole attempt failed transiently. Entries go back to `pending` with their attempt
  /// count raised and the next attempt scheduled.
  Future<void> markAttemptFailed(
    List<SyncOutboxData> entries,
    String error,
    Duration Function(int attempts) delayFor, {
    DateTime? now,
  }) async {
    final at = now ?? DateTime.now().toUtc();

    for (final entry in entries) {
      final attempts = entry.attempts + 1;

      await (_db.update(_db.syncOutbox)..where((t) => t.id.equals(entry.id))).write(
        SyncOutboxCompanion(
          syncStatus: const Value(OutboxStatus.pending),
          attempts: Value(attempts),
          lastError: Value(error),
          nextAttemptAt: Value(at.add(delayFor(attempts))),
        ),
      );
    }
  }

  /// Entries the server refused at the envelope level — a 4xx. Not retried.
  Future<void> markBatchRejected(List<SyncOutboxData> entries, String error) async {
    if (entries.isEmpty) return;

    await (_db.update(_db.syncOutbox)..where((t) => t.id.isIn(entries.map((e) => e.id)))).write(
      SyncOutboxCompanion(
        syncStatus: const Value(OutboxStatus.rejected),
        lastError: Value(error),
        nextAttemptAt: const Value(null),
      ),
    );
  }

  Future<int> countByStatus(String status) async {
    final count = _db.syncOutbox.id.count();

    final row = await (_db.selectOnly(_db.syncOutbox)
          ..addColumns([count])
          ..where(_db.syncOutbox.syncStatus.equals(status)))
        .getSingle();

    return row.read(count) ?? 0;
  }

  /// Entries that have failed enough times to be worth telling somebody about.
  Future<List<SyncOutboxData>> stuckEntries() {
    return (_db.select(_db.syncOutbox)
          ..where((t) => t.attempts.isBiggerOrEqualValue(Backoff.alertAfterAttempts))
          ..where((t) => t.syncStatus.equals(OutboxStatus.pending)))
        .get();
  }

  /// The envelope, ready to send.
  Map<String, dynamic> buildBatch(String deviceId, List<SyncOutboxData> entries) {
    return {
      'batch_id': _uuid.v7(),
      'device_id': deviceId,
      'entries': [
        for (final entry in entries)
          {
            'id': entry.id,
            'entity_type': entry.entityType,
            'entity_id': entry.entityId,
            'operation': entry.operation,
            'payload': jsonDecode(entry.payload),
          },
      ],
    };
  }
}
