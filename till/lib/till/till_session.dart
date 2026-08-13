import '../data/local_database.dart';

/// Who this till is.
///
/// Written into `sync_state` at provisioning. Every value here has to be available with no
/// network — the checkout path needs the store, the account and the receipt prefix while
/// offline, so none of them may be something the till asks the server for at sale time.
class TillSession {
  const TillSession({
    required this.deviceId,
    required this.accountId,
    required this.storeId,
    required this.numberPrefix,
    this.cashierUserId,
  });

  final String deviceId;
  final String accountId;
  final String storeId;

  /// "T1". Assigned by the backoffice at device registration and unique per store, which is
  /// what lets two offline tills number receipts independently without colliding.
  final String numberPrefix;

  final String? cashierUserId;

  static Future<TillSession?> load(LocalDatabase db) async {
    final deviceId = await db.readState(SyncStateKeys.deviceId);
    final accountId = await db.readState(SyncStateKeys.accountId);
    final storeId = await db.readState(SyncStateKeys.storeId);
    final prefix = await db.readState(SyncStateKeys.numberPrefix);

    if (deviceId == null || accountId == null || storeId == null || prefix == null) {
      return null;
    }

    return TillSession(
      deviceId: deviceId,
      accountId: accountId,
      storeId: storeId,
      numberPrefix: prefix,
      cashierUserId: await db.readState('cashier_user_id'),
    );
  }

  Future<void> persist(LocalDatabase db) async {
    await db.writeState(SyncStateKeys.deviceId, deviceId);
    await db.writeState(SyncStateKeys.accountId, accountId);
    await db.writeState(SyncStateKeys.storeId, storeId);
    await db.writeState(SyncStateKeys.numberPrefix, numberPrefix);

    if (cashierUserId != null) {
      await db.writeState('cashier_user_id', cashierUserId);
    }
  }

  TillSession withCashier(String? userId) {
    return TillSession(
      deviceId: deviceId,
      accountId: accountId,
      storeId: storeId,
      numberPrefix: numberPrefix,
      cashierUserId: userId,
    );
  }
}
