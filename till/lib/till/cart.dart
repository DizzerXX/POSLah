import 'package:flutter/foundation.dart';

/// One ingredient a line consumes, captured when the line was added.
@immutable
class RecipeComponent {
  const RecipeComponent({required this.ingredientId, required this.quantityMilli});

  final String ingredientId;

  /// Per one unit of the product, in milli-units.
  final int quantityMilli;
}

/// A modifier applied to a line, snapshotted like everything else on it.
@immutable
class CartModifier {
  const CartModifier({
    required this.modifierId,
    required this.nameSnapshot,
    required this.priceDeltaSnapshot,
    this.consumesIngredientId,
    this.consumesQuantityMilli,
  });

  final String modifierId;
  final String nameSnapshot;
  final int priceDeltaSnapshot;

  /// "Extra shot" decrements beans on top of the base recipe.
  final String? consumesIngredientId;
  final int? consumesQuantityMilli;
}

/// One line in the cart.
///
/// Everything on it that came from the catalog is a **snapshot taken at add-to-cart**, not a
/// reference resolved later. If a pull-sync lands mid-transaction and changes a price, the
/// line keeps the price the cashier quoted out loud. Same reasoning as the snapshot columns
/// on the server, applied one layer up.
@immutable
class CartLine {
  const CartLine({
    required this.lineId,
    required this.productId,
    required this.nameSnapshot,
    required this.priceSnapshot,
    required this.quantity,
    this.variantId,
    this.fulfillmentType = 'direct',
    this.tracksStock = true,
    this.recipe = const [],
    this.modifiers = const [],
  });

  /// Local uuid, for keyed list updates. Distinct from the product: the same product added
  /// twice is two lines, and a cashier removing one of them means that one.
  final String lineId;
  final String productId;
  final String? variantId;
  final String nameSnapshot;

  /// Minor units, already including any variant delta.
  final int priceSnapshot;
  final int quantity;

  final String fulfillmentType;

  /// Whether this product holds stock of its own. Three real cases: a retail SKU tracks its
  /// variant; an F&B menu item tracks nothing itself and decrements its recipe; a corkage
  /// fee touches inventory not at all. One boolean cannot express the third, which is why
  /// this and [recipe] are separate.
  final bool tracksStock;

  /// The bill of materials **as it stood when this line was added**.
  ///
  /// Snapshotted for the same reason the price is: if a pull-sync lands mid-transaction and
  /// changes the burger recipe, this burger still decrements the ingredients it was actually
  /// made from. Re-reading the recipe at checkout would apply the new one to a sale already
  /// in progress.
  final List<RecipeComponent> recipe;

  final List<CartModifier> modifiers;

  /// Derived, never stored. Modifiers price into the line rather than sitting beside it.
  int get unitPrice =>
      priceSnapshot + modifiers.fold(0, (sum, m) => sum + m.priceDeltaSnapshot);

  int get lineTotal => unitPrice * quantity;

  CartLine copyWith({int? quantity}) {
    return CartLine(
      lineId: lineId,
      productId: productId,
      variantId: variantId,
      nameSnapshot: nameSnapshot,
      priceSnapshot: priceSnapshot,
      quantity: quantity ?? this.quantity,
      fulfillmentType: fulfillmentType,
      tracksStock: tracksStock,
      recipe: recipe,
      modifiers: modifiers,
    );
  }
}

/// The cart.
///
/// Ephemeral and local-only: it is not persisted, never enters the outbox, and has no row in
/// the schema. It becomes an order at the moment of checkout and not before. A held or
/// parked cart *would* need persistence, and is explicitly a v2 feature.
///
/// **Every total is computed on read.** Storing `subtotal` as mutable state would create two
/// sources of truth for one number, and the bug that follows is the display and the charge
/// disagreeing — which is the worst class of bug this screen can have.
@immutable
class CartState {
  const CartState({
    this.lines = const [],
    this.customerId,
    this.discountTotal = 0,
    this.taxRate = '0.0000',
  });

  final List<CartLine> lines;
  final String? customerId;

  /// Order-level only in v1. Line-level discounts are scope creep.
  final int discountTotal;

  /// The store's rate, as a decimal string, snapshotted onto the order at checkout.
  final String taxRate;

  bool get isEmpty => lines.isEmpty;

  bool get isNotEmpty => lines.isNotEmpty;

  int get itemCount => lines.fold(0, (sum, line) => sum + line.quantity);

  int get subtotal => lines.fold(0, (sum, line) => sum + line.lineTotal);

  /// Never below zero: a discount larger than the cart is a data-entry mistake, not a
  /// negative sale.
  int get discountedSubtotal {
    final value = subtotal - discountTotal;

    return value < 0 ? 0 : value;
  }

  /// Integer arithmetic throughout. The rate is a decimal string parsed to basis points, so
  /// no double touches money at any stage.
  int get taxTotal {
    final basisPoints = _rateInBasisPoints(taxRate);

    return (discountedSubtotal * basisPoints + 5000) ~/ 10000;
  }

  int get grandTotal => discountedSubtotal + taxTotal;

  /// "0.0600" -> 600 basis points.
  static int _rateInBasisPoints(String rate) {
    final parts = rate.split('.');
    final whole = int.tryParse(parts[0]) ?? 0;
    final fraction = parts.length > 1 ? parts[1].padRight(4, '0').substring(0, 4) : '0000';

    return whole * 10000 + (int.tryParse(fraction) ?? 0);
  }

  CartState copyWith({
    List<CartLine>? lines,
    String? customerId,
    int? discountTotal,
    String? taxRate,
    bool clearCustomer = false,
  }) {
    return CartState(
      lines: lines ?? this.lines,
      customerId: clearCustomer ? null : (customerId ?? this.customerId),
      discountTotal: discountTotal ?? this.discountTotal,
      taxRate: taxRate ?? this.taxRate,
    );
  }
}
