import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/sync/outbox.dart';
import 'package:poslah_till/modules/retail_module.dart';
import 'package:poslah_till/till/cart.dart';
import 'package:poslah_till/till/catalog_repository.dart';
import 'package:poslah_till/till/checkout_service.dart';
import 'package:poslah_till/till/reversal_service.dart';
import 'package:poslah_till/till/till_session.dart';
import 'package:uuid/uuid.dart';

import 'support/catalog_seed.dart';
import 'support/till_fixture.dart';

/// Voids, and the manager override that gates them.
///
/// The PIN check here is a **UX gate**. A rebuilt app skips it entirely, so nothing may
/// depend on it having run — the check that decides is on the server, which re-reads the
/// authorising user's role when the reversal syncs. What these tests cover is that the till
/// behaves correctly for an honest cashier, and that the reversal it produces is the shape
/// the server will accept.
void main() {
  late LocalDatabase db;
  late Outbox outbox;
  late CheckoutService checkout;
  late ReversalService reversals;
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
    checkout = CheckoutService(db, outbox,
        stockStrategy: RetailStockStrategy(CatalogRepository(db), storeId),
        clock: () => DateTime.utc(2026, 8, 13, 10));
    reversals = ReversalService(db, outbox, clock: () => DateTime.utc(2026, 8, 13, 11));

    catalog = await seedCatalog(db);
    await seedStock(db, catalog.smallBagVariantId, 10);
    await seedStaff(db);
  });

  tearDown(() => db.close());

  Future<CheckoutResult> sell({int quantity = 2}) {
    return checkout.checkout(
      cart: CartState(
        lines: [
          CartLine(
            lineId: const Uuid().v7(),
            productId: catalog.beansProductId,
            variantId: catalog.smallBagVariantId,
            nameSnapshot: 'Ethiopia Yirgacheffe 250g',
            priceSnapshot: 4500,
            quantity: quantity,
          ),
        ],
        taxRate: '0.0600',
      ),
      session: session,
      method: PaymentMethod.card,
    );
  }

  group('manager PIN', () {
    /// The hash is a real Laravel `$2y$` bcrypt hash, not one this codebase produced. If the
    /// two ever disagree about format, an offline void stops working in a shop and nowhere
    /// else, so it is worth pinning here.
    test('a correct manager PIN is granted', () async {
      final check = await reversals.verifyManagerPin('u-manager', '1234');

      expect(check.isGranted, isTrue);
      expect(check.user!.name, 'Daniel Tan');
    });

    test('a wrong PIN is refused', () async {
      final check = await reversals.verifyManagerPin('u-manager', '9999');

      expect(check.isGranted, isFalse);
      expect(check.refusal, PinRefusal.wrongPin);
    });

    /// A cashier has no PIN hash locally at all — the server never sends one — so this is
    /// refused for role before there is anything to check against.
    test('a cashier cannot authorise even with the right PIN', () async {
      final check = await reversals.verifyManagerPin('u-cashier', '1234');

      expect(check.isGranted, isFalse);
      expect(check.refusal, PinRefusal.notAuthorised);
    });

    test('a deactivated manager is refused', () async {
      final check = await reversals.verifyManagerPin('u-manager-inactive', '1234');

      expect(check.isGranted, isFalse);
      expect(check.refusal, PinRefusal.inactive);
    });

    test('an unknown user is refused', () async {
      expect((await reversals.verifyManagerPin('nobody', '1234')).refusal, PinRefusal.unknownUser);
    });

    test('only managers and owners are offered in the override prompt', () async {
      final offered = await reversals.authorisers();

      expect(offered.map((u) => u.id), ['u-manager']);
    });
  });

  group('voiding an order', () {
    /// The definition-of-done case: the reversing entry exists and the original is
    /// untouched. The ledger tables refuse UPDATE at the database, so a correction *has* to
    /// be a new fact pointing at the old one.
    test('writes a reversing entry and leaves the sale byte identical', () async {
      final sale = await sell();

      final before = await (db.select(db.ledgerEntries)..where((t) => t.entryType.equals('sale')))
          .getSingle();

      await reversals.voidOrder(
        orderId: sale.orderId,
        session: session,
        authorizedByUserId: 'u-manager',
      );

      final after = await (db.select(db.ledgerEntries)..where((t) => t.id.equals(before.id)))
          .getSingle();

      expect(after, before, reason: 'the original sale entry is unchanged in every field');

      final reversal = await (db.select(db.ledgerEntries)..where((t) => t.entryType.equals('void')))
          .getSingle();

      expect(reversal.amount, -before.amount);
      expect(reversal.reversesEntryId, before.id);
      expect(reversal.authorizedByUserId, 'u-manager');
      expect(reversal.paymentMethod, before.paymentMethod);
    });

    test('the net position of the order is zero', () async {
      final sale = await sell();

      await reversals.voidOrder(
        orderId: sale.orderId,
        session: session,
        authorizedByUserId: 'u-manager',
      );

      final entries = await (db.select(db.ledgerEntries)..where((t) => t.orderId.equals(sale.orderId)))
          .get();

      // A plain SUM, with no case analysis and no edit of history to arrive at it.
      expect(entries.fold<int>(0, (sum, e) => sum + e.amount), 0);
      expect(entries, hasLength(2));
    });

    test('returns the goods to stock as positive movements', () async {
      final sale = await sell(quantity: 2);

      expect(
        (await (db.select(db.stockLevels)
                  ..where((t) => t.stockableId.equals(catalog.smallBagVariantId)))
                .getSingle())
            .quantityOnHandMilli,
        8000,
      );

      await reversals.voidOrder(
        orderId: sale.orderId,
        session: session,
        authorizedByUserId: 'u-manager',
      );

      final movements = await db.select(db.stockMovements).get();

      expect(movements, hasLength(2), reason: 'the sale and its reversal, both kept');
      expect(movements.map((m) => m.deltaMilli).toList()..sort(), [-2000, 2000]);
      expect(movements.where((m) => m.reason == 'refund').single.deltaMilli, 2000);

      expect(
        (await (db.select(db.stockLevels)
                  ..where((t) => t.stockableId.equals(catalog.smallBagVariantId)))
                .getSingle())
            .quantityOnHandMilli,
        10000,
      );
    });

    test('marks the local order voided', () async {
      final sale = await sell();

      await reversals.voidOrder(
        orderId: sale.orderId,
        session: session,
        authorizedByUserId: 'u-manager',
      );

      final order = await (db.select(db.orders)..where((t) => t.id.equals(sale.orderId))).getSingle();

      expect(order.status, 'voided');
      expect(order.paymentStatus, 'refunded');
    });

    /// The order's status change is *not* enqueued. Every sync write is an insert, and the
    /// server derives the voided status from the void entry arriving — so the outbox carries
    /// facts, and the projection follows from them on the other side.
    test('enqueues the reversing entry and movements, and no order update', () async {
      final sale = await sell();

      final beforeIds = (await outbox.nextBatch()).map((e) => e.id).toSet();

      await reversals.voidOrder(
        orderId: sale.orderId,
        session: session,
        authorizedByUserId: 'u-manager',
      );

      final added = (await outbox.nextBatch()).where((e) => !beforeIds.contains(e.id)).toList();

      expect(added.map((e) => e.entityType).toSet(), {'ledger_entry', 'stock_movement'});
      expect(
        added.where((e) => e.entityType == 'order'),
        isEmpty,
        reason: 'an order status change never travels as an update',
      );

      final voidEntry = added.firstWhere((e) => e.entityType == 'ledger_entry');
      final payload = jsonDecode(voidEntry.payload) as Map<String, dynamic>;

      expect(payload['entry_type'], 'void');
      expect(payload['authorized_by_user_id'], 'u-manager');
      expect(payload['amount'], isNegative);
    });

    test('an order cannot be voided twice', () async {
      final sale = await sell();

      await reversals.voidOrder(
        orderId: sale.orderId,
        session: session,
        authorizedByUserId: 'u-manager',
      );

      await expectLater(
        reversals.voidOrder(
          orderId: sale.orderId,
          session: session,
          authorizedByUserId: 'u-manager',
        ),
        throwsStateError,
      );
    });

    /// An untracked product decremented nothing, so its void returns nothing. The reversal
    /// is derived from the movements the sale actually wrote rather than recomputed from the
    /// lines, which is what keeps the two in step.
    test('voiding an untracked sale returns no stock', () async {
      final result = await checkout.checkout(
        cart: CartState(
          lines: [
            CartLine(
              lineId: const Uuid().v7(),
              productId: catalog.corkageProductId,
              nameSnapshot: 'Corkage',
              priceSnapshot: 3000,
              quantity: 1,
            ),
          ],
        ),
        session: session,
        method: PaymentMethod.card,
      );

      await reversals.voidOrder(
        orderId: result.orderId,
        session: session,
        authorizedByUserId: 'u-manager',
      );

      expect(await db.select(db.stockMovements).get(), isEmpty);
      expect(await (db.select(db.ledgerEntries)..where((t) => t.entryType.equals('void'))).get(),
          hasLength(1));
    });
  });
}
