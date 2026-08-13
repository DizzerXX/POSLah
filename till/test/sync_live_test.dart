import 'dart:io';

import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/sync/outbox.dart';
import 'package:poslah_till/sync/sync_engine.dart';
import 'package:poslah_till/sync/sync_transport.dart';

import 'support/till_fixture.dart';

/// End to end against the real Laravel API.
///
/// Every other test in this suite runs against `FakeServer`, which I wrote — so together
/// they prove the till agrees with *my description* of the contract, and nothing more. This
/// one proves the two halves actually agree: real HTTP, real Sanctum token, real PostgreSQL,
/// real reconciliation.
///
/// It is skipped unless pointed at a running server, because a test suite that needs a
/// database and a web server to pass is a test suite people stop running:
///
/// ```
/// cd api && php artisan serve &
/// php artisan poslah:device-token "Front Counter"
///
/// cd till
/// POSLAH_API_URL=http://127.0.0.1:8000 POSLAH_DEVICE_TOKEN=<token> flutter test test/sync_live_test.dart
/// ```
void main() {
  final baseUrl = Platform.environment['POSLAH_API_URL'];
  final token = Platform.environment['POSLAH_DEVICE_TOKEN'];

  final configured = baseUrl != null && token != null;

  group(
    'live sync',
    skip: configured ? null : 'Set POSLAH_API_URL and POSLAH_DEVICE_TOKEN to run.',
    () {
      late LocalDatabase db;
      late Outbox outbox;
      late SyncEngine engine;
      late HttpSyncTransport transport;

      setUp(() {
        db = LocalDatabase.memory();
        outbox = Outbox(db);
        transport = HttpSyncTransport(baseUrl: baseUrl!, token: token!);
        engine = SyncEngine(
          db: db,
          outbox: outbox,
          transport: transport,
          // The server takes the device from the token; this only has to match it.
          deviceId: Platform.environment['POSLAH_DEVICE_ID'] ?? deviceId,
        );
      });

      tearDown(() => db.close());

      test('pulls a real catalog and stores the server cursor', () async {
        final report = await engine.pullCatalog();

        expect(report['products'], greaterThan(0), reason: 'the seeded account has a catalog');
        expect(report['product_variants'], greaterThan(0));
        expect(await db.readState(SyncStateKeys.lastPullAt), isNotNull);

        // Stock is deliberately absent from the pull: the till's figure is advisory, and
        // shipping authoritative-looking numbers would invite something to depend on them.
        expect(await db.select(db.stockLevels).get(), isEmpty);
      });

      test('a second pull with the stored cursor returns an empty delta', () async {
        await engine.pullCatalog();
        final second = await engine.pullCatalog();

        expect(second.total, 0, reason: 'nothing changed server-side between the two');
      });

      /// The whole loop: sell offline, reconnect, push, then resend the same sale and watch
      /// the server recognise its own ids.
      test('pushes a sale and treats the resend as a duplicate', () async {
        await engine.pullCatalog();

        final store = await db.select(db.stores).getSingle();
        final variant = await (db.select(db.productVariants)
              ..where((t) => t.isActive.equals(true))
              ..limit(1))
            .getSingle();

        await enqueueSale(
          outbox,
          variantId: variant.id,
          account: store.accountId,
          store: store.id,
          orderNumber: 'LIVE-${DateTime.now().millisecondsSinceEpoch}',
          occurredAt: DateTime.now().toUtc(),
        );

        final first = await engine.drain();

        expect(first.accepted, 3, reason: 'order, ledger entry and stock movement');
        expect(first.rejected, 0);
        expect(await outbox.countByStatus(OutboxStatus.synced), 3);

        // Re-enqueue the very same entries and send them again — the shape of a till that
        // crashed before recording the response.
        await (db.update(db.syncOutbox)).write(
          const SyncOutboxCompanion(syncStatus: Value(OutboxStatus.pending)),
        );

        final second = await engine.drain();

        expect(second.duplicate, 3, reason: 'client-generated ids make the resend free');
        expect(second.accepted, 0);
      });
    },
  );
}
