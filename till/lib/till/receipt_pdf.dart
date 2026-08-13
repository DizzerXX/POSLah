import 'dart:typed_data';

import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;

import '../data/local_database.dart';
import '../money.dart';
import 'checkout_service.dart';

/// A receipt.
///
/// PDF export rather than a printer: physical hardware is out of scope for v1, and a PDF is
/// something a merchant can actually hand to a customer over WhatsApp today.
///
/// Everything rendered here comes from the **order's own snapshot columns**, never from the
/// live catalog. A receipt reprinted in June must be identical to the one handed over in
/// March, even if the product has since been renamed, repriced or discontinued.
class ReceiptPdf {
  const ReceiptPdf._();

  static Future<Uint8List> build({
    required Order order,
    required List<OrderLine> lines,
    required String storeName,
    String? storeAddress,
    PaymentMethod method = PaymentMethod.cash,
    int? amountCharged,
    int? tendered,
    int? change,
  }) async {
    final document = pw.Document();
    final charged = amountCharged ?? order.grandTotal;

    document.addPage(
      pw.Page(
        // 80mm roll, the width nearly every counter-top printer uses.
        pageFormat: PdfPageFormat(80 * PdfPageFormat.mm, double.infinity, marginAll: 6),
        build: (context) => pw.Column(
          crossAxisAlignment: pw.CrossAxisAlignment.stretch,
          children: [
            pw.Center(
              child: pw.Text(storeName, style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 12)),
            ),
            if (storeAddress != null)
              pw.Center(child: pw.Text(storeAddress, style: const pw.TextStyle(fontSize: 7))),
            pw.SizedBox(height: 8),

            // The locally generated number. Present on an offline receipt because the till
            // composed it before any network call.
            _row('Receipt', order.orderNumber ?? '—', bold: true),
            _row('Date', _formatTimestamp(order.occurredAt)),
            if (order.status == 'voided') ...[
              pw.SizedBox(height: 4),
              pw.Center(
                child: pw.Text('** VOIDED **', style: pw.TextStyle(fontWeight: pw.FontWeight.bold)),
              ),
            ],
            pw.Divider(height: 12),

            for (final line in lines) ...[
              pw.Text(line.nameSnapshot, style: const pw.TextStyle(fontSize: 9)),
              _row(
                '  ${line.quantity} × ${Money.format(line.priceSnapshot)}',
                Money.format(line.lineTotal),
              ),
            ],

            pw.Divider(height: 12),
            _row('Subtotal', Money.format(order.subtotal)),
            if (order.discountTotal > 0) _row('Discount', '-${Money.format(order.discountTotal)}'),
            _row('Tax (${_formatRate(order.taxRateSnapshot)})', Money.format(order.taxTotal)),
            _row('Total', Money.format(order.grandTotal), bold: true),

            // Cash is rounded to the nearest five sen because 1 and 5 sen coins are out of
            // circulation. Shown as its own line so the customer can see why the number they
            // handed over differs from the total.
            if (charged != order.grandTotal)
              _row('Rounding', Money.format(charged - order.grandTotal)),
            if (charged != order.grandTotal) _row('Payable', Money.format(charged), bold: true),

            pw.SizedBox(height: 6),
            _row('Paid by', method.value.toUpperCase()),
            if (tendered != null) _row('Tendered', Money.format(tendered)),
            if (change != null) _row('Change', Money.format(change)),

            pw.SizedBox(height: 12),
            pw.Center(child: pw.Text('Terima kasih!', style: const pw.TextStyle(fontSize: 9))),
          ],
        ),
      ),
    );

    return document.save();
  }

  static pw.Widget _row(String label, String value, {bool bold = false}) {
    final style = pw.TextStyle(
      fontSize: 9,
      fontWeight: bold ? pw.FontWeight.bold : pw.FontWeight.normal,
    );

    return pw.Padding(
      padding: const pw.EdgeInsets.symmetric(vertical: 1),
      child: pw.Row(
        mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
        children: [pw.Text(label, style: style), pw.Text(value, style: style)],
      ),
    );
  }

  /// "0.0600" -> "6%".
  static String _formatRate(String rate) {
    final value = double.tryParse(rate);
    if (value == null) return rate;

    final percent = value * 100;

    return '${percent == percent.roundToDouble() ? percent.round() : percent}%';
  }

  static String _formatTimestamp(DateTime at) {
    final local = at.toLocal();

    String two(int value) => value.toString().padLeft(2, '0');

    return '${local.year}-${two(local.month)}-${two(local.day)} ${two(local.hour)}:${two(local.minute)}';
  }
}
