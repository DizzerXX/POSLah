import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../money.dart';
import '../till/cart.dart';
import '../till/providers.dart';
import 'checkout_screen.dart';

/// Screen 2 of 4: review the cart before taking money.
class CartReviewScreen extends ConsumerWidget {
  const CartReviewScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Cart')),
      body: const CartPane(showActions: true),
    );
  }
}

/// The cart itself — a side panel on a tablet, this screen's body on a phone.
class CartPane extends ConsumerWidget {
  const CartPane({this.showActions = true, super.key});

  final bool showActions;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cart = ref.watch(cartProvider);
    final controller = ref.read(cartProvider.notifier);

    if (cart.isEmpty) {
      return const Center(child: Text('Cart is empty'));
    }

    return Column(
      children: [
        Expanded(
          child: ListView.separated(
            itemCount: cart.lines.length,
            separatorBuilder: (_, _) => const Divider(height: 1),
            itemBuilder: (context, index) {
              final line = cart.lines[index];

              return ListTile(
                key: Key('cart-line-${line.lineId}'),
                title: Text(line.nameSnapshot),
                // The price the cashier quoted, captured at add-to-cart. A catalog update
                // landing right now does not change what is displayed or charged here.
                subtitle: Text(Money.format(line.priceSnapshot)),
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    IconButton(
                      key: Key('decrement-${line.lineId}'),
                      icon: const Icon(Icons.remove_circle_outline),
                      onPressed: () => controller.decrement(line.lineId),
                    ),
                    Text('${line.quantity}', style: Theme.of(context).textTheme.titleMedium),
                    IconButton(
                      key: Key('increment-${line.lineId}'),
                      icon: const Icon(Icons.add_circle_outline),
                      onPressed: () => controller.increment(line.lineId),
                    ),
                    SizedBox(
                      width: 84,
                      child: Text(
                        Money.format(line.lineTotal),
                        textAlign: TextAlign.right,
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
        ),
        const Divider(height: 1),
        _Totals(cart: cart),
        if (showActions)
          Padding(
            padding: const EdgeInsets.all(12),
            child: Row(
              children: [
                TextButton(
                  key: const Key('discount-button'),
                  onPressed: () => _enterDiscount(context, ref),
                  child: const Text('Discount'),
                ),
                const SizedBox(width: 8),
                // Expanded, not Spacer: at side-panel width the label "Charge RM 1,234.00"
                // is wider than the space left over, and a fixed-size button overflows.
                Expanded(
                  child: FilledButton(
                    key: const Key('checkout-button'),
                    onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(builder: (_) => const CheckoutScreen()),
                    ),
                    child: Text(
                      'Charge ${Money.format(cart.grandTotal)}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }

  /// Order-level only. Line-level discounts are scope creep, and the plan says so.
  Future<void> _enterDiscount(BuildContext context, WidgetRef ref) async {
    final controller = TextEditingController();

    final value = await showDialog<int>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Order discount'),
        content: TextField(
          key: const Key('discount-field'),
          controller: controller,
          autofocus: true,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(prefixText: 'RM '),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(Money.parse(controller.text) ?? 0),
            child: const Text('Apply'),
          ),
        ],
      ),
    );

    if (value != null) ref.read(cartProvider.notifier).applyDiscount(value);
  }
}

class _Totals extends StatelessWidget {
  const _Totals({required this.cart});

  final CartState cart;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Column(
        children: [
          // Every one of these is computed on read. None of them is stored, so the display
          // and the charge cannot drift apart.
          _line(context, 'Subtotal', cart.subtotal),
          if (cart.discountTotal > 0) _line(context, 'Discount', -cart.discountTotal),
          _line(context, 'Tax', cart.taxTotal),
          const SizedBox(height: 4),
          _line(context, 'Total', cart.grandTotal, bold: true),
        ],
      ),
    );
  }

  Widget _line(BuildContext context, String label, int amount, {bool bold = false}) {
    final style = bold
        ? Theme.of(context).textTheme.titleMedium
        : Theme.of(context).textTheme.bodyMedium;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: style),
          Text(Money.format(amount), key: Key('total-$label'), style: style),
        ],
      ),
    );
  }
}
