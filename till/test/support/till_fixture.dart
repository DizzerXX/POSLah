import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/sync/outbox.dart';
import 'package:poslah_till/sync/sync_engine.dart';
import 'package:poslah_till/sync/sync_transport.dart';
import 'package:uuid/uuid.dart';

const uuid = Uuid();

const deviceId = '019ff000-0000-7000-8000-000000000001';
const accountId = '019ff000-0000-7000-8000-000000000002';
const storeId = '019ff000-0000-7000-8000-000000000003';

/// A till wired to a database and a transport, as the app would wire it.
class TillFixture {
  TillFixture(this.db, SyncTransport transport)
      : outbox = Outbox(db),
        engine = SyncEngine(
          db: db,
          outbox: Outbox(db),
          transport: transport,
          deviceId: deviceId,
        );

  final LocalDatabase db;
  final Outbox outbox;
  final SyncEngine engine;

  Future<void> close() => db.close();
}

/// Enqueues the three entries one completed sale produces, exactly as the checkout
/// transaction will: the order with its lines, the ledger entry, and the stock movement.
///
/// Returns the order id.
Future<String> enqueueSale(
  Outbox outbox, {
  required String variantId,
  int unitPrice = 4500,
  int quantity = 1,
  String orderNumber = 'T1-0001',
  DateTime? occurredAt,
  String account = accountId,
  String store = storeId,
}) async {
  final at = occurredAt ?? DateTime.utc(2026, 8, 13, 8);
  final orderId = uuid.v7();
  final lineId = uuid.v7();

  final lineTotal = unitPrice * quantity;
  final tax = (lineTotal * 6) ~/ 100;

  await outbox.enqueue(
    entityType: 'order',
    entityId: orderId,
    createdAt: at,
    payload: {
      'id': orderId,
      'account_id': account,
      'store_id': store,
      'cashier_user_id': null,
      'customer_id': null,
      'order_number': orderNumber,
      'status': 'created',
      'payment_status': 'paid',
      'subtotal': lineTotal,
      'tax_total': tax,
      'discount_total': 0,
      'grand_total': lineTotal + tax,
      'tax_rate_snapshot': '0.0600',
      'occurred_at': at.toIso8601String(),
      'lines': [
        {
          'id': lineId,
          'product_id': null,
          'product_variant_id': variantId,
          'name_snapshot': 'Ethiopia Yirgacheffe 250g',
          'price_snapshot': unitPrice,
          'fulfillment_type': 'direct',
          'quantity': quantity,
          'line_total': lineTotal,
          'fulfillment_status': 'fulfilled',
          'modifiers': <Map<String, dynamic>>[],
        },
      ],
    },
  );

  final ledgerId = uuid.v7();

  await outbox.enqueue(
    entityType: 'ledger_entry',
    entityId: ledgerId,
    createdAt: at,
    payload: {
      'id': ledgerId,
      'account_id': account,
      'store_id': store,
      'order_id': orderId,
      'entry_type': 'sale',
      'amount': lineTotal + tax,
      'reverses_entry_id': null,
      'payment_method': 'cash',
      'authorized_by_user_id': null,
      'metadata': null,
      'occurred_at': at.toIso8601String(),
    },
  );

  final movementId = uuid.v7();

  await outbox.enqueue(
    entityType: 'stock_movement',
    entityId: movementId,
    createdAt: at,
    payload: {
      'id': movementId,
      'store_id': store,
      'stockable_type': 'product_variant',
      'stockable_id': variantId,
      'delta': '-${quantity.toString()}.000',
      'reason': 'sale',
      'source_order_id': orderId,
      'source_order_line_id': lineId,
      'created_by_user_id': null,
      'note': null,
      'occurred_at': at.toIso8601String(),
    },
  );

  return orderId;
}
