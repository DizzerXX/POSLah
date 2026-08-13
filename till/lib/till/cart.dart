import 'package:flutter/foundation.dart';

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

  /// 'direct' for every v1 retail line. Carried rather than assumed so the F&B work in
  /// step 5 has nowhere new to put it.
  final String fulfillmentType;

  /// Derived, never stored.
  int get lineTotal => priceSnapshot * quantity;

  CartLine copyWith({int? quantity}) {
    return CartLine(
      lineId: lineId,
      productId: productId,
      variantId: variantId,
      nameSnapshot: nameSnapshot,
      priceSnapshot: priceSnapshot,
      quantity: quantity ?? this.quantity,
      fulfillmentType: fulfillmentType,
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
