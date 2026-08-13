import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/local_database.dart';
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

final catalogRepositoryProvider =
    Provider<CatalogRepository>((ref) => CatalogRepository(ref.watch(localDatabaseProvider)));

final checkoutServiceProvider = Provider<CheckoutService>(
  (ref) => CheckoutService(ref.watch(localDatabaseProvider), ref.watch(outboxProvider)),
);

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
  final session = await TillSession.load(db);

  if (session != null) {
    final store =
        await (db.select(db.stores)..where((t) => t.id.equals(session.storeId))).getSingleOrNull();

    if (store != null) ref.read(cartProvider.notifier).useTaxRate(store.taxRate);
  }

  return session;
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
