import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../money.dart';
import '../till/checkout_service.dart';
import '../till/providers.dart';
import 'receipt_screen.dart';

/// Screen 3 of 4: take the money.
///
/// **There is no network call on this screen and no sync state visible on it.** The sale is
/// complete when it is written locally; the outbox drains afterwards, asynchronously, and
/// whether that succeeds is not the cashier's problem. A spinner here would be the exact
/// failure the plan warns about.
class CheckoutScreen extends ConsumerStatefulWidget {
  const CheckoutScreen({super.key});

  @override
  ConsumerState<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends ConsumerState<CheckoutScreen> {
  PaymentMethod _method = PaymentMethod.cash;
  final _tenderedController = TextEditingController();
  bool _writing = false;

  @override
  void dispose() {
    _tenderedController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cart = ref.watch(cartProvider);
    final grandTotal = cart.grandTotal;

    // Cash rounds to the nearest five sen; card and QR do not, because no coins change
    // hands.
    final due = _method == PaymentMethod.cash
        ? CashRounding.toNearestFiveSen(grandTotal)
        : grandTotal;

    final tendered = Money.parse(_tenderedController.text);
    final tender = _method == PaymentMethod.cash && tendered != null
        ? CashRounding.tender(grandTotal: grandTotal, tendered: tendered)
        : null;

    final canConfirm = !_writing &&
        cart.isNotEmpty &&
        (_method != PaymentMethod.cash || (tender?.isSufficient ?? false));

    return Scaffold(
      appBar: AppBar(title: const Text('Checkout')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  Text('Amount due', style: Theme.of(context).textTheme.labelLarge),
                  Text(
                    Money.format(due),
                    key: const Key('amount-due'),
                    style: Theme.of(context).textTheme.displaySmall,
                  ),
                  if (due != grandTotal)
                    Text(
                      'Rounded from ${Money.format(grandTotal)}',
                      key: const Key('rounding-note'),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          SegmentedButton<PaymentMethod>(
            segments: const [
              ButtonSegment(value: PaymentMethod.cash, label: Text('Cash'), icon: Icon(Icons.payments)),
              ButtonSegment(value: PaymentMethod.card, label: Text('Card'), icon: Icon(Icons.credit_card)),
              ButtonSegment(value: PaymentMethod.qr, label: Text('QR'), icon: Icon(Icons.qr_code)),
              ButtonSegment(value: PaymentMethod.other, label: Text('Other')),
            ],
            selected: {_method},
            onSelectionChanged: (selection) => setState(() => _method = selection.first),
          ),
          const SizedBox(height: 16),
          if (_method == PaymentMethod.cash) ...[
            TextField(
              key: const Key('tendered-field'),
              controller: _tenderedController,
              autofocus: true,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(
                labelText: 'Cash tendered',
                prefixText: 'RM ',
                border: OutlineInputBorder(),
              ),
              onChanged: (_) => setState(() {}),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              children: [
                for (final note in _suggestedNotes(due))
                  ActionChip(
                    label: Text(Money.format(note)),
                    onPressed: () => setState(() {
                      _tenderedController.text = (note / 100).toStringAsFixed(2);
                    }),
                  ),
              ],
            ),
            const SizedBox(height: 16),
            if (tender != null)
              ListTile(
                title: const Text('Change'),
                trailing: Text(
                  Money.format(tender.change),
                  key: const Key('change-due'),
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                subtitle: tender.isSufficient ? null : const Text('Not enough tendered'),
              ),
          ],
          const SizedBox(height: 8),
          // Recorded, not processed. v1 does not touch a payment network.
          if (_method != PaymentMethod.cash)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 8),
              child: Text('Payment is recorded, not processed.'),
            ),
          FilledButton(
            key: const Key('confirm-payment'),
            onPressed: canConfirm ? () => _confirm(tendered) : null,
            child: const Text('Complete sale'),
          ),
        ],
      ),
    );
  }

  Future<void> _confirm(int? tendered) async {
    setState(() => _writing = true);

    final session = ref.read(tillSessionProvider).value;

    if (session == null) {
      setState(() => _writing = false);
      _showError('This till has not been provisioned.');

      return;
    }

    try {
      // One local transaction. No network, not even optimistically.
      final result = await ref.read(checkoutServiceProvider).checkout(
            cart: ref.read(cartProvider),
            session: session,
            method: _method,
            tendered: tendered,
          );

      ref.read(cartProvider.notifier).clear();
      ref.invalidate(pendingSyncCountProvider);
      ref.invalidate(catalogItemsProvider);

      if (!mounted) return;

      Navigator.of(context).pushReplacement(
        MaterialPageRoute<void>(builder: (_) => ReceiptScreen(result: result)),
      );
    } catch (error) {
      // The only acceptable checkout failure: local, immediate and honest. The sale did not
      // happen, and the cashier is told so rather than being left with a half-written one.
      setState(() => _writing = false);
      _showError('$error');
    }
  }

  void _showError(String message) {
    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('Sale not completed: $message')),
    );
  }

  /// The notes a cashier is most likely to be handed for this amount.
  static List<int> _suggestedNotes(int due) {
    const notes = [1000, 5000, 10000];

    return [due, ...notes.where((note) => note > due)].take(4).toList();
  }
}
