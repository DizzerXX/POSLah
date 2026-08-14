import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/till/providers.dart';
import 'package:poslah_till/till/till_session.dart';
import 'package:poslah_till/main.dart';

import 'support/catalog_seed.dart';
import 'support/till_fixture.dart';

/// The retail loop, driven through the actual screens.
///
/// The definition of done for this step opens with "a cashier rings up a multi-line sale
/// with variants and completes checkout offline". The service tests prove the write path;
/// this proves a person can reach it.
///
/// Nothing here wires a transport. There is no server, no token and no network stack in this
/// test at all — which is the point: if any part of the checkout path had come to depend on
/// one, these tests would fail rather than quietly working on a good connection.
void main() {
  late LocalDatabase db;
  late SeededCatalog catalog;

  setUp(() async {
    db = LocalDatabase.memory();
    catalog = await seedCatalog(db);
    await seedStock(db, catalog.smallBagVariantId, 10);
    await seedStaff(db);

    await const TillSession(
      deviceId: deviceId,
      accountId: accountId,
      storeId: storeId,
      numberPrefix: 'T1',
      cashierUserId: 'u-cashier',
    ).persist(db);

    // A provisioned till has pulled, so it knows what the account is entitled to. Without
    // this the registry is empty and no module's till screen mounts at all.
    await db.writeState(SyncStateKeys.enabledModules, 'retail');
  });

  tearDown(() => db.close());

  Future<void> pumpTill(WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [localDatabaseProvider.overrideWithValue(db)],
        // The real app root, so startup runs exactly as it does on a device.
        child: const PoslahTillApp(),
      ),
    );

    await tester.pumpAndSettle();
  }

  testWidgets('rings up a multi-line sale with a variant and completes it offline',
      (tester) async {
    tester.view.physicalSize = const Size(1400, 1800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await pumpTill(tester);

    // A product with two variants opens the picker rather than guessing which one.
    await tester.tap(find.byKey(Key('product-${catalog.beansProductId}')));
    await tester.pumpAndSettle();

    expect(find.byKey(Key('variant-${catalog.smallBagVariantId}')), findsOneWidget);
    await tester.tap(find.byKey(Key('variant-${catalog.smallBagVariantId}')));
    await tester.pumpAndSettle();

    // A second line, a different variant of the same product.
    await tester.tap(find.byKey(Key('product-${catalog.beansProductId}')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(Key('variant-${catalog.largeBagVariantId}')));
    await tester.pumpAndSettle();

    // A third line, a different product entirely.
    await tester.tap(find.byKey(Key('product-${catalog.teeProductId}')));
    await tester.pumpAndSettle();

    expect(find.textContaining('Ethiopia Yirgacheffe — 250g Bag'), findsWidgets);

    // 4500 + 16500 + 5900 = 26900.
    expect(find.byKey(const Key('total-Subtotal')), findsOneWidget);
    expect(find.text('RM 269.00'), findsWidgets);

    await tester.tap(find.byKey(const Key('checkout-button')));
    await tester.pumpAndSettle();

    // 26900 + 6% = 28514, which rounds to 28515 in cash.
    expect(find.byKey(const Key('amount-due')), findsOneWidget);
    expect(find.text('RM 285.15'), findsWidgets);
    expect(find.byKey(const Key('rounding-note')), findsOneWidget);

    await tester.enterText(find.byKey(const Key('tendered-field')), '300.00');
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('change-due')), findsOneWidget);
    expect(find.text('RM 14.85'), findsWidgets);

    await tester.tap(find.byKey(const Key('confirm-payment')));
    await tester.pumpAndSettle();

    // The receipt, with a number generated locally before any network call.
    expect(find.byKey(const Key('receipt-number')), findsOneWidget);
    expect(find.text('Receipt T1-0001'), findsOneWidget);

    final order = await db.select(db.orders).getSingle();
    expect(order.orderNumber, 'T1-0001');
    expect(order.grandTotal, 28514);
    expect(await db.select(db.orderLines).get(), hasLength(3));

    // Three lines, but only the two stock-tracking variants moved stock.
    expect(await db.select(db.stockMovements).get(), hasLength(3));

    // And the whole sale is queued for whenever the network returns.
    expect(await db.select(db.syncOutbox).get(), hasLength(5));
  });

  testWidgets('a barcode scan adds the exact variant it names', (tester) async {
    tester.view.physicalSize = const Size(1400, 1800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await pumpTill(tester);

    await tester.tap(find.byKey(const Key('scan-button')));
    await tester.pumpAndSettle();

    // The 1kg bag's barcode, not the 250g one and not the parent product's.
    await tester.enterText(find.byKey(const Key('barcode-field')), '9557001000028');
    await tester.tap(find.byKey(const Key('barcode-confirm')));
    await tester.pumpAndSettle();

    expect(find.textContaining('1kg Bag'), findsWidgets);
    // RM 45.00 base plus the RM 120.00 delta.
    expect(find.text('RM 165.00'), findsWidgets);
  });

  testWidgets('quantity steppers change the total without restating it anywhere',
      (tester) async {
    tester.view.physicalSize = const Size(1400, 1800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await pumpTill(tester);

    await tester.tap(find.byKey(Key('product-${catalog.teeProductId}')));
    await tester.pumpAndSettle();

    expect(find.text('RM 59.00'), findsWidgets);

    await tester.tap(find.byIcon(Icons.add_circle_outline).first);
    await tester.pumpAndSettle();

    // Every total is derived on read, so one tap moves all of them together.
    expect(find.text('RM 118.00'), findsWidgets);
  });

  testWidgets('the sync indicator is the only place sync is visible', (tester) async {
    tester.view.physicalSize = const Size(1400, 1800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await pumpTill(tester);

    expect(find.byKey(const Key('sync-indicator')), findsNothing, reason: 'nothing pending yet');

    await tester.tap(find.byKey(Key('product-${catalog.teeProductId}')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('checkout-button')));
    await tester.pumpAndSettle();

    // No spinner, no "syncing…", no retry button anywhere on the checkout screen.
    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(find.textContaining('sync', findRichText: true), findsNothing);
  });
}
