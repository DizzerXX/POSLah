import 'data/local_database.dart';
import 'sync/outbox.dart';
import 'sync/sync_engine.dart';
import 'sync/sync_transport.dart';

/// Where the till gets its server and its credential.
///
/// Passed at build time with `--dart-define`, because there is no device-registration screen
/// yet — enrolling a till from the backoffice is step 4's work, and inventing a settings UI
/// for it now would mean guessing at a flow that step is going to define properly:
///
/// ```
/// flutter run \
///   --dart-define=POSLAH_API_URL=http://127.0.0.1:8000 \
///   --dart-define=POSLAH_DEVICE_TOKEN=<token from php artisan poslah:device-token>
/// ```
class TillConfig {
  const TillConfig._();

  static const apiUrl = String.fromEnvironment('POSLAH_API_URL');
  static const deviceToken = String.fromEnvironment('POSLAH_DEVICE_TOKEN');

  static bool get isConfigured => apiUrl.isNotEmpty && deviceToken.isNotEmpty;
}

/// Builds a sync engine, if this till has been given a server to talk to.
///
/// Returns null when it has not — which is a perfectly ordinary state, not an error. The
/// till sells either way; that is the whole point of local-first, and an unconfigured
/// device simply has nothing to drain to yet.
SyncEngine? buildSyncEngine(LocalDatabase db, {required String deviceId}) {
  if (!TillConfig.isConfigured) return null;

  return SyncEngine(
    db: db,
    outbox: Outbox(db),
    transport: HttpSyncTransport(baseUrl: TillConfig.apiUrl, token: TillConfig.deviceToken),
    deviceId: deviceId,
  );
}

/// First run: fetch the catalog and learn who this device is.
///
/// Only reachable when a token was supplied. Failure here is not fatal — a till with no
/// catalog cannot sell anything useful, but a till that *had* a catalog and cannot reach the
/// server must carry on selling, so this never throws upward.
Future<bool> provisionFromServer(LocalDatabase db) async {
  if (!TillConfig.isConfigured) return false;

  final engine = SyncEngine(
    db: db,
    outbox: Outbox(db),
    transport: HttpSyncTransport(baseUrl: TillConfig.apiUrl, token: TillConfig.deviceToken),
    deviceId: await db.readState(SyncStateKeys.deviceId) ?? '',
  );

  try {
    await engine.pullCatalog();

    return true;
  } on Exception {
    return false;
  }
}
