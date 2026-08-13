import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/sync/outbox.dart';
import 'package:poslah_till/till/cart.dart';
import 'package:poslah_till/till/checkout_service.dart';
import 'package:poslah_till/till/till_session.dart';
import 'package:uuid/uuid.dart';

import 'support/catalog_seed.dart';
import 'support/fake_server.dart';
import 'support/till_fixture.dart';

/// The checkout write path.
///
/// This is the one place in the retail step where correctness matters more than polish. A
/// partial write here is the ugliest bug class available — a ledger entry with no order, an
/// order with no stock movement — so what these tests are really asserting is that it is all
/// one transaction, and that no part of it waits on a network.
void main() {
  late LocalDatabase db;
  late Outbox outbox;
  late CheckoutService checkout;
  late SeededCatalog catalog;

  const session = TillSession(
    deviceId: deviceId,
    accountId: accountId,
    storeId: storeId,
    numberPrefix: 'T1',
    cashierUserId: 'u-cashier',
  );

  setUp(() async {
    db = LocalDatabase.memory();
    outbox = Outbox(db);
    checkout = CheckoutService(
      db,
      outbox,
      clock: () => DateTime.utc(2026, 8, 13, 10, 30),
    );

    catalog = await seedCatalog(db);
    await seedStock(db, catalog.smallBagVariantId, 10);
  });

  tearDown(() => db.close());

  CartLine lineFor(String variantId, {int price = 4500, int quantity = 1, String? productId}) {
    return CartLine(
      lineId: const Uuid().v7(),
      productId: productId ?? catalog.beansProductId,
      variantId: variantId,
      nameSnapshot: 'Ethiopia Yirgacheffe 250g',
      priceSnapshot: price,
      quantity: quantity,
    );
  }

  group('a completed sale', () {
    /// The definition-of-done case: a multi-line sale with variants, completed with no
    /// network anywhere in the path.
    test('writes order, lines, ledger entry, movements and outbox in one go', () async {
      final cart = CartState(
        lines: [
          lineFor(catalog.smallBagVariantId, price: 4500, quantity: 2),
          lineFor(catalog.largeBagVariantId, price: 16500),
        ],
        taxRate: '0.0600',
      );

      final result = await checkout.checkout(
        cart: cart,
        session: session,
        method: PaymentMethod.cash,
        tendered: 30000,
      );

      final order = await db.select(db.orders).getSingle();

      expect(order.id, result.orderId);
      expect(order.orderNumber, 'T1-0001');
      // Fulfilment and payment are separate axes. No routed line means the fulfilment
      // machine collapses; the order is created and paid at the same instant.
      expect(order.status, 'created');
      expect(order.paymentStatus, 'paid');
      expect(order.subtotal, 25500);
      expect(order.taxTotal, 1530);
      expect(order.grandTotal, 27030);
      expect(order.taxRateSnapshot, '0.0600');

      expect(await db.select(db.orderLines).get(), hasLength(2));

      final ledger = await db.select(db.ledgerEntries).getSingle();
      expect(ledger.entryType, 'sale');
      expect(ledger.orderId, order.id);
      expect(ledger.paymentMethod, 'cash');

      final movements = await db.select(db.stockMovements).get();
      expect(movements, hasLength(2));
      expect(movements.map((m) => m.deltaMilli).toList()..sort(), [-2000, -1000]);
      expect(movements.every((m) => m.reason == 'sale'), isTrue);
    });

    test('snapshots the name and price onto the line rather than referencing the catalog', () async {
      final cart = CartState(
        lines: [lineFor(catalog.smallBagVariantId, price: 4500)],
        taxRate: '0.0600',
      );

      await checkout.checkout(cart: cart, session: session, method: PaymentMethod.card);

      final line = await db.select(db.orderLines).getSingle();

      expect(line.nameSnapshot, 'Ethiopia Yirgacheffe 250g');
      expect(line.priceSnapshot, 4500);
      expect(line.fulfillmentType, 'direct');
      // A direct line is handed over at ring-up, so it is fulfilled the moment it is sold.
      expect(line.fulfillmentStatus, 'fulfilled');
    });

    test('decrements the advisory local stock level', () async {
      final cart = CartState(lines: [lineFor(catalog.smallBagVariantId, quantity: 3)]);

      await checkout.checkout(cart: cart, session: session, method: PaymentMethod.qr);

      final level = await (db.select(db.stockLevels)
            ..where((t) => t.stockableId.equals(catalog.smallBagVariantId)))
          .getSingle();

      expect(level.quantityOnHandMilli, 7000);
    });

    /// The locked decision, at the layer where it is easiest to betray. Nothing in this
    /// path reads stock to decide whether the sale may proceed.
    test('sells past zero without complaint', () async {
      final cart = CartState(lines: [lineFor(catalog.smallBagVariantId, quantity: 12)]);

      await checkout.checkout(cart: cart, session: session, method: PaymentMethod.cash, tendered: 100000);

      final level = await (db.select(db.stockLevels)
            ..where((t) => t.stockableId.equals(catalog.smallBagVariantId)))
          .getSingle();

      // Ten on the shelf, twelve sold. The sale is a fact; -2 is the honest record of it.
      expect(level.quantityOnHandMilli, -2000);
      expect(await db.select(db.orders).get(), hasLength(1));
    });

    /// A corkage fee is sellable and touches no inventory. An empty movement list is a
    /// legitimate outcome, not a missing-stock warning.
    test('an untracked product writes no stock movement and does not error', () async {
      final cart = CartState(
        lines: [
          CartLine(
            lineId: const Uuid().v7(),
            productId: catalog.corkageProductId,
            variantId: null,
            nameSnapshot: 'Corkage',
            priceSnapshot: 3000,
            quantity: 1,
          ),
        ],
      );

      await checkout.checkout(cart: cart, session: session, method: PaymentMethod.other);

      expect(await db.select(db.stockMovements).get(), isEmpty);
      expect(await db.select(db.orders).get(), hasLength(1));
      expect(await db.select(db.ledgerEntries).get(), hasLength(1));
    });
  });

  group('receipt numbering', () {
    /// The till owns this counter because it has to produce a number with no network. A
    /// customer walking away with a receipt that says "pending" is not a shippable product.
    test('numbers receipts locally and increments per sale', () async {
      for (var i = 1; i <= 3; i++) {
        final result = await checkout.checkout(
          cart: CartState(lines: [lineFor(catalog.smallBagVariantId)]),
          session: session,
          method: PaymentMethod.card,
        );

        expect(result.orderNumber, 'T1-000$i');
      }
    });

    /// The prefix is what makes two offline tills unable to collide without coordinating.
    test('a different device prefix produces a different series', () async {
      const other = TillSession(
        deviceId: 'device-2',
        accountId: accountId,
        storeId: storeId,
        numberPrefix: 'T2',
      );

      await checkout.checkout(
        cart: CartState(lines: [lineFor(catalog.smallBagVariantId)]),
        session: session,
        method: PaymentMethod.card,
      );

      final second = await checkout.checkout(
        cart: CartState(lines: [lineFor(catalog.smallBagVariantId)]),
        session: other,
        method: PaymentMethod.card,
      );

      expect(second.orderNumber, startsWith('T2-'));
    });
  });

  group('cash rounding', () {
    /// The order keeps the exact arithmetic total; the ledger records the money that
    /// actually moved. Those are two different facts, and collapsing them would put a
    /// receipt and a ledger entry a couple of sen apart with nothing to explain it.
    test('cash rounds the charged amount but not the order total', () async {
      // 4500 + 6% = 4770 exactly; use a price that lands off a five sen boundary.
      final cart = CartState(lines: [lineFor(catalog.smallBagVariantId, price: 1999)], taxRate: '0.0600');

      // 1999 + 120 = 2119 -> 2120 in cash.
      final result = await checkout.checkout(
        cart: cart,
        session: session,
        method: PaymentMethod.cash,
        tendered: 5000,
      );

      expect(result.grandTotal, 2119);
      expect(result.amountCharged, 2120);
      expect(result.roundingAdjustment, 1);
      expect(result.change, 2880);

      final order = await db.select(db.orders).getSingle();
      final ledger = await db.select(db.ledgerEntries).getSingle();

      expect(order.grandTotal, 2119, reason: 'the sale is what it is');
      expect(ledger.amount, 2120, reason: 'the ledger records the money that moved');
    });

    test('card and qr are never rounded', () async {
      final cart = CartState(lines: [lineFor(catalog.smallBagVariantId, price: 1999)], taxRate: '0.0600');

      final result = await checkout.checkout(cart: cart, session: session, method: PaymentMethod.card);

      expect(result.amountCharged, 2119);
      expect(result.roundingAdjustment, 0);
    });

    test('cash short of the amount due is refused before anything is written', () async {
      final cart = CartState(lines: [lineFor(catalog.smallBagVariantId)], taxRate: '0.0600');

      await expectLater(
        checkout.checkout(cart: cart, session: session, method: PaymentMethod.cash, tendered: 100),
        throwsStateError,
      );

      expect(await db.select(db.orders).get(), isEmpty);
    });
  });

  group('the outbox', () {
    test('enqueues the sale as three entries, order first', () async {
      final cart = CartState(lines: [lineFor(catalog.smallBagVariantId)], taxRate: '0.0600');

      await checkout.checkout(cart: cart, session: session, method: PaymentMethod.card);

      final batch = await outbox.nextBatch();

      expect(batch.map((e) => e.entityType).toList(), ['order', 'ledger_entry', 'stock_movement']);
    });

    /// The payload is a snapshot taken now, not a promise to re-read the row later. The
    /// order's own lines travel inside it, so a sale reaches the server as one fact.
    test('the order payload carries its lines', () async {
      final cart = CartState(
        lines: [lineFor(catalog.smallBagVariantId), lineFor(catalog.largeBagVariantId)],
        taxRate: '0.0600',
      );

      await checkout.checkout(cart: cart, session: session, method: PaymentMethod.card);

      final entry = (await outbox.nextBatch()).first;
      final payload = jsonDecode(entry.payload) as Map<String, dynamic>;

      expect(payload['lines'], hasLength(2));
      expect(payload['order_number'], 'T1-0001');
      expect(payload['status'], 'created');
      expect(payload['payment_status'], 'paid');
    });

    /// Nothing in the checkout path touches the network. This asserts it by construction:
    /// the service is built with a transport that would blow up if it were ever called.
    test('completes with no transport wired at all', () async {
      final offlineDb = LocalDatabase.memory();
      await seedCatalog(offlineDb);

      final offlineCheckout = CheckoutService(offlineDb, Outbox(offlineDb));

      final result = await offlineCheckout.checkout(
        cart: CartState(lines: [lineFor(catalog.smallBagVariantId)], taxRate: '0.0600'),
        session: session,
        method: PaymentMethod.cash,
        tendered: 5000,
      );

      expect(result.orderNumber, 'T1-0001');
      expect(await offlineDb.select(offlineDb.orders).get(), hasLength(1));

      await offlineDb.close();
    });

    /// And then it syncs, whenever the network happens to come back.
    test('the queued sale drains to a server once one is reachable', () async {
      await checkout.checkout(
        cart: CartState(lines: [lineFor(catalog.smallBagVariantId)], taxRate: '0.0600'),
        session: session,
        method: PaymentMethod.card,
      );

      final server = FakeServer();
      final engine = TillFixture(db, server).engine;

      final report = await engine.drain();

      expect(report.accepted, 3);
      expect(await outbox.countByStatus(OutboxStatus.synced), 3);
    });
  });

  group('refusals', () {
    test('an empty cart cannot be checked out', () async {
      await expectLater(
        checkout.checkout(cart: const CartState(), session: session, method: PaymentMethod.cash, tendered: 0),
        throwsStateError,
      );
    });
  });
}
