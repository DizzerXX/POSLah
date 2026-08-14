import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/main.dart';
import 'package:poslah_till/till/providers.dart';
import 'package:poslah_till/till/till_session.dart';

import 'support/catalog_seed.dart';
import 'support/till_fixture.dart';

/// The F&B till, driven through the real screens.
///
/// The inventory mechanics were proven in step 5; this is the path a cashier actually takes
/// to reach them. Until it existed, BOM expansion could only be triggered by a test.
void main() {
  late LocalDatabase db;
  late SeededMenu menu;

  setUp(() async {
    db = LocalDatabase.memory();
    menu = await seedMenu(db);

    await const TillSession(
      deviceId: deviceId,
      accountId: accountId,
      storeId: storeId,
      numberPrefix: 'T2',
    ).withPin('fnb').persist(db);

    await db.writeState(SyncStateKeys.enabledModules, 'retail,fnb');
  });

  tearDown(() => db.close());

  Future<void> boot(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1400, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [localDatabaseProvider.overrideWithValue(db)],
        child: const PoslahTillApp(),
      ),
    );

    await tester.pumpAndSettle();
  }

  testWidgets('a pinned F&B till boots straight into the menu', (tester) async {
    await boot(tester);

    expect(find.byKey(Key('menu-${menu.burgerId}')), findsOneWidget);
    expect(find.byKey(Key('menu-${menu.latteId}')), findsOneWidget);
    // Pinned, so no switcher — and the corkage fee is direct, so it is not on the menu.
    expect(find.byKey(const Key('module-switcher')), findsNothing);
    expect(find.byKey(Key('menu-${menu.corkageId}')), findsNothing);
  });

  testWidgets('selling a burger through the UI expands its recipe', (tester) async {
    await boot(tester);

    await tester.tap(find.byKey(Key('menu-${menu.burgerId}')));
    await tester.pumpAndSettle();

    // The burger carries a modifier, so the sheet opens rather than adding blindly.
    expect(find.byKey(Key('modifier-${menu.extraCheeseModifierId}')), findsOneWidget);
    await tester.tap(find.byKey(const Key('confirm-modifiers')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('checkout-button')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('tendered-field')));
    await tester.enterText(find.byKey(const Key('tendered-field')), '50.00');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('confirm-payment')), findsOneWidget);
    final button = tester.widget<FilledButton>(find.byKey(const Key('confirm-payment')));
    expect(button.onPressed, isNotNull, reason: 'confirm must be enabled');

    await tester.tap(find.byKey(const Key('confirm-payment')));
    await tester.pumpAndSettle();

    expect(await db.select(db.orders).get(), hasLength(1), reason: 'the sale was written');

    // Three ingredients, expanded on this device from the recipe it held.
    final movements = await db.select(db.stockMovements).get();

    expect(movements, hasLength(3));
    expect(movements.every((m) => m.stockableType == 'ingredient'), isTrue);

    final byIngredient = {for (final m in movements) m.stockableId: m.deltaMilli};
    expect(byIngredient[menu.bunId], -1000);
    expect(byIngredient[menu.pattyId], -150000);
    expect(byIngredient[menu.cheeseId], -2000);
  });

  testWidgets('choosing extra cheese adds the modifier ingredient too', (tester) async {
    await boot(tester);

    await tester.tap(find.byKey(Key('menu-${menu.burgerId}')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(Key('modifier-${menu.extraCheeseModifierId}')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('confirm-modifiers')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('checkout-button')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('tendered-field')), '50.00');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('confirm-payment')));
    await tester.pumpAndSettle();

    final movements = await db.select(db.stockMovements).get();

    // Base recipe plus the extra slice, kept as its own row.
    expect(movements, hasLength(4));
    expect(movements.where((m) => m.stockableId == menu.cheeseId), hasLength(2));

    // And the modifier priced into the line: RM 22.00 + RM 3.00.
    final order = await db.select(db.orders).getSingle();
    expect(order.subtotal, 2500);
  });

  testWidgets('the order is routed, so it becomes a kitchen ticket rather than collapsing',
      (tester) async {
    await boot(tester);

    await tester.tap(find.byKey(Key('menu-${menu.latteId}')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('confirm-modifiers')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('checkout-button')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('tendered-field')), '50.00');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('confirm-payment')));
    await tester.pumpAndSettle();

    final line = await db.select(db.orderLines).getSingle();

    expect(line.fulfillmentType, 'routed');
    // A routed line is not fulfilled at ring-up — the kitchen has it.
    expect(line.fulfillmentStatus, 'pending');
  });
}
