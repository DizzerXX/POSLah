import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'data/local_database.dart';
import 'sync/outbox.dart';
import 'till/providers.dart';
import 'ui/till_screen.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final db = LocalDatabase(openLocalDatabase());

  // Before anything else. Entries left `in_flight` by a kill mid-batch are invisible to
  // every later drain, so without this the sales inside them are silently marooned — no
  // error, no retry, and a queue that looks clean.
  await Outbox(db).recoverStrandedEntries();

  runApp(
    ProviderScope(
      overrides: [localDatabaseProvider.overrideWithValue(db)],
      child: const PoslahTillApp(),
    ),
  );
}

/// The till.
///
/// Boots straight into the retail till. There is deliberately no module switcher: the
/// entitlement system is step 4, and building a switcher before it exists would mean
/// guessing at an interface that then has to be rewritten.
class PoslahTillApp extends ConsumerWidget {
  const PoslahTillApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return MaterialApp(
      title: 'POSlah Till',
      theme: ThemeData(colorSchemeSeed: const Color(0xFF0F766E), useMaterial3: true),
      home: const _Boot(),
    );
  }
}

class _Boot extends ConsumerWidget {
  const _Boot();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(tillBootProvider);

    return session.when(
      loading: () => const Scaffold(body: Center(child: CircularProgressIndicator())),
      error: (error, _) => Scaffold(body: Center(child: Text('$error'))),
      data: (session) {
        if (session == null) {
          // Device registration lands in step 4's backoffice. Until then a till is
          // provisioned with `php artisan poslah:device-token` and its identity written
          // into sync_state.
          return const Scaffold(
            body: Center(
              child: Padding(
                padding: EdgeInsets.all(32),
                child: Text(
                  'This till has not been provisioned.\n\n'
                  'Write its device id, account, store and receipt prefix into sync_state.',
                  textAlign: TextAlign.center,
                ),
              ),
            ),
          );
        }

        return const TillScreen();
      },
    );
  }
}
