import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/main.dart';
import 'package:poslah_till/modules/fnb_module.dart';
import 'package:poslah_till/modules/module_registry.dart';
import 'package:poslah_till/modules/pos_module.dart';
import 'package:poslah_till/modules/retail_module.dart';
import 'package:poslah_till/till/cart.dart';
import 'package:poslah_till/till/catalog_repository.dart';
import 'package:poslah_till/till/providers.dart';
import 'package:poslah_till/till/till_session.dart';
import 'package:uuid/uuid.dart';

import 'support/catalog_seed.dart';
import 'support/fake_server.dart';
import 'support/till_fixture.dart';

/// The module system.
///
/// What is being defended here is that an unentitled vertical is **absent**, not hidden.
/// There is no module flag reaching screen code, so `if (module == 'fnb')` has nowhere to
/// live — and a module that was never registered has no routes, so a stray navigation finds
/// nothing rather than being told it is not allowed.
///
/// All of this is UX. The boundary that decides is the server's, tested in the API suite.
void main() {
  Map<String, PosModule> available(LocalDatabase db) => {
        'retail': RetailModule(CatalogRepository(db), storeId),
        'fnb': const FnbModule(),
      };

  group('registry construction', () {
    late LocalDatabase db;

    setUp(() => db = LocalDatabase.memory());
    tearDown(() => db.close());

    test('registers only the modules the account is entitled to', () {
      final registry = ModuleRegistry.build(
        enabledModules: ['retail'],
        available: available(db),
      );

      expect(registry.modules.map((m) => m.id), ['retail']);
      expect(registry.has('fnb'), isFalse);
      expect(registry.showsSwitcher, isFalse, reason: 'nothing to switch between');
    });

    test('an unregistered module contributes no routes at all', () {
      final registry = ModuleRegistry.build(
        enabledModules: ['retail'],
        available: available(db),
      );

      // Not "present but guarded" — absent. A deep link to /fnb finds nothing to show,
      // rather than an access-denied screen implying the screen exists and is withheld.
      expect(registry.routes.keys, ['/retail']);
      expect(registry.routes.containsKey('/fnb'), isFalse);
    });

    test('both modules register and the switcher appears', () {
      final registry = ModuleRegistry.build(
        enabledModules: ['retail', 'fnb'],
        available: available(db),
      );

      expect(registry.modules.map((m) => m.id), ['retail', 'fnb']);
      expect(registry.showsSwitcher, isTrue);
      expect(registry.routes.keys, containsAll(['/retail', '/fnb']));
    });

    /// A merchant running both verticals usually dedicates a physical till to one of them.
    /// Pinning removes the switcher from that till along with a class of cashier error.
    test('a pinned device registers only its own module even when the account has both', () {
      final registry = ModuleRegistry.build(
        enabledModules: ['retail', 'fnb'],
        available: available(db),
        assignedModule: 'retail',
      );

      expect(registry.modules.map((m) => m.id), ['retail']);
      expect(registry.showsSwitcher, isFalse);
      expect(registry.routes.containsKey('/fnb'), isFalse);
    });

    test('a pin the account is not entitled to is ignored rather than obeyed', () {
      final registry = ModuleRegistry.build(
        enabledModules: ['retail'],
        available: available(db),
        assignedModule: 'fnb',
      );

      expect(registry.modules.map((m) => m.id), ['retail']);
    });

    test('activating an unregistered module is refused', () {
      final registry = ModuleRegistry.build(
        enabledModules: ['retail'],
        available: available(db),
      );

      expect(() => registry.activate('fnb'), throwsStateError);
    });

    /// A module removed while it was the active one leaves no dangling selection.
    test('a stale active module falls back to one that still exists', () {
      final registry = ModuleRegistry.build(
        enabledModules: ['retail'],
        available: available(db),
        activeId: 'fnb',
      );

      expect(registry.current!.id, 'retail');
    });
  });

  group('strategies', () {
    late LocalDatabase db;

    setUp(() => db = LocalDatabase.memory());
    tearDown(() => db.close());

    CartLine line({String? variantId = 'v1', int quantity = 2, String type = 'direct'}) {
      return CartLine(
        lineId: const Uuid().v7(),
        productId: 'p1',
        variantId: variantId,
        nameSnapshot: 'Ethiopia Yirgacheffe',
        priceSnapshot: 4500,
        quantity: quantity,
        fulfillmentType: type,
      );
    }

    test('retail expands a line to exactly one movement', () {
      final movements = RetailStockStrategy(CatalogRepository(db), storeId).expandLine(line());

      expect(movements, hasLength(1));
      expect(movements.single.stockableType, 'product_variant');
      expect(movements.single.deltaMilli, -2000);
    });

    /// An untracked product — a corkage fee, a delivery charge — legitimately moves nothing.
    /// The empty list is an answer, not a missing configuration.
    test('a line with no variant expands to no movements', () {
      final movements =
          RetailStockStrategy(CatalogRepository(db), storeId).expandLine(line(variantId: null));

      expect(movements, isEmpty);
    });

    test('retail never asks for fulfilment; the order collapses to created', () {
      const strategy = RetailCheckoutStrategy();
      final cart = CartState(lines: [line()]);

      expect(strategy.requiresFulfillment(cart), isFalse);
      expect(strategy.initialOrderStatus(cart), 'created');
    });

    /// The branch is per line, not per vertical: a café selling a bag of beans is making a
    /// retail sale, and the state machine collapses for it exactly as it would on a till
    /// that has never heard of F&B.
    test('an fnb cart with no routed line still collapses to created', () {
      const strategy = FnbCheckoutStrategy();

      expect(strategy.initialOrderStatus(CartState(lines: [line()])), 'created');
      expect(
        strategy.initialOrderStatus(CartState(lines: [line(type: 'routed')])),
        'sent',
      );
    });
  });

  group('entitlement refresh', () {
    late LocalDatabase db;
    late FakeServer server;
    late TillFixture till;

    setUp(() async {
      db = LocalDatabase.memory();
      server = FakeServer();
      till = TillFixture(db, server);

      await const TillSession(
        deviceId: deviceId,
        accountId: accountId,
        storeId: storeId,
        numberPrefix: 'T1',
      ).persist(db);
    });

    tearDown(() => till.close());

    /// The definition-of-done case: an admin disables F&B and the till reflects it on its
    /// next pull, with no re-login. A till left signed in for three weeks must not still be
    /// running on three-week-old entitlements.
    test('a module revoked server-side disappears on the next pull', () async {
      server.pullResponse = {
        'server_time': '2026-08-14T09:00:00+00:00',
        'enabled_modules': ['retail', 'fnb'],
        'changes': <String, dynamic>{},
      };

      await till.engine.pullCatalog();
      expect(await db.readState(SyncStateKeys.enabledModules), 'retail,fnb');

      final container = ProviderContainer(
        overrides: [localDatabaseProvider.overrideWithValue(db)],
      );
      addTearDown(container.dispose);

      expect((await container.read(moduleRegistryProvider.future)).showsSwitcher, isTrue);

      // Noon: the owner turns F&B off in the backoffice. Nothing is pushed to the till.
      server.pullResponse = {
        'server_time': '2026-08-14T12:00:00+00:00',
        'enabled_modules': ['retail'],
        'changes': <String, dynamic>{},
      };

      await till.engine.pullCatalog();
      container.invalidate(moduleRegistryProvider);

      final registry = await container.read(moduleRegistryProvider.future);

      expect(registry.has('fnb'), isFalse);
      expect(registry.showsSwitcher, isFalse);
      expect(registry.routes.containsKey('/fnb'), isFalse);
      expect(registry.current!.id, 'retail');
    });
  });

  group('the switcher on screen', () {
    late LocalDatabase db;
    late SeededCatalog catalog;

    Future<void> boot(WidgetTester tester, {required String modules, String? pinnedTo}) async {
      await const TillSession(
        deviceId: deviceId,
        accountId: accountId,
        storeId: storeId,
        numberPrefix: 'T1',
      ).withPin(pinnedTo).persist(db);

      await db.writeState(SyncStateKeys.enabledModules, modules);

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

    setUp(() async {
      db = LocalDatabase.memory();
      catalog = await seedCatalog(db);
    });

    tearDown(() => db.close());

    testWidgets('a retail-only till shows no switcher', (tester) async {
      await boot(tester, modules: 'retail');

      expect(find.byKey(const Key('module-switcher')), findsNothing);
      // And the retail till is what mounted.
      expect(find.byKey(Key('product-${catalog.beansProductId}')), findsOneWidget);
    });

    testWidgets('an account with both modules gets a switcher', (tester) async {
      await boot(tester, modules: 'retail,fnb');

      expect(find.byKey(const Key('module-switcher')), findsOneWidget);
    });

    testWidgets('a pinned device shows no switcher even with both modules', (tester) async {
      await boot(tester, modules: 'retail,fnb', pinnedTo: 'retail');

      expect(find.byKey(const Key('module-switcher')), findsNothing);
    });

    testWidgets('switching mounts the other vertical', (tester) async {
      await boot(tester, modules: 'retail,fnb');

      await tester.tap(find.byKey(const Key('module-switcher')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('module-option-fnb')));
      await tester.pumpAndSettle();

      expect(find.byType(FnbTillPlaceholder), findsOneWidget);
      expect(find.byKey(Key('product-${catalog.beansProductId}')), findsNothing);
    });

    /// Complete or discard, but do not carry a cart across. Migrating one would be a
    /// half-built mixed cart, and a half-implementation is worse than its absence.
    testWidgets('an in-progress cart blocks the switch', (tester) async {
      await boot(tester, modules: 'retail,fnb');

      await tester.tap(find.byKey(Key('product-${catalog.teeProductId}')));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('module-switcher')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('module-option-fnb')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('switch-blocked')), findsOneWidget);
      // Still on retail, cart intact.
      expect(find.byKey(Key('product-${catalog.beansProductId}')), findsOneWidget);
      expect(find.byType(FnbTillPlaceholder), findsNothing);
    });
  });
}
