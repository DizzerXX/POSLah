import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../data/local_database.dart';
import '../sync/outbox.dart';
import 'till_session.dart';

/// Quick-create at the counter.
///
/// Customers are one of the few things a till creates rather than receives, so they are
/// written locally and pushed upward like a sale — client-minted id, snapshot payload, both
/// clocks. Identical for both verticals: there is one of these services, not two.
class CustomerService {
  CustomerService(this._db, this._outbox, {Uuid? uuid, DateTime Function()? clock})
      : _uuid = uuid ?? const Uuid(),
        _clock = clock ?? (() => DateTime.now().toUtc());

  final LocalDatabase _db;
  final Outbox _outbox;
  final Uuid _uuid;
  final DateTime Function() _clock;

  Future<Customer> quickCreate({
    required TillSession session,
    required String name,
    String? phone,
  }) async {
    final id = _uuid.v7();
    final occurredAt = _clock();

    return _db.transaction(() async {
      await _db.into(_db.customers).insert(
            CustomersCompanion.insert(
              id: id,
              accountId: session.accountId,
              name: name,
              phone: Value(phone),
              occurredAt: occurredAt,
            ),
          );

      await _outbox.enqueue(
        entityType: 'customer',
        entityId: id,
        createdAt: occurredAt,
        payload: {
          'id': id,
          'account_id': session.accountId,
          'name': name,
          'phone': phone,
          'email': null,
          'notes': null,
          'occurred_at': occurredAt.toIso8601String(),
        },
      );

      return (_db.select(_db.customers)..where((t) => t.id.equals(id))).getSingle();
    });
  }

  Future<List<Customer>> search(String term) {
    final trimmed = term.trim();
    if (trimmed.isEmpty) return Future.value(const []);

    final pattern = '%${trimmed.toLowerCase()}%';

    return (_db.select(_db.customers)
          ..where((t) => t.name.lower().like(pattern) | t.phone.lower().like(pattern))
          ..limit(20))
        .get();
  }
}
