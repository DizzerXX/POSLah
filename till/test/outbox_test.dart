import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/sync/outbox.dart';

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

  group('enqueue', () {
    test('stores the payload as a snapshot rather than a pointer to the row', () async {
      final payload = {'id': 'abc', 'grand_total': 4770};

      final entryId = await till.outbox.enqueue(
        entityType: 'order',
        entityId: 'abc',
        payload: payload,
      );

      // The caller mutating its own map afterwards must not change what will be sent. The
      // enqueued entry is a fact about a moment, not a live view of an object.
      payload['grand_total'] = 999999;

      final stored = await (db.select(db.syncOutbox)..where((t) => t.id.equals(entryId))).getSingle();

      expect(jsonDecode(stored.payload)['grand_total'], 4770);
      expect(stored.syncStatus, OutboxStatus.pending);
      expect(stored.attempts, 0);
    });
  });

  group('batching', () {
    test('sends an order before the ledger entry and movement that reference it', () async {
      await enqueueSale(till.outbox, variantId: 'variant-1');

      final batch = await till.outbox.nextBatch();

      expect(
        batch.map((e) => e.entityType).toList(),
        ['order', 'ledger_entry', 'stock_movement'],
      );
    });

    /// Sorting by created_at alone is very nearly right, and occasionally wrong: a checkout
    /// writes all three rows inside one transaction, so they can share a timestamp exactly.
    /// If the movement sorts ahead of its order, the server rejects it for a missing
    /// dependency and the sale needs a second round trip it should never have needed.
    test('dependency order holds even when every entry shares a timestamp', () async {
      final at = DateTime.utc(2026, 8, 13, 8);

      await till.outbox.enqueue(
        entityType: 'stock_movement',
        entityId: 'm1',
        payload: const {},
        createdAt: at,
      );
      await till.outbox.enqueue(
        entityType: 'ledger_entry',
        entityId: 'l1',
        payload: const {},
        createdAt: at,
      );
      await till.outbox.enqueue(
        entityType: 'order',
        entityId: 'o1',
        payload: const {},
        createdAt: at,
      );

      final batch = await till.outbox.nextBatch();

      expect(batch.first.entityType, 'order');
      expect(batch.last.entityType, isIn(['stock_movement', 'ledger_entry']));
    });

    test('drains oldest first and never exceeds the batch ceiling', () async {
      for (var i = 0; i < 60; i++) {
        await till.outbox.enqueue(
          entityType: 'customer',
          entityId: 'c$i',
          payload: {'id': 'c$i'},
          createdAt: DateTime.utc(2026, 8, 13, 8).add(Duration(seconds: i)),
        );
      }

      final batch = await till.outbox.nextBatch(limit: 50);

      expect(batch, hasLength(50));
      expect(batch.first.entityId, 'c0');
      expect(batch.last.entityId, 'c49');
    });

    test('entries waiting out a backoff delay are not sent early', () async {
      final now = DateTime.utc(2026, 8, 13, 9);

      await till.outbox.enqueue(entityType: 'customer', entityId: 'c1', payload: const {});

      final entries = await till.outbox.nextBatch(now: now);
      await till.outbox.markAttemptFailed(entries, 'offline', (_) => const Duration(minutes: 2), now: now);

      expect(await till.outbox.nextBatch(now: now.add(const Duration(minutes: 1))), isEmpty);
      expect(await till.outbox.nextBatch(now: now.add(const Duration(minutes: 3))), hasLength(1));
    });
  });

  group('results', () {
    test('a duplicate is treated as a success, not a failure', () async {
      final entryId = await till.outbox.enqueue(
        entityType: 'order',
        entityId: 'o1',
        payload: const {},
      );

      await till.outbox.applyResult(entryId: entryId, status: 'duplicate');

      final stored = await (db.select(db.syncOutbox)..where((t) => t.id.equals(entryId))).getSingle();

      expect(stored.syncStatus, OutboxStatus.synced);
      expect(stored.lastError, isNull);
    });

    test('a rejection records why and is never retried', () async {
      final entryId = await till.outbox.enqueue(
        entityType: 'order',
        entityId: 'o1',
        payload: const {},
      );

      await till.outbox.applyResult(
        entryId: entryId,
        status: 'rejected',
        reason: 'validation',
        detail: 'grand_total must be an integer.',
      );

      final stored = await (db.select(db.syncOutbox)..where((t) => t.id.equals(entryId))).getSingle();

      expect(stored.syncStatus, OutboxStatus.rejected);
      expect(stored.lastError, contains('grand_total'));

      // Not picked up again: a rejected entry is a bug to fix, not a wait to sit out.
      expect(await till.outbox.nextBatch(), isEmpty);
    });
  });

  group('stuck entries', () {
    /// Silent permanent failure is the worst outcome available: the merchant closes their
    /// day on figures that are short and has no reason to suspect it.
    test('repeated failures become something the till can shout about', () async {
      await till.outbox.enqueue(entityType: 'order', entityId: 'o1', payload: const {});

      var now = DateTime.utc(2026, 8, 13, 9);

      for (var attempt = 0; attempt < 10; attempt++) {
        final entries = await till.outbox.nextBatch(now: now);
        await till.outbox.markAttemptFailed(entries, 'offline', (_) => Duration.zero, now: now);
        now = now.add(const Duration(minutes: 1));
      }

      expect(await till.outbox.stuckEntries(), hasLength(1));
    });
  });
}
