import 'package:drift/drift.dart';
import 'package:flutter/material.dart';

import '../data/local_database.dart';
import '../till/cart.dart';
import 'pos_module.dart';

/// F&B: a menu item is sold and its *ingredients* are decremented. One product, N
/// stockables — and the product itself has no stock level at all.
///
/// That asymmetry is the whole vertical. Everything here follows from it.
class FnbModule implements PosModule {
  const FnbModule(this._recipes, this._storeId);

  final RecipeRepository _recipes;
  final String _storeId;

  @override
  String get id => 'fnb';

  @override
  String get displayName => 'F&B';

  @override
  IconData get icon => Icons.local_cafe_outlined;

  @override
  List<ModuleRoute> get routes => [
        ModuleRoute(path: '/fnb', builder: (_) => const FnbTillPlaceholder()),
      ];

  @override
  Widget buildTillScreen() => const FnbTillPlaceholder();

  @override
  CheckoutStrategy get checkoutStrategy => const FnbCheckoutStrategy();

  @override
  StockStrategy get stockStrategy => FnbStockStrategy(_recipes, _storeId);
}

class FnbCheckoutStrategy implements CheckoutStrategy {
  const FnbCheckoutStrategy();

  /// Per line, not per vertical. A café selling a bag of beans is making a retail sale, and
  /// the state machine collapses for it exactly as it would on a retail-only till.
  @override
  bool requiresFulfillment(CartState cart) =>
      cart.lines.any((line) => line.fulfillmentType == 'routed');

  @override
  String initialOrderStatus(CartState cart) =>
      requiresFulfillment(cart) ? 'sent' : 'created';
}

/// Bill-of-materials expansion.
///
/// **Expansion happens here, on the till, at sale time** — against the recipe as it stands
/// locally at that moment. If a merchant changes the burger recipe on Tuesday, a Monday sale
/// that syncs on Wednesday must still decrement Monday's ingredients. Expanding server-side
/// at sync time would silently apply Tuesday's recipe to Monday's burger.
class FnbStockStrategy implements StockStrategy {
  const FnbStockStrategy(this._recipes, this._storeId);

  final RecipeRepository _recipes;
  final String _storeId;

  /// Synchronous by interface, so the recipe has to be resident before checkout runs.
  /// [prepare] loads it; the till does that once when a line is added.
  @override
  List<PlannedMovement> expandLine(CartLine line) {
    // The retail path *through the F&B module*: a tracked product with a variant decrements
    // that variant. A café sells bags of beans as well as lattes.
    if (line.tracksStock && line.variantId != null) {
      return [
        PlannedMovement(
          stockableType: 'product_variant',
          stockableId: line.variantId!,
          deltaMilli: -line.quantity * 1000,
        ),
      ];
    }

    final movements = <PlannedMovement>[];

    for (final component in line.recipe) {
      movements.add(
        PlannedMovement(
          stockableType: 'ingredient',
          stockableId: component.ingredientId,
          deltaMilli: -component.quantityMilli * line.quantity,
        ),
      );
    }

    // Modifiers consume on top of the base recipe: an extra shot is 9g more beans, extra
    // cheese is one more slice.
    for (final modifier in line.modifiers) {
      if (modifier.consumesIngredientId == null) continue;

      movements.add(
        PlannedMovement(
          stockableType: 'ingredient',
          stockableId: modifier.consumesIngredientId!,
          deltaMilli: -(modifier.consumesQuantityMilli ?? 1000) * line.quantity,
        ),
      );
    }

    // An empty list is a legitimate outcome, not a missing-recipe warning: a corkage fee is
    // a sellable product that touches no inventory at all.
    return movements;
  }

  /// "How many burgers can I make" is a computed minimum across ingredients, not a stored
  /// number — which is why this returns a hint rather than a quantity, and null when the
  /// question has no answer.
  ///
  /// **It is advisory and it will sometimes be wrong.** Local stock is a stale projection;
  /// another till may have sold the same ingredients since the last sync. That is acceptable
  /// precisely because nothing depends on it — it greys a tile, and the cashier can still
  /// ring the item up.
  @override
  Future<StockHint?> hintFor(String productId, {String? variantId}) async {
    final lines = await _recipes.forProduct(productId);

    // Unknowable rather than zero. A product with no recipe is not "out of stock".
    if (lines.isEmpty) return null;

    int? makeable;

    for (final line in lines) {
      if (line.quantityMilli <= 0) continue;

      final onHand = await _recipes.onHandMilli(_storeId, line.ingredientId);
      if (onHand == null) return null;

      final possible = onHand ~/ line.quantityMilli;

      makeable = makeable == null ? possible : (possible < makeable ? possible : makeable);
    }

    if (makeable == null) return null;

    return StockHint(quantity: makeable, isLow: makeable <= 5);
  }
}

/// Reads recipes and ingredient stock out of the local mirror.
class RecipeRepository {
  RecipeRepository(this._db);

  final LocalDatabase _db;

  Future<List<RecipeLine>> forProduct(String productId) {
    return (_db.select(_db.recipeLines)
          ..where((t) => t.productId.equals(productId))
          ..orderBy([(t) => OrderingTerm.asc(t.ingredientId)]))
        .get();
  }

  Future<List<Modifier>> modifiersFor(String productId) {
    return (_db.select(_db.modifiers)
          ..where((t) => t.productId.equals(productId) & t.isActive.equals(true))
          ..orderBy([(t) => OrderingTerm.asc(t.name)]))
        .get();
  }

  Future<int?> onHandMilli(String storeId, String ingredientId) async {
    final level = await (_db.select(_db.stockLevels)
          ..where((t) => t.storeId.equals(storeId) & t.stockableId.equals(ingredientId)))
        .getSingleOrNull();

    return level?.quantityOnHandMilli;
  }

  Future<Ingredient?> ingredient(String id) {
    return (_db.select(_db.ingredients)..where((t) => t.id.equals(id))).getSingleOrNull();
  }
}

class FnbTillPlaceholder extends StatelessWidget {
  const FnbTillPlaceholder({super.key});

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(
        child: Padding(
          padding: EdgeInsets.all(32),
          child: Text(
            'F&B till\n\nRecipes expand on this device at sale time.\nThe menu UI arrives with the KDS work.',
            textAlign: TextAlign.center,
          ),
        ),
      ),
    );
  }
}
