import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../bootstrap.dart';
import '../data/local_database.dart';
import '../modules/fnb_module.dart';
import '../modules/module_registry.dart';
import '../modules/pos_module.dart';
import '../modules/retail_module.dart';
import '../sync/outbox.dart';
import 'cart.dart';
import 'cart_controller.dart';
import 'catalog_repository.dart';
import 'checkout_service.dart';
import 'reversal_service.dart';
import 'till_session.dart';

/// Overridden at startup with the on-device database, and in tests with an in-memory one.
final localDatabaseProvider = Provider<LocalDatabase>((ref) {
  throw UnimplementedError('localDatabaseProvider must be overridden.');
});

final outboxProvider = Provider<Outbox>((ref) => Outbox(ref.watch(localDatabaseProvider)));

final recipeRepositoryProvider =
    Provider<RecipeRepository>((ref) => RecipeRepository(ref.watch(localDatabaseProvider)));

final catalogRepositoryProvider =
    Provider<CatalogRepository>((ref) => CatalogRepository(ref.watch(localDatabaseProvider)));

/// Checkout, built with the active vertical's stock strategy.
///
/// Retail expands a line to one movement; F&B expands it to one per recipe ingredient. The
/// checkout path itself knows neither — it asks the strategy and writes what comes back.
final checkoutServiceProvider = FutureProvider<CheckoutService>((ref) async {
  final registry = await ref.watch(moduleRegistryProvider.future);

  return CheckoutService(
    ref.watch(localDatabaseProvider),
    ref.watch(outboxProvider),
    stockStrategy: registry.current!.stockStrategy,
  );
});

final reversalServiceProvider = Provider<ReversalService>(
  (ref) => ReversalService(ref.watch(localDatabaseProvider), ref.watch(outboxProvider)),
);

final tillSessionProvider = FutureProvider<TillSession?>(
  (ref) => TillSession.load(ref.watch(localDatabaseProvider)),
);

/// Startup: load who this till is, and apply the store's tax rate to the cart.
///
/// The rate is applied once here rather than read per-render, because it is snapshotted onto
/// each order at checkout — a rate that changed mid-transaction must not silently reprice a
/// cart the cashier has already quoted from.
final tillBootProvider = FutureProvider<TillSession?>((ref) async {
  final db = ref.watch(localDatabaseProvider);

  var session = await TillSession.load(db);

  // First run with a token supplied: fetch the catalog, and learn our own identity and
  // receipt prefix from the same response.
  if (session == null && TillConfig.isConfigured) {
    await provisionFromServer(db);
    session = await TillSession.load(db);
  }

  final loaded = session;

  if (loaded != null) {
    final store =
        await (db.select(db.stores)..where((t) => t.id.equals(loaded.storeId))).getSingleOrNull();

    if (store != null) ref.read(cartProvider.notifier).useTaxRate(store.taxRate);
  }

  return loaded;
});

final cartProvider = NotifierProvider<CartController, CartState>(CartController.new);

final categoriesProvider =
    FutureProvider<List<Category>>((ref) => ref.watch(catalogRepositoryProvider).activeCategories());

/// The currently selected category tab, or null for "all".
///
/// A plain Notifier rather than StateProvider: the latter moved to riverpod's legacy
/// surface in 3.0, and reaching for a deprecated API on day one of a new codebase is how
/// migrations get expensive later.
class SelectedCategory extends Notifier<String?> {
  @override
  String? build() => null;

  void select(String? categoryId) => state = categoryId;
}

final selectedCategoryProvider =
    NotifierProvider<SelectedCategory, String?>(SelectedCategory.new);

class SearchTerm extends Notifier<String> {
  @override
  String build() => '';

  void set(String term) => state = term;
}

final searchTermProvider = NotifierProvider<SearchTerm, String>(SearchTerm.new);

final catalogItemsProvider = FutureProvider<List<CatalogItem>>((ref) {
  return ref.watch(catalogRepositoryProvider).items(
        categoryId: ref.watch(selectedCategoryProvider),
        search: ref.watch(searchTermProvider),
      );
});

