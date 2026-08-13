import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/sync/backoff.dart';
import 'package:poslah_till/sync/outbox.dart';
import 'package:poslah_till/sync/sync_engine.dart';
import 'package:poslah_till/sync/sync_transport.dart';

import 'support/fake_server.dart';
import 'support/till_fixture.dart';

void main() {
  late LocalDatabase db;
  late FakeServer server;
  late TillFixture till;

  setUp(() {
    db = LocalDatabase.memory();
    server = FakeServer();
    till = TillFixture(db, server);
  });

  tearDown(() => till.close());

  group('drain', () {
    test('sends a sale and marks every entry synced', () async {
      await enqueueSale(till.outbox, variantId: 'variant-1');

      final report = await till.engine.drain();

      expect(report.accepted, 3);
      expect(report.rejected, 0);
      expect(await till.outbox.countByStatus(OutboxStatus.synced), 3);
      expect(await till.outbox.nextBatch(), isEmpty);
    });

    test('an idle outbox costs one query and sends nothing', () async {
      final report = await till.engine.drain();

      expect(report.isIdle, isTrue);
      expect(server.receivedBatches, isEmpty);
    });

    test('keeps sending until the queue is empty', () async {
      for (var i = 0; i < 7; i++) {
        await enqueueSale(
          till.outbox,
          variantId: 'variant-1',
          orderNumber: 'T1-000$i',
          occurredAt: DateTime.utc(2026, 8, 13, 8).add(Duration(minutes: i)),
        );
      }

      // 21 entries against a ceiling of 5 means five round trips, and the loop has to keep
      // going rather than stopping after the first full batch.
      final engine = SyncEngine(
        db: db,
        outbox: till.outbox,
        transport: server,
        deviceId: deviceId,
        batchSize: 5,
      );

      final report = await engine.drain();

      expect(report.synced, 21);
      expect(report.batchesSent, 5);
      expect(await till.outbox.countByStatus(OutboxStatus.pending), 0);
    });

    /// A poison entry is refused on its own and the sales beside it still land. This is the
    /// client half of the guarantee the server makes with per-entry results — if the till
    /// reacted to any rejection by failing the batch, the server's care would be wasted.
    test('one rejected entry does not stop the rest of the batch', () async {
      await enqueueSale(till.outbox, variantId: 'variant-1');

      final entries = await till.outbox.nextBatch();
      server.rejectIds.add(entries[1].entityId);

      final report = await till.engine.drain();

      expect(report.accepted, 2);
      expect(report.rejected, 1);
      expect(await till.outbox.countByStatus(OutboxStatus.synced), 2);
      expect(await till.outbox.countByStatus(OutboxStatus.rejected), 1);
    });
  });

  group('failure handling', () {
    test('a transient failure returns entries to pending with backoff scheduled', () async {
      await enqueueSale(till.outbox, variantId: 'variant-1');

      final now = DateTime.utc(2026, 8, 13, 9);
      server.nextPushFailure = TransientSyncFailure('No route to host');

      final report = await till.engine.drain(now: now);

      expect(report.transientFailure, contains('No route to host'));
      expect(await till.outbox.countByStatus(OutboxStatus.pending), 3);

      // Not eligible immediately — but not lost either.
      expect(await till.outbox.nextBatch(now: now), isEmpty);
      expect(
        await till.outbox.nextBatch(now: now.add(Backoff.delayForAttempt(1) + const Duration(seconds: 1))),
        hasLength(3),
      );
    });

    test('the same entries go out again once the delay has passed', () async {
      await enqueueSale(till.outbox, variantId: 'variant-1');

      final now = DateTime.utc(2026, 8, 13, 9);
      server.nextPushFailure = TransientSyncFailure('offline');

      await till.engine.drain(now: now);
      final report = await till.engine.drain(now: now.add(const Duration(minutes: 1)));

      expect(report.accepted, 3);
      expect(await till.outbox.countByStatus(OutboxStatus.synced), 3);
    });

    /// A 4xx on the envelope means the request itself was wrong. Retrying reproduces it
    /// exactly, so cycling it forever would wedge everything behind it — which is the exact
    /// failure per-entry results exist to prevent, arriving through the other door.
    test('a permanent failure rejects the batch instead of retrying it', () async {
      await enqueueSale(till.outbox, variantId: 'variant-1');

      server.nextPushFailure = PermanentSyncFailure('Unprocessable', statusCode: 422);

      final report = await till.engine.drain();

      expect(report.rejected, 3);
      expect(await till.outbox.countByStatus(OutboxStatus.rejected), 3);
      expect(await till.outbox.nextBatch(), isEmpty);
    });
  });

  group('pull', () {
    test('stores the server clock as the next cursor, not this device clock', () async {
      server.pullResponse = {
        'server_time': '2026-08-13T09:30:00+00:00',
        'enabled_modules': ['retail', 'fnb'],
        'changes': <String, dynamic>{},
      };

      await till.engine.pullCatalog();

      expect(server.lastPullCursor, isNull, reason: 'a first pull has no cursor');
      expect(await db.readState(SyncStateKeys.lastPullAt), '2026-08-13T09:30:00+00:00');

      await till.engine.pullCatalog();

      expect(server.lastPullCursor, '2026-08-13T09:30:00+00:00');
    });

    test('refreshes entitlements on every pull, not only at login', () async {
      server.pullResponse = {
        'server_time': '2026-08-13T09:30:00+00:00',
        'enabled_modules': ['retail', 'fnb'],
        'changes': <String, dynamic>{},
      };

      await till.engine.pullCatalog();
      expect(await db.readState(SyncStateKeys.enabledModules), 'retail,fnb');

      // An admin switches F&B off at noon.
      server.pullResponse = {
        'server_time': '2026-08-13T12:00:00+00:00',
        'enabled_modules': ['retail'],
        'changes': <String, dynamic>{},
      };

      await till.engine.pullCatalog();
      expect(await db.readState(SyncStateKeys.enabledModules), 'retail');
    });
  });
}
