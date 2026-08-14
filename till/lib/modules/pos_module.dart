import 'package:flutter/widgets.dart';

import '../till/cart.dart';

/// A vertical.
///
/// The point of this interface is to make `if (module == 'fnb')` **impossible to write in
/// screen code**, by never handing screen code a module string in the first place. Every
/// difference between retail and F&B arrives through an implementation of this, and a screen
/// asks the registry for behaviour rather than asking what kind of shop it is in.
///
/// The defence is structural, not disciplinary. Each scattered conditional is individually
/// reasonable; collectively they produce two tangled implementations sharing a codebase,
/// which is worse than two clean separate apps.
abstract interface class PosModule {
  /// 'retail' | 'fnb'. Used for registration and persistence, never for branching.
  String get id;

  String get displayName;

  IconData get icon;

  /// The module's own navigation subtree. A module that is not registered has no routes,
  /// so its screens are unreachable rather than merely hidden.
  List<ModuleRoute> get routes;

  Widget buildTillScreen();

  CheckoutStrategy get checkoutStrategy;

  StockStrategy get stockStrategy;
}

/// A route belonging to a module's subtree.
class ModuleRoute {
  const ModuleRoute({required this.path, required this.builder});

  final String path;
  final WidgetBuilder builder;
}

/// How a vertical turns a cart into money.
abstract interface class CheckoutStrategy {
  /// Whether this sale needs to reach a kitchen. Retail: never. F&B: when a line is routed.
  bool requiresFulfillment(CartState cart);

  /// The fulfilment status an order starts in.
  ///
  /// One state machine, collapsing rather than branching: with no routed line the order
  /// never enters sent / preparing / ready.
  String initialOrderStatus(CartState cart);
}

/// How a vertical turns a sold line into stock movements.
///
/// Note the shape: **a list**, not a single movement. Retail returns one element and F&B
/// returns one per recipe line — and an untracked product legitimately returns none.
///
/// This interface is being designed against one real implementation, because F&B is a stub
/// until step 5. It will need revising when BOM expansion becomes real, and that revision is
/// the expected outcome rather than a failure: an interface is cheap to change while there is
/// exactly one implementor and expensive afterwards.
abstract interface class StockStrategy {
  List<PlannedMovement> expandLine(CartLine line);

  /// Advisory availability, for greying a tile. Never blocks a sale.
  Future<StockHint?> hintFor(String productId, {String? variantId});
}

/// A stock movement a sale will cause, before it is written.
class PlannedMovement {
  const PlannedMovement({
    required this.stockableType,
    required this.stockableId,
    required this.deltaMilli,
  });

  final String stockableType;
  final String stockableId;

  /// Signed milli-units, the same scale as the server's numeric(14,3).
  final int deltaMilli;
}

/// What the catalog tile shows. Null means "unknowable", which is a real answer for a
/// product that tracks nothing.
class StockHint {
  const StockHint({required this.quantity, required this.isLow});

  final int quantity;
  final bool isLow;
}
