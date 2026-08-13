import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../data/local_database.dart';
import '../money.dart';
import '../sync/outbox.dart';
import 'cart.dart';
import 'till_session.dart';

/// Payment methods v1 records. It does not process any of them.
enum PaymentMethod {
  cash('cash'),
  card('card'),
  qr('qr'),
  other('other');

  const PaymentMethod(this.value);

  final String value;
}

/// What the cashier sees the instant checkout returns.
class CheckoutResult {
  const CheckoutResult({
    required this.orderId,
    required this.orderNumber,
    required this.grandTotal,
    required this.amountCharged,
    required this.method,
    this.tendered,
    this.change,
  });

  final String orderId;

  /// "T1-0042" — generated locally, before any network call, because a customer walks away
  /// holding this.
  final String orderNumber;

  /// The exact arithmetic total of the sale.
  final int grandTotal;

  /// What the customer actually paid. Differs from [grandTotal] by at most two sen, and only
  /// for cash, because 1 and 5 sen coins are out of circulation here.
  final int amountCharged;

  final PaymentMethod method;
  final int? tendered;
  final int? change;

  int get roundingAdjustment => amountCharged - grandTotal;
}

/// Writes a completed sale.
///
/// **One local transaction, no network call, not even an optimistic one.** The outbox drain
/// is triggered after commit and its success is invisible to the cashier. If a checkout
/// screen ever awaits sync, the local-first design has been undone at the last possible
/// moment.
///
/// Partial writes here are the ugliest bug class available — a ledger entry with no order, an
/// order with no stock movement — so everything lands together or nothing does. A failure is
/// local, immediate and honest: the sale did not happen and the cashier is told.
class CheckoutService {
  CheckoutService(this._db, this._outbox, {Uuid? uuid, DateTime Function()? clock})
      : _uuid = uuid ?? const Uuid(),
        _clock = clock ?? (() => DateTime.now().toUtc());

  final LocalDatabase _db;
  final Outbox _outbox;
  final Uuid _uuid;
  final DateTime Function() _clock;

  Future<CheckoutResult> checkout({
    required CartState cart,
    required TillSession session,
    required PaymentMethod method,
    int? tendered,
  }) async {
    if (cart.isEmpty) {
      throw StateError('Cannot check out an empty cart.');
    }

    final grandTotal = cart.grandTotal;

    // Cash rounds to the nearest five sen; card and QR do not, because no coins are
    // involved. The order keeps the exact arithmetic total and the ledger records the money
    // that actually moved — those are two different facts and collapsing them would put a
    // receipt and a ledger entry a couple of sen apart with nothing to explain why.
    final amountCharged =
        method == PaymentMethod.cash ? CashRounding.toNearestFiveSen(grandTotal) : grandTotal;

    if (method == PaymentMethod.cash && (tendered == null || tendered < amountCharged)) {
      throw StateError('Cash tendered is less than the amount due.');
    }

    final occurredAt = _clock();
    final orderId = _uuid.v7();

    return _db.transaction(() async {
      final orderNumber = await _nextOrderNumber(session);

      await _db.into(_db.orders).insert(
            OrdersCompanion.insert(
              id: orderId,
              accountId: session.accountId,
              storeId: session.storeId,
              deviceId: session.deviceId,
              cashierUserId: Value(session.cashierUserId),
              customerId: Value(cart.customerId),
              orderNumber: Value(orderNumber),
              // Fulfilment and payment are separate axes. A retail sale has no routed line,
              // so the fulfilment machine collapses and the order never enters sent /
              // preparing / ready — it is simply created, and paid.
              status: 'created',
              paymentStatus: 'paid',
              subtotal: cart.subtotal,
              taxTotal: cart.taxTotal,
              discountTotal: cart.discountTotal,
              grandTotal: grandTotal,
              taxRateSnapshot: cart.taxRate,
              occurredAt: occurredAt,
            ),
          );

      final lineIds = <String, String>{};

      for (final line in cart.lines) {
        final lineId = _uuid.v7();
        lineIds[line.lineId] = lineId;

        await _db.into(_db.orderLines).insert(
              OrderLinesCompanion.insert(
                id: lineId,
                orderId: orderId,
                productId: Value(line.productId),
                productVariantId: Value(line.variantId),
                nameSnapshot: line.nameSnapshot,
                priceSnapshot: line.priceSnapshot,
                fulfillmentType: line.fulfillmentType,
                quantity: line.quantity,
                lineTotal: line.lineTotal,
                // A direct line is handed over at ring-up, so it is fulfilled the moment
                // it is sold.
                fulfillmentStatus: 'fulfilled',
              ),
            );
      }

      final ledgerId = _uuid.v7();

      await _db.into(_db.ledgerEntries).insert(
            LedgerEntriesCompanion.insert(
              id: ledgerId,
              accountId: session.accountId,
              storeId: session.storeId,
              orderId: Value(orderId),
              entryType: 'sale',
              amount: amountCharged,
              paymentMethod: method.value,
              occurredAt: occurredAt,
            ),
          );

      final movements = await _writeStockMovements(
        cart: cart,
        session: session,
        orderId: orderId,
        lineIds: lineIds,
        occurredAt: occurredAt,
      );

      // Enqueued last, and inside the same transaction: an outbox entry for a sale that
      // rolled back would push a fact that never happened.
      await _enqueue(orderId, orderNumber, cart, session, occurredAt, ledgerId, amountCharged,
          method, movements);

      return CheckoutResult(
        orderId: orderId,
        orderNumber: orderNumber,
        grandTotal: grandTotal,
        amountCharged: amountCharged,
        method: method,
        tendered: tendered,
        change: method == PaymentMethod.cash ? tendered! - amountCharged : null,
      );
    });
  }