/// How many outbox entries are still waiting.
///
/// This exists for **one small indicator in the app chrome** and a debug screen, and for
/// nothing else. Sync status must never appear on the checkout screen: the cashier does not
/// wait on it, is not asked about it, and has no action to take on it.
final pendingSyncCountProvider = FutureProvider<int>(
  (ref) => ref.watch(outboxProvider).countByStatus(OutboxStatus.pending),
);


/// The modules this till may show, built from what the server said and what the device is
/// pinned to.
///
/// Rebuilt on every pull, not only at sign-in: a till left signed in for three weeks must
/// not still be running on three-week-old entitlements. If a module disappears while it is
/// the active one, the registry simply no longer contains it and `current` falls back to a
/// remaining module — there is no "you have lost access" screen because there is no route
/// left to show one on.
final moduleRegistryProvider = FutureProvider<ModuleRegistry>((ref) async {
  final db = ref.watch(localDatabaseProvider);
  final session = await ref.watch(tillBootProvider.future);

  final enabled = (await db.readState(SyncStateKeys.enabledModules) ?? '')
      .split(',')
      .where((value) => value.isNotEmpty)
      .toList();

  final available = <String, PosModule>{
    'retail': RetailModule(ref.watch(catalogRepositoryProvider), session?.storeId ?? ''),
    'fnb': FnbModule(ref.watch(recipeRepositoryProvider), session?.storeId ?? ''),
  };

  return ModuleRegistry.build(
    enabledModules: enabled,
    available: available,
    assignedModule: session?.assignedModule,
    activeId: await db.readState(SyncStateKeys.activeModule),
  );
});

/// Switching verticals.
///
/// **An in-progress cart blocks the switch**, and is not migrated across. Migrating one
/// would be a half-built mixed cart, which is a v2 feature and worse absent-but-implied than
/// simply refused.
class ModuleSwitchBlocked implements Exception {
  const ModuleSwitchBlocked();
}

final moduleSwitcherProvider = Provider<ModuleSwitchController>(ModuleSwitchController.new);

class ModuleSwitchController {
  ModuleSwitchController(this._ref);

  final Ref _ref;

  Future<void> switchTo(String moduleId) async {
    if (_ref.read(cartProvider).isNotEmpty) {
      throw const ModuleSwitchBlocked();
    }

    final registry = await _ref.read(moduleRegistryProvider.future);

    registry.activate(moduleId);

    await _ref.read(localDatabaseProvider).writeState(SyncStateKeys.activeModule, moduleId);

    _ref.invalidate(moduleRegistryProvider);
  }
}


/// A menu item with everything the till needs to sell it: its recipe and its modifiers.
class MenuItem {
  const MenuItem({required this.product, required this.recipe, required this.modifiers});

  final Product product;
  final List<RecipeLine> recipe;
  final List<Modifier> modifiers;
}

/// The F&B menu.
///
/// Routed products, which is the same item-level branch the rest of the system uses — not a
/// query for "products belonging to the F&B module", because no such column exists.
final menuItemsProvider = FutureProvider<List<MenuItem>>((ref) async {
  final db = ref.watch(localDatabaseProvider);
  final recipes = ref.watch(recipeRepositoryProvider);

  final products = await (db.select(db.products)
        ..where((t) => t.isActive.equals(true) & t.fulfillmentType.equals('routed'))
        ..orderBy([(t) => OrderingTerm.asc(t.name)]))
      .get();

  return [
    for (final product in products)
      MenuItem(
        product: product,
        recipe: await recipes.forProduct(product.id),
        modifiers: await recipes.modifiersFor(product.id),
      ),
  ];
});

/// Cached per product and invalidated on stock change, rather than recomputed in a build
/// method — the naive version is N recipes x M ingredients on every catalog render.
final makeableHintProvider = FutureProvider.family<StockHint?, String>((ref, productId) async {
  final registry = await ref.watch(moduleRegistryProvider.future);

  return registry.current?.stockStrategy.hintFor(productId);
});
