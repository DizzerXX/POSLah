import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:printing/printing.dart';

import '../data/local_database.dart';
import '../money.dart';
import '../till/checkout_service.dart';
import '../till/providers.dart';
import '../till/receipt_pdf.dart';
import 'widgets/manager_pin_dialog.dart';

/// Screen 4 of 4: the receipt.
///
/// Everything here is read from the order's own snapshot columns, so a receipt reopened in
/// June is identical to the one handed over in March even if the product has since been
/// renamed or discontinued.
class ReceiptScreen extends ConsumerStatefulWidget {
  const ReceiptScreen({required this.result, super.key});

  final CheckoutResult result;

  @override
  ConsumerState<ReceiptScreen> createState() => _ReceiptScreenState();
}

class _ReceiptScreenState extends ConsumerState<ReceiptScreen> {
  bool _voided = false;

  @override
  Widget build(BuildContext context) {
    final db = ref.watch(localDatabaseProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.result.orderNumber),
        actions: [
          if (!_voided)
            TextButton(
              key: const Key('void-button'),
              onPressed: _void,
              child: const Text('Void'),
            ),
        ],
      ),
      body: FutureBuilder<_ReceiptData>(
        future: _load(db),
        builder: (context, snapshot) {
          final data = snapshot.data;
          if (data == null) return const Center(child: CircularProgressIndicator());

          return Column(
            children: [
              if (_voided)
                Container(
                  width: double.infinity,
                  color: Theme.of(context).colorScheme.errorContainer,
                  padding: const EdgeInsets.all(12),
                  child: Text(
                    'VOIDED',
                    key: const Key('voided-banner'),
                    textAlign: TextAlign.center,
                    style: TextStyle(color: Theme.of(context).colorScheme.onErrorContainer),
                  ),
                ),
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Text(data.storeName, style: Theme.of(context).textTheme.titleLarge),
                    const SizedBox(height: 4),
                    Text('Receipt ${data.order.orderNumber}',
                        key: const Key('receipt-number'),
                        style: Theme.of(context).textTheme.titleMedium),
                    const Divider(height: 24),
                    for (final line in data.lines)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        title: Text(line.nameSnapshot),
                        subtitle: Text('${line.quantity} × ${Money.format(line.priceSnapshot)}'),
                        trailing: Text(Money.format(line.lineTotal)),
                      ),
                    const Divider(height: 24),
                    _row(context, 'Subtotal', Money.format(data.order.subtotal)),
                    if (data.order.discountTotal > 0)
                      _row(context, 'Discount', '-${Money.format(data.order.discountTotal)}'),
                    _row(context, 'Tax', Money.format(data.order.taxTotal)),
                    _row(context, 'Total', Money.format(data.order.grandTotal), bold: true),
                    if (widget.result.roundingAdjustment != 0) ...[
                      _row(context, 'Rounding', Money.format(widget.result.roundingAdjustment)),
                      _row(context, 'Paid', Money.format(widget.result.amountCharged), bold: true),
                    ],
                    const SizedBox(height: 8),
                    _row(context, 'Method', widget.result.method.value.toUpperCase()),
                    if (widget.result.tendered != null)
                      _row(context, 'Tendered', Money.format(widget.result.tendered!)),
                    if (widget.result.change != null)
                      _row(context, 'Change', Money.format(widget.result.change!)),
                  ],
                ),
              ),
              SafeArea(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          key: const Key('share-receipt'),
                          onPressed: () => _share(data),
                          icon: const Icon(Icons.ios_share),
                          // No physical printing in v1 — hardware is out of scope. A PDF is
                          // something a merchant can send today.
                          label: const Text('Share PDF'),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: FilledButton(
                          key: const Key('new-sale'),
                          onPressed: () => Navigator.of(context).pop(),
                          child: const Text('New sale'),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Future<_ReceiptData> _load(LocalDatabase db) async {
    final order =
        await (db.select(db.orders)..where((t) => t.id.equals(widget.result.orderId))).getSingle();

    final lines =
        await (db.select(db.orderLines)..where((t) => t.orderId.equals(order.id))).get();

    final store =
        await (db.select(db.stores)..where((t) => t.id.equals(order.storeId))).getSingleOrNull();

    return _ReceiptData(
      order: order,
      lines: lines,
      storeName: store?.name ?? 'POSlah',
      storeAddress: store?.address,
    );
  }

  Future<void> _share(_ReceiptData data) async {
    final Uint8List bytes = await ReceiptPdf.build(
      order: data.order,
      lines: data.lines,
      storeName: data.storeName,
      storeAddress: data.storeAddress,
      method: widget.result.method,
      amountCharged: widget.result.amountCharged,
      tendered: widget.result.tendered,
      change: widget.result.change,
    );

    await Printing.sharePdf(bytes: bytes, filename: '${data.order.orderNumber}.pdf');
  }

  /// A void needs a manager, and the till asks for one.
  ///
  /// That prompt is a UX gate: a rebuilt app skips it. The check that decides happens on the
  /// server when the reversing entry syncs, which re-reads the authorising user's role.
  Future<void> _void() async {
    final session = ref.read(tillSessionProvider).value;
    if (session == null) return;

    final authorizer = await showDialog<String>(
      context: context,
      builder: (context) => const ManagerPinDialog(),
    );

    if (authorizer == null || !mounted) return;

    try {
      await ref.read(reversalServiceProvider).voidOrder(
            orderId: widget.result.orderId,
            session: session,
            authorizedByUserId: authorizer,
          );

      ref.invalidate(pendingSyncCountProvider);
      ref.invalidate(catalogItemsProvider);

      if (mounted) setState(() => _voided = true);
    } catch (error) {
      if (!mounted) return;

      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$error')));
    }
  }

  Widget _row(BuildContext context, String label, String value, {bool bold = false}) {
    final style = bold
        ? Theme.of(context).textTheme.titleMedium
        : Theme.of(context).textTheme.bodyMedium;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [Text(label, style: style), Text(value, style: style)],
      ),
    );
  }
}

class _ReceiptData {
  const _ReceiptData({
    required this.order,
    required this.lines,
    required this.storeName,
    this.storeAddress,
  });

  final Order order;
  final List<OrderLine> lines;
  final String storeName;
  final String? storeAddress;
}
