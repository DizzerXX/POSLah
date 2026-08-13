import 'package:poslah_till/sync/sync_transport.dart';

/// An in-process stand-in for the sync API.
///
/// Not a mock: it records nothing about who called it and asserts nothing about being
/// called. It is a working implementation of the server's contract — client-generated ids,
/// per-entry results, duplicates on resend — so the drain loop can be exercised against
/// something that behaves like the real thing rather than against canned responses.
///
/// The contract it implements is pinned by the Laravel test suite on the other side; the
/// live integration test in `sync_live_test.dart` is what proves the two agree.
class FakeServer implements SyncTransport {
  FakeServer({this.serverTime = '2026-08-13T09:00:00+00:00'});

  /// Entity ids the server has already seen. A second arrival is a duplicate, which is a
  /// success.
  final Set<String> storedEntities = {};

  /// Outbox entry ids the server was asked to process, in order, across all attempts.
  final List<String> receivedEntryIds = [];

  final List<Map<String, dynamic>> receivedBatches = [];

  /// Entity ids the server should refuse. Stands in for a poison entry.
  final Set<String> rejectIds = {};

  /// Set to make the next push fail. Cleared after it fires.
  Exception? nextPushFailure;

  /// When true, the push is processed and stored but the response never arrives — the
  /// shape of a crash or a dropped connection *after* the server committed.
  bool loseResponse = false;

  String serverTime;

  Map<String, dynamic> pullResponse = const {
    'server_time': '2026-08-13T09:00:00+00:00',
    'enabled_modules': ['retail'],
    'changes': <String, dynamic>{},
  };

  @override
  Future<Map<String, dynamic>> push(Map<String, dynamic> batch) async {
    final failure = nextPushFailure;

    if (failure != null) {
      nextPushFailure = null;
      throw failure;
    }

    receivedBatches.add(batch);

    final entries = (batch['entries'] as List<dynamic>).cast<Map<String, dynamic>>();
    final results = <Map<String, dynamic>>[];

    for (final entry in entries) {
      final entryId = entry['id'] as String;
      final entityId = entry['entity_id'] as String;

      receivedEntryIds.add(entryId);

      if (rejectIds.contains(entityId)) {
        results.add({
          'entry_id': entryId,
          'status': 'rejected',
          'reason': 'validation',
          'detail': 'Refused by the fake server.',
        });
        continue;
      }

      // Idempotency, the same way the real server does it: the id came from the till, so a
      // second arrival is recognised without any dedupe bookkeeping.
      final isNew = storedEntities.add(entityId);

      results.add({
        'entry_id': entryId,
        'status': isNew ? 'accepted' : 'duplicate',
      });
    }

    if (loseResponse) {
      // The work landed; the answer did not. This is the case the till must survive, and
      // the reason resending has to be safe rather than merely unlikely to be needed.
      throw TransientSyncFailure('Connection closed after the server committed.');
    }

    return {
      'batch_id': batch['batch_id'],
      'results': results,
      'server_time': serverTime,
    };
  }

  @override
  Future<Map<String, dynamic>> pull({String? since}) async {
    lastPullCursor = since;

    return pullResponse;
  }

  String? lastPullCursor;
}
