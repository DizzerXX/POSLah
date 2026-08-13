import 'package:bcrypt/bcrypt.dart';
import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../data/local_database.dart';
import '../sync/outbox.dart';
import 'till_session.dart';

/// Why a manager override was refused.
enum PinRefusal { unknownUser, notAuthorised, inactive, wrongPin, noPinSet }

class PinCheck {
  const PinCheck.granted(this.user)
      : refusal = null,
        isGranted = true;

  const PinCheck.refused(this.refusal)
      : user = null,
        isGranted = false;

  final User? user;
  final PinRefusal? refusal;
  final bool isGranted;
}

/// Voids and refunds.
///
/// Both write **reversing entries**. Neither mutates the original: the ledger tables refuse
/// UPDATE and DELETE at the database, so a correction has to be a new fact pointing at the
/// old one, and the original stays byte-identical forever.
class ReversalService {
  ReversalService(this._db, this._outbox, {Uuid? uuid, DateTime Function()? clock})
      : _uuid = uuid ?? const Uuid(),
        _clock = clock ?? (() => DateTime.now().toUtc());

  final LocalDatabase _db;
  final Outbox _outbox;
  final Uuid _uuid;
  final DateTime Function() _clock;

  /// Verifies a manager override PIN against the hash cached by pull-sync.
  ///
  /// **This is a UX gate and nothing more.** A rebuilt app can skip this method entirely, so
  /// nothing may depend on it having run. The check that decides is on the server, which
  /// re-reads the authorising user's role when the reversal syncs and rejects the entry if
  /// it is not a manager or owner.
  Future<PinCheck> verifyManagerPin(String userId, String pin) async {
    final user = await (_db.select(_db.users)..where((t) => t.id.equals(userId))).getSingleOrNull();

    if (user == null) return const PinCheck.refused(PinRefusal.unknownUser);
    if (!user.isActive) return const PinCheck.refused(PinRefusal.inactive);

    if (user.role != 'manager' && user.role != 'owner') {
      return const PinCheck.refused(PinRefusal.notAuthorised);
    }

    final hash = user.pinHash;

    // Only managers and owners have a PIN hash locally, so this is the case where a manager
    // simply has not set one. Refuse rather than wave it through.
    if (hash == null || hash.isEmpty) return const PinCheck.refused(PinRefusal.noPinSet);

    if (!BCrypt.checkpw(pin, hash)) return const PinCheck.refused(PinRefusal.wrongPin);

    return PinCheck.granted(user);
  }

  /// Staff who can be offered in the override prompt.
  Future<List<User>> authorisers() {
    return (_db.select(_db.users)
          ..where((t) => t.isActive.equals(true))
          ..where((t) => t.role.isIn(['manager', 'owner'])))
        .get();
  }

