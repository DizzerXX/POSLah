import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'data/local_database.dart';
import 'sync/outbox.dart';
import 'till/providers.dart';

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

/// Mounts whichever module is active.
///
/// A vertical that is not registered has no screen and no route here, so there is nothing
/// for a stray navigation to reach.
class _ModuleHost extends ConsumerWidget {
  const _ModuleHost();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final registry = ref.watch(moduleRegistryProvider);

    return registry.when(
      loading: () => const Scaffold(body: Center(child: CircularProgressIndicator())),
      error: (error, _) => Scaffold(body: Center(child: Text('$error'))),
      data: (registry) {
        final module = registry.current;

        if (module == null) {
          // Prevented by a database CHECK, so reaching this means something upstream is
          // badly wrong rather than that a merchant has no modules.
          return const Scaffold(
            body: Center(child: Text('This account has no modules enabled.')),
          );
        }

        return module.buildTillScreen();
      },
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
                  'Run it with --dart-define=POSLAH_API_URL and POSLAH_DEVICE_TOKEN, '
                  'using a token from: php artisan poslah:device-token "Front Counter"',
                  textAlign: TextAlign.center,
                ),
              ),
            ),
          );
        }

        // The active module builds its own till screen. Nothing here knows which vertical
        // it is; that is the entire point of the registry.
        return const _ModuleHost();
      },
    );
  }
}
