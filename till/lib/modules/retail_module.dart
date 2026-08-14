import 'package:flutter/material.dart';

import '../till/cart.dart';
import '../till/catalog_repository.dart';
import '../ui/till_screen.dart';
import 'pos_module.dart';

/// Retail: a SKU is sold and that same SKU's variant is decremented. One product, one
/// stockable, one movement.
class RetailModule implements PosModule {
  const RetailModule(this._catalog, this._storeId);

  final CatalogRepository _catalog;
  final String _storeId;

  @override
  String get id => 'retail';

  @override
  String get displayName => 'Retail';

  @override
  IconData get icon => Icons.storefront_outlined;

  @override
  List<ModuleRoute> get routes => [
        ModuleRoute(path: '/retail', builder: (_) => const TillScreen()),
      ];

  @override
  Widget buildTillScreen() => const TillScreen();

  @override
  CheckoutStrategy get checkoutStrategy => const RetailCheckoutStrategy();

  @override
  StockStrategy get stockStrategy => RetailStockStrategy(_catalog, _storeId);
}

class RetailCheckoutStrategy implements CheckoutStrategy {
  const RetailCheckoutStrategy();

  /// Never. A retail line is handed over at ring-up.
  @override
  bool requiresFulfillment(CartState cart) => false;

  /// The collapsed path: with nothing routed, the order is created and paid at once and
  /// never enters sent / preparing / ready.
  @override
  String initialOrderStatus(CartState cart) => 'created';
}

class RetailStockStrategy implements StockStrategy {
  const RetailStockStrategy(this._catalog, this._storeId);

  final CatalogRepository _catalog;
  final String _storeId;

  /// One movement, or none.
  ///
  /// None is a legitimate outcome, not a missing configuration: a corkage fee or a delivery
  /// charge is a sellable product that touches no inventory.
  @override
  List<PlannedMovement> expandLine(CartLine line) {
    if (line.variantId == null) return const [];

    return [
      PlannedMovement(
        stockableType: 'product_variant',
        stockableId: line.variantId!,
        deltaMilli: -line.quantity * 1000,
      ),
    ];
  }

  @override
  Future<StockHint?> hintFor(String productId, {String? variantId}) async {
    if (variantId == null) return null;

    final quantity = await _catalog.stockHint(_storeId, variantId);
    if (quantity == null) return null;

    return StockHint(quantity: quantity, isLow: quantity <= 5);
  }
}
