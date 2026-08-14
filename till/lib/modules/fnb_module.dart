import 'package:flutter/material.dart';

import '../till/cart.dart';
import 'pos_module.dart';

/// F&B — **a registered shell, not a working vertical**.
///
/// Step 4 exists to prove the registry, the routing and the entitlement boundary. The till
/// behaviour behind this module, and the BOM expansion its stock strategy is supposed to do,
/// arrive in step 5.
///
/// The interface is therefore being designed against an implementation that does not exist
/// yet, and `StockStrategy` will almost certainly need adjusting once expansion is real —
/// `expandLine` already returns a list precisely because a burger is one product and several
/// stockables. That revision is planned, not indecision: an interface is cheap to change
/// while there is one real implementor and expensive afterwards.
class FnbModule implements PosModule {
  const FnbModule();

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
  StockStrategy get stockStrategy => const FnbStockStrategy();
}

class FnbCheckoutStrategy implements CheckoutStrategy {
  const FnbCheckoutStrategy();

  /// A kitchen is involved when any line is routed — which is per line, not per vertical.
  /// A café selling a bag of beans is making a retail sale.
  @override
  bool requiresFulfillment(CartState cart) =>
      cart.lines.any((line) => line.fulfillmentType == 'routed');

  @override
  String initialOrderStatus(CartState cart) =>
      requiresFulfillment(cart) ? 'sent' : 'created';
}

/// Stub. Step 5 replaces this with recipe expansion.
class FnbStockStrategy implements StockStrategy {
  const FnbStockStrategy();

  @override
  List<PlannedMovement> expandLine(CartLine line) => const [];

  @override
  Future<StockHint?> hintFor(String productId, {String? variantId}) async => null;
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
            'F&B till\n\nRegistered and routable.\nThe menu, recipes and BOM decrement arrive in step 5.',
            textAlign: TextAlign.center,
          ),
        ),
      ),
    );
  }
}
