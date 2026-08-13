import 'package:flutter/material.dart';

import 'data/local_database.dart';
import 'sync/outbox.dart';
import 'sync/sync_engine.dart';
import 'sync/sync_transport.dart';

/// The till app.
///
/// There is no user interface yet — the retail till loop is step 3. What exists here is the
/// wiring that step will build on, and the one piece of startup behaviour that has to be
/// right from the beginning: recovering outbox entries stranded by a kill mid-batch.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final db = LocalDatabase(openLocalDatabase());
  final outbox = Outbox(db);

  final deviceId = await db.readState(SyncStateKeys.deviceId);

  // Device registration is step 4's backoffice work. Until then a till is provisioned with
  // `php artisan poslah:device-token` and its identity written into sync_state.
  if (deviceId != null) {
    final engine = SyncEngine(
      db: db,
      outbox: outbox,
      transport: HttpSyncTransport(
        baseUrl: const String.fromEnvironment('POSLAH_API_URL', defaultValue: 'http://127.0.0.1:8000'),
        token: await db.readState('device_token') ?? '',
      ),
      deviceId: deviceId,
    );

    // Before anything else. Entries left `in_flight` by a crash are invisible to every
    // later drain, so without this the sales inside them are silently marooned.
    await engine.recoverOnLaunch();
  }

  runApp(const PoslahTillApp());
}

class PoslahTillApp extends StatelessWidget {
  const PoslahTillApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'POSlah Till',
      theme: ThemeData(colorSchemeSeed: const Color(0xFF0F766E), useMaterial3: true),
      home: const Scaffold(
        body: Center(
          child: Padding(
            padding: EdgeInsets.all(32),
            child: Text(
              'POSlah till\n\nSync engine only.\nThe till interface arrives in step 3.',
              textAlign: TextAlign.center,
            ),
          ),
        ),
      ),
    );
  }
}