  /// "{prefix}-{sequence}", incremented locally.
  ///
  /// The till owns this counter because it has to produce a receipt number with no network.
  /// Server-assigned numbering would mean handing a customer a receipt that says "pending"
  /// until the shop's wifi comes back, which is not a shippable product. Uniqueness comes
  /// from the per-device prefix, so two offline tills cannot collide without coordinating.
  Future<String> _nextOrderNumber(TillSession session) async {
    final current = int.tryParse(await _db.readState(SyncStateKeys.nextSequence) ?? '1') ?? 1;

    await _db.writeState(SyncStateKeys.nextSequence, '${current + 1}');

    return '${session.numberPrefix}-${current.toString().padLeft(4, '0')}';
  }

  /// One movement per stock-tracking line.
  ///
  /// A product with `tracks_stock: false` — a corkage fee, a delivery charge — writes none,
  /// and that empty result is a legitimate outcome rather than a missing-recipe warning.
  Future<List<Map<String, dynamic>>> _writeStockMovements({
    required CartState cart,
    required TillSession session,
    required String orderId,
    required Map<String, String> lineIds,
    required DateTime occurredAt,
  }) async {
    final payloads = <Map<String, dynamic>>[];

    for (final line in cart.lines) {
      if (line.variantId == null) continue;

      final product = await (_db.select(_db.products)..where((t) => t.id.equals(line.productId)))
          .getSingleOrNull();

      if (product != null && !product.tracksStock) continue;

      final movementId = _uuid.v7();
      final deltaMilli = -line.quantity * 1000;

      await _db.into(_db.stockMovements).insert(
            StockMovementsCompanion.insert(
              id: movementId,
              storeId: session.storeId,
              stockableType: 'product_variant',
              stockableId: line.variantId!,
              deltaMilli: deltaMilli,
              reason: 'sale',
              sourceOrderId: Value(orderId),
              sourceOrderLineId: Value(lineIds[line.lineId]),
              createdByUserId: Value(session.cashierUserId),
              occurredAt: occurredAt,
            ),
          );

      // Advisory only. This number greys a tile; it never decides whether a sale may happen,
      // and it is stale the moment another till sells the same thing.
      await _adjustLocalStock(session.storeId, line.variantId!, deltaMilli);

      payloads.add({
        'id': movementId,
        'store_id': session.storeId,
        'stockable_type': 'product_variant',
        'stockable_id': line.variantId,
        'delta': _milliToDecimal(deltaMilli),
        'reason': 'sale',
        'source_order_id': orderId,
        'source_order_line_id': lineIds[line.lineId],
        'created_by_user_id': session.cashierUserId,
        'note': null,
        'occurred_at': occurredAt.toIso8601String(),
      });
    }

    return payloads;
  }