  /// Voids a whole order: same day, before settlement.
  ///
  /// Writes a negative ledger entry pointing at the sale, positive stock movements returning
  /// the goods, and flips the local order's status. That status flip is the one permitted
  /// mutation in this area — and it does not travel to the server as an update, because the
  /// server derives it from the void entry arriving. Every sync write is an insert.
  Future<String> voidOrder({
    required String orderId,
    required TillSession session,
    required String authorizedByUserId,
  }) async {
    final occurredAt = _clock();

    return _db.transaction(() async {
      final order =
          await (_db.select(_db.orders)..where((t) => t.id.equals(orderId))).getSingleOrNull();

      if (order == null) throw StateError('No such order: $orderId');
      if (order.status == 'voided') throw StateError('Order ${order.orderNumber} is already voided.');

      final sale = await (_db.select(_db.ledgerEntries)
            ..where((t) => t.orderId.equals(orderId) & t.entryType.equals('sale')))
          .getSingle();

      final voidId = _uuid.v7();

      await _db.into(_db.ledgerEntries).insert(
            LedgerEntriesCompanion.insert(
              id: voidId,
              accountId: session.accountId,
              storeId: session.storeId,
              orderId: Value(orderId),
              entryType: 'void',
              // Negative, so a day's takings stay a plain SUM with no case analysis.
              amount: -sale.amount,
              reversesEntryId: Value(sale.id),
              paymentMethod: sale.paymentMethod,
              authorizedByUserId: Value(authorizedByUserId),
              occurredAt: occurredAt,
            ),
          );

      final movementPayloads = await _returnStock(
        orderId: orderId,
        session: session,
        occurredAt: occurredAt,
        authorizedByUserId: authorizedByUserId,
      );

      await (_db.update(_db.orders)..where((t) => t.id.equals(orderId)))
          .write(const OrdersCompanion(status: Value('voided'), paymentStatus: Value('refunded')));

      await _outbox.enqueue(
        entityType: 'ledger_entry',
        entityId: voidId,
        createdAt: occurredAt,
        payload: {
          'id': voidId,
          'account_id': session.accountId,
          'store_id': session.storeId,
          'order_id': orderId,
          'entry_type': 'void',
          'amount': -sale.amount,
          'reverses_entry_id': sale.id,
          'payment_method': sale.paymentMethod,
          'authorized_by_user_id': authorizedByUserId,
          'metadata': null,
          'occurred_at': occurredAt.toIso8601String(),
        },
      );

      for (final payload in movementPayloads) {
        await _outbox.enqueue(
          entityType: 'stock_movement',
          entityId: payload['id'] as String,
          createdAt: occurredAt,
          payload: payload,
        );
      }

      return voidId;
    });
  }

  /// Positive movements for everything the order took out of stock.
  ///
  /// Derived from the movements the sale actually wrote rather than recomputed from the
  /// lines: if the sale decremented nothing — an untracked product — the void returns
  /// nothing, and the two stay in step by construction.
  Future<List<Map<String, dynamic>>> _returnStock({
    required String orderId,
    required TillSession session,
    required DateTime occurredAt,
    required String authorizedByUserId,
  }) async {
    final sold = await (_db.select(_db.stockMovements)
          ..where((t) => t.sourceOrderId.equals(orderId) & t.reason.equals('sale')))
        .get();

    final payloads = <Map<String, dynamic>>[];

    for (final movement in sold) {
      final id = _uuid.v7();
      final returned = -movement.deltaMilli;

      await _db.into(_db.stockMovements).insert(
            StockMovementsCompanion.insert(
              id: id,
              storeId: session.storeId,
              stockableType: movement.stockableType,
              stockableId: movement.stockableId,
              deltaMilli: returned,
              reason: 'refund',
              sourceOrderId: Value(orderId),
              sourceOrderLineId: Value(movement.sourceOrderLineId),
              createdByUserId: Value(authorizedByUserId),
              occurredAt: occurredAt,
            ),
          );

      final level = await (_db.select(_db.stockLevels)
            ..where((t) =>
                t.storeId.equals(session.storeId) & t.stockableId.equals(movement.stockableId)))
          .getSingleOrNull();

      if (level != null) {
        await (_db.update(_db.stockLevels)..where((t) => t.id.equals(level.id))).write(
          StockLevelsCompanion(quantityOnHandMilli: Value(level.quantityOnHandMilli + returned)),
        );
      }

      payloads.add({
        'id': id,
        'store_id': session.storeId,
        'stockable_type': movement.stockableType,
        'stockable_id': movement.stockableId,
        'delta': _milliToDecimal(returned),
        'reason': 'refund',
        'source_order_id': orderId,
        'source_order_line_id': movement.sourceOrderLineId,
        'created_by_user_id': authorizedByUserId,
        'note': null,
        'occurred_at': occurredAt.toIso8601String(),
      });
    }

    return payloads;
  }

  static String _milliToDecimal(int milli) {
    final negative = milli < 0;
    final magnitude = milli.abs();

    return '${negative ? '-' : ''}${magnitude ~/ 1000}.${(magnitude % 1000).toString().padLeft(3, '0')}';
  }
}
