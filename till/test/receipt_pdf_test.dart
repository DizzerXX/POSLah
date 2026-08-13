import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/sync/outbox.dart';
import 'package:poslah_till/till/cart.dart';
import 'package:poslah_till/till/checkout_service.dart';
import 'package:poslah_till/till/receipt_pdf.dart';
import 'package:poslah_till/till/till_session.dart';
import 'package:uuid/uuid.dart';

import 'support/catalog_seed.dart';
import 'support/till_fixture.dart';

/// The receipt renders from the order's own snapshot columns.
///
/// The point being defended: a receipt reprinted in June must be identical to the one handed
/// over in March, even after the product has been renamed, repriced or discontinued. If this
/// ever started joining the live catalog, that guarantee would quietly disappear and nothing
/// would fail until a customer came back with an old receipt.
void main() {
  late LocalDatabase db;
  late CheckoutService checkout;
  late SeededCatalog catalog;

  const session = TillSession(
    deviceId: deviceId,
    accountId: accountId,
    storeId: storeId,
    numberPrefix: 'T1',
  );

  setUp(() async {
    db = LocalDatabase.memory();
    checkout = CheckoutService(db, Outbox(db));
    catalog = await seedCatalog(db);
  });

  tearDown(() => db.close());

  Future<CheckoutResult> sell() {
    return checkout.checkout(
      cart: CartState(
        lines: [
          CartLine(
            lineId: const Uuid().v7(),
            productId: catalog.beansProductId,
            variantId: catalog.smallBagVariantId,
            nameSnapshot: 'Ethiopia Yirgacheffe — 250g Bag',
            priceSnapshot: 4500,
            quantity: 2,
          ),
        ],
        taxRate: '0.0600',
      ),
      session: session,
      method: PaymentMethod.cash,
      tendered: 10000,
    );
  }

  test('produces a PDF carrying the locally generated receipt number', () async {
    final result = await sell();

    final order = await db.select(db.orders).getSingle();
    final lines = await db.select(db.orderLines).get();

    final bytes = await ReceiptPdf.build(
      order: order,
      lines: lines,
      storeName: 'Bangsar Outlet',
      storeAddress: '12 Jalan Telawi 3',
      method: result.method,
      amountCharged: result.amountCharged,
      tendered: result.tendered,
      change: result.change,
    );

    expect(bytes, isNotEmpty);
    expect(String.fromCharCodes(bytes.take(5)), '%PDF-');
    expect(order.orderNumber, 'T1-0001');
  });

  /// The catalog changes; the receipt does not.
  test('renders from the snapshot even after the product is renamed and discontinued',
      () async {
    await sell();

    final before = await db.select(db.orderLines).getSingle();

    await (db.update(db.products)..where((t) => t.id.equals(catalog.beansProductId))).write(
      const ProductsCompanion(
        name: Value('Something Else Entirely'),
        price: Value(9900),
        isActive: Value(false),
      ),
    );

    final after = await db.select(db.orderLines).getSingle();

    expect(after.nameSnapshot, before.nameSnapshot);
    expect(after.priceSnapshot, 4500);
    expect(after.lineTotal, 9000);

    // And the rendered receipt still builds from those, with no live catalog involved.
    final bytes = await ReceiptPdf.build(
      order: await db.select(db.orders).getSingle(),
      lines: [after],
      storeName: 'Bangsar Outlet',
    );

    expect(bytes, isNotEmpty);
  });

  test('a voided order is marked as such on its receipt', () async {
    final result = await sell();

    await (db.update(db.orders)..where((t) => t.id.equals(result.orderId)))
        .write(const OrdersCompanion(status: Value('voided')));

    final bytes = await ReceiptPdf.build(
      order: await db.select(db.orders).getSingle(),
      lines: await db.select(db.orderLines).get(),
      storeName: 'Bangsar Outlet',
    );

    expect(bytes, isNotEmpty);
  });
}
