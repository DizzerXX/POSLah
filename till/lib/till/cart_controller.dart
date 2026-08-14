import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';

import '../data/local_database.dart';
import 'cart.dart';
import 'catalog_repository.dart';

/// Holds the cart.
///
/// Riverpod rather than bloc, picked once and used consistently — the plan is explicit that
/// either is fine and that mixing them is not.
///
/// Note what this class does *not* contain: any notion of sync, network, or pending state.
/// The cashier must never wait on or think about sync, so if that ever leaks in here it will
/// have leaked all the way to the screen.
class CartController extends Notifier<CartState> {
  CartController({Uuid? uuid}) : _uuid = uuid ?? const Uuid();

  final Uuid _uuid;

  @override
  CartState build() => const CartState();

  /// The store's rate, applied to this cart and snapshotted onto the order at checkout.
  void useTaxRate(String rate) => state = state.copyWith(taxRate: rate);

  /// Adds a product, capturing name and price **now**.
  ///
  /// If a pull-sync lands a second later and reprices this product, the line keeps what the
  /// cashier quoted. That is the whole reason the snapshot is taken here rather than read at
  /// checkout.
  void add(CatalogItem item, {ProductVariant? variant, int quantity = 1}) {
    final name = variant == null || variant.name.isEmpty
        ? item.product.name
        : '${item.product.name} — ${variant.name}';

    state = state.copyWith(
      lines: [
        ...state.lines,
        CartLine(
          lineId: _uuid.v7(),
          productId: item.product.id,
          variantId: variant?.id,
          nameSnapshot: name,
          priceSnapshot: item.priceFor(variant),
          quantity: quantity,
          fulfillmentType: item.product.fulfillmentType,
        ),
      ],
    );
  }

  /// Adds a menu item, snapshotting its **recipe and modifiers** alongside the price.
  ///
  /// The recipe is captured here for the same reason the price is: a burger already in the
  /// cart must decrement the ingredients it was actually made from, even if the merchant
  /// reformulates it thirty seconds later.
  void addMenuItem(
    Product product, {
    required List<RecipeLine> recipe,
    List<Modifier> modifiers = const [],
    int quantity = 1,
  }) {
    state = state.copyWith(
      lines: [
        ...state.lines,
        CartLine(
          lineId: _uuid.v7(),
          productId: product.id,
          nameSnapshot: product.name,
          priceSnapshot: product.price,
          quantity: quantity,
          fulfillmentType: product.fulfillmentType,
          tracksStock: product.tracksStock,
          recipe: [
            for (final line in recipe)
              RecipeComponent(
                ingredientId: line.ingredientId,
                quantityMilli: line.quantityMilli,
              ),
          ],
          modifiers: [
            for (final modifier in modifiers)
              CartModifier(
                modifierId: modifier.id,
                nameSnapshot: modifier.name,
                priceDeltaSnapshot: modifier.priceDelta,
                consumesIngredientId: modifier.consumesIngredientId,
                consumesQuantityMilli: modifier.consumesQuantityMilli,
              ),
          ],
        ),
      ],
    );
  }

  void setQuantity(String lineId, int quantity) {
    if (quantity <= 0) {
      remove(lineId);

      return;
    }

    state = state.copyWith(
      lines: [
        for (final line in state.lines)
          line.lineId == lineId ? line.copyWith(quantity: quantity) : line,
      ],
    );
  }

  void increment(String lineId) {
    final line = state.lines.firstWhere((l) => l.lineId == lineId);

    setQuantity(lineId, line.quantity + 1);
  }

  void decrement(String lineId) {
    final line = state.lines.firstWhere((l) => l.lineId == lineId);

    setQuantity(lineId, line.quantity - 1);
  }

  void remove(String lineId) {
    state = state.copyWith(lines: state.lines.where((l) => l.lineId != lineId).toList());
  }

  /// Order-level only in v1. Line-level discounts are scope creep.
  void applyDiscount(int minorUnits) {
    state = state.copyWith(discountTotal: minorUnits < 0 ? 0 : minorUnits);
  }

  void attachCustomer(String? customerId) {
    state = customerId == null
        ? state.copyWith(clearCustomer: true)
        : state.copyWith(customerId: customerId);
  }

  /// After checkout. The cart was never persisted, so there is nothing to clean up
  /// anywhere else.
  void clear() => state = CartState(taxRate: state.taxRate);
}
