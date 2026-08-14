import 'dart:io';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import 'tables.dart';

part 'local_database.g.dart';

/// The till's local store.
///
/// This is not a cache in front of the API. It is where sales are written, and the server
/// is a replica the till pushes to. Checkout completes against this database and returns to
/// the cashier without touching the network — not as a fallback when the network is down,
/// but on every single sale, so the path that runs offline is the same path that runs all
/// the time and cannot rot unnoticed.
@DriftDatabase(
  tables: [
    Stores,
    Users,
    Categories,
    Products,
    ProductVariants,
    Modifiers,
    Ingredients,
    RecipeLines,
    Orders,
    OrderLines,
    OrderLineModifiers,
    LedgerEntries,
    StockMovements,
    StockLevels,
    Customers,
    TimeEntries,
    SyncOutbox,
    SyncState,
  ],
)
class LocalDatabase extends _$LocalDatabase {
  LocalDatabase(super.executor);

  /// An isolated in-memory database. Used by the test suite so every scenario starts from
  /// nothing, and by nothing else.
  LocalDatabase.memory() : super(NativeDatabase.memory());

  @override
  int get schemaVersion => 1;

  /// Reads a sync cursor or device setting.
  Future<String?> readState(String key) async {
    final row = await (select(syncState)..where((t) => t.key.equals(key))).getSingleOrNull();

    return row?.value;
  }

  Future<void> writeState(String key, String? value) {
    return into(syncState).insertOnConflictUpdate(
      SyncStateCompanion.insert(key: key, value: Value(value)),
    );
  }
}

/// Keys held in [SyncState].
class SyncStateKeys {
  /// The cursor for the next pull. Always the server's own `server_time` from the previous
  /// response, never this device's clock: a till running fast would silently skip rows.
  static const lastPullAt = 'last_pull_at';

  static const deviceId = 'device_id';
  static const accountId = 'account_id';
  static const storeId = 'store_id';

  /// The receipt prefix assigned at registration, and the counter behind "T1-0042". The
  /// till owns this counter because it must be able to number a receipt while offline.
  static const numberPrefix = 'number_prefix';
  static const nextSequence = 'next_sequence';

  static const enabledModules = 'enabled_modules';

  /// Set when the backoffice pins this physical till to one vertical. Null means unpinned,
  /// and the switcher appears if the account has more than one module.
  static const assignedModule = 'assigned_module';

  /// Which module the cashier last had open.
  static const activeModule = 'active_module';
}

/// The on-device database file.
LazyDatabase openLocalDatabase() {
  return LazyDatabase(() async {
    final directory = await getApplicationDocumentsDirectory();

    return NativeDatabase.createInBackground(File(p.join(directory.path, 'poslah_till.sqlite')));
  });
}