  Future<void> _adjustLocalStock(String storeId, String stockableId, int deltaMilli) async {
    final existing = await (_db.select(_db.stockLevels)
          ..where((t) => t.storeId.equals(storeId) & t.stockableId.equals(stockableId)))
        .getSingleOrNull();

    if (existing == null) {
      await _db.into(_db.stockLevels).insert(
            StockLevelsCompanion.insert(
              id: _uuid.v7(),
              storeId: storeId,
              stockableType: 'product_variant',
              stockableId: stockableId,
              quantityOnHandMilli: Value(deltaMilli),
            ),
          );

      return;
    }

    await (_db.update(_db.stockLevels)..where((t) => t.id.equals(existing.id))).write(
      // Allowed to go negative locally too. A hint that clamps at zero would be lying about
      // the thing it is hinting at.
      StockLevelsCompanion(quantityOnHandMilli: Value(existing.quantityOnHandMilli + deltaMilli)),
    );
  }

  Future<void> _enqueue(
    String orderId,
    String orderNumber,
    CartState cart,
    TillSession session,
    DateTime occurredAt,
    String ledgerId,
    int amountCharged,
    PaymentMethod method,
    List<Map<String, dynamic>> movements,
  ) async {
    final lines = await (_db.select(_db.orderLines)..where((t) => t.orderId.equals(orderId))).get();

    await _outbox.enqueue(
      entityType: 'order',
      entityId: orderId,
      createdAt: occurredAt,
      payload: {
        'id': orderId,
        'account_id': session.accountId,
        'store_id': session.storeId,
        'cashier_user_id': session.cashierUserId,
        'customer_id': cart.customerId,
        'order_number': orderNumber,
        'status': 'created',
        'payment_status': 'paid',
        'subtotal': cart.subtotal,
        'tax_total': cart.taxTotal,
        'discount_total': cart.discountTotal,
        'grand_total': cart.grandTotal,
        'tax_rate_snapshot': cart.taxRate,
        'occurred_at': occurredAt.toIso8601String(),
        'lines': [
          for (final line in lines)
            {
              'id': line.id,
              'product_id': line.productId,
              'product_variant_id': line.productVariantId,
              'name_snapshot': line.nameSnapshot,
              'price_snapshot': line.priceSnapshot,
              'fulfillment_type': line.fulfillmentType,
              'quantity': line.quantity,
              'line_total': line.lineTotal,
              'fulfillment_status': line.fulfillmentStatus,
              'modifiers': <Map<String, dynamic>>[],
            },
        ],
      },
    );

    await _outbox.enqueue(
      entityType: 'ledger_entry',
      entityId: ledgerId,
      createdAt: occurredAt,
      payload: {
        'id': ledgerId,
        'account_id': session.accountId,
        'store_id': session.storeId,
        'order_id': orderId,
        'entry_type': 'sale',
        'amount': amountCharged,
        'reverses_entry_id': null,
        'payment_method': method.value,
        'authorized_by_user_id': null,
        'metadata': null,
        'occurred_at': occurredAt.toIso8601String(),
      },
    );

    for (final movement in movements) {
      await _outbox.enqueue(
        entityType: 'stock_movement',
        entityId: movement['id'] as String,
        createdAt: occurredAt,
        payload: movement,
      );
    }
  }

  /// Milli-units back to the decimal string the wire uses: -2000 becomes "-2.000".
  static String _milliToDecimal(int milli) {
    final negative = milli < 0;
    final magnitude = milli.abs();

    return '${negative ? '-' : ''}${magnitude ~/ 1000}.${(magnitude % 1000).toString().padLeft(3, '0')}';
  }
}
