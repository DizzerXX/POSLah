import 'package:drift/drift.dart';

/// The till's local mirror of the server schema.
///
/// Three rules hold across every table here, and each of them is a rule the server holds
/// too — the point of a local-first till is that both sides agree about what a fact is.
///
/// **Ids are client-generated UUIDv7 text.** No autoincrement anywhere. The till mints ids
/// with no coordination, and the id it chose is what makes a resend idempotent server-side.
///
/// **Money is integer minor units.** `int`, never `double`. A cent of floating point error
/// looks fine in every test that does not use the input that exposes it.
///
/// **Stock quantities are integer milli-units** (1 unit = 1000). Stock is the one family of
/// quantities that is not a count — 150 g of patty — but storing it as a double would
/// reintroduce exactly the drift that integer money exists to avoid. Scaling by 1000 matches
/// the server's numeric(14,3) exactly, so the conversion at the wire is lossless.

/// Local-only sync bookkeeping, mixed into the tables the till writes and pushes.
mixin LocalSyncColumns on Table {
  /// `pending` | `in_flight` | `synced` | `rejected`
  TextColumn get syncStatus => text().withDefault(const Constant('pending'))();
}

// ---------------------------------------------------------------------------
// Catalog. Pulled down, never edited here — the till never changes a product.
// ---------------------------------------------------------------------------

class Stores extends Table {
  TextColumn get id => text()();
  TextColumn get accountId => text()();
  TextColumn get name => text()();
  TextColumn get address => text().nullable()();

  /// Fraction as a string ("0.0600") so it round-trips to the server untouched and gets
  /// snapshotted onto the order rather than recomputed.
  TextColumn get taxRate => text().withDefault(const Constant('0.0000'))();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

class Users extends Table {
  TextColumn get id => text()();
  TextColumn get accountId => text()();
  TextColumn get name => text()();
  TextColumn get email => text()();
  TextColumn get role => text()();

  /// bcrypt hash of the manager override PIN, present only for managers and owners.
  ///
  /// It is here so a void can be gated at the counter while the till is offline, which is
  /// exactly when a void tends to happen. The login password hash is never sent and never
  /// stored. This gate is UX regardless: the server verifies the authorising user's role
  /// independently when the reversal syncs, and that is the check that decides.
  TextColumn get pinHash => text().nullable()();

  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

class Categories extends Table {
  TextColumn get id => text()();
  TextColumn get accountId => text()();
  TextColumn get name => text()();
  IntColumn get sortOrder => integer().withDefault(const Constant(0))();
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

class Products extends Table {
  TextColumn get id => text()();
  TextColumn get accountId => text()();
  TextColumn get categoryId => text().nullable()();
  TextColumn get name => text()();
  TextColumn get sku => text().nullable()();
  TextColumn get barcode => text().nullable()();

  /// Minor units.
  IntColumn get price => integer()();

  /// `direct` | `routed` — the only vertical branch in the system.
  TextColumn get fulfillmentType => text()();
  BoolColumn get tracksStock => boolean().withDefault(const Constant(true))();

  /// Deactivated products are kept, not deleted: historical order lines reference them, and
  /// the pull delta reports deactivation rather than silence.
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

class ProductVariants extends Table {
  TextColumn get id => text()();
  TextColumn get productId => text()();
  TextColumn get name => text()();
  TextColumn get sku => text().nullable()();
  TextColumn get barcode => text().nullable()();

  /// Signed minor units.
  IntColumn get priceDelta => integer().withDefault(const Constant(0))();
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

class Modifiers extends Table {
  TextColumn get id => text()();
  TextColumn get productId => text()();
  TextColumn get name => text()();
  IntColumn get priceDelta => integer().withDefault(const Constant(0))();
  TextColumn get consumesIngredientId => text().nullable()();

  /// Milli-units of the consumed ingredient.
  IntColumn get consumesQuantityMilli => integer().nullable()();
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

class Ingredients extends Table {
  TextColumn get id => text()();
  TextColumn get accountId => text()();
  TextColumn get name => text()();

  /// `g` | `ml` | `unit`
  TextColumn get unit => text()();
  IntColumn get lowStockThresholdMilli => integer().withDefault(const Constant(0))();
  IntColumn get costPerUnit => integer().nullable()();
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

/// Recipes live locally because expansion happens here, at sale time, against the recipe as
/// it stood when the burger was actually made. Expanding server-side at sync time would
/// apply Tuesday's recipe to Monday's sale.
class RecipeLines extends Table {
  TextColumn get id => text()();
  TextColumn get productId => text()();
  TextColumn get ingredientId => text()();
  IntColumn get quantityMilli => integer()();
  DateTimeColumn get updatedAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

// ---------------------------------------------------------------------------
// Facts the till creates and pushes upward.
// ---------------------------------------------------------------------------

class Orders extends Table with LocalSyncColumns {
  TextColumn get id => text()();
  TextColumn get accountId => text()();
  TextColumn get storeId => text()();
  TextColumn get deviceId => text()();
  TextColumn get cashierUserId => text().nullable()();
  TextColumn get customerId => text().nullable()();

  /// "T1-0042", composed locally before any network call, because a customer walks away
  /// with a printed receipt and "pending" is not a number.
  TextColumn get orderNumber => text().nullable()();
  TextColumn get status => text()();
  TextColumn get paymentStatus => text()();

  IntColumn get subtotal => integer()();
  IntColumn get taxTotal => integer()();
  IntColumn get discountTotal => integer()();
  IntColumn get grandTotal => integer()();
  TextColumn get taxRateSnapshot => text()();

  /// This device's clock. Untrusted by the server, and rightly so.
  DateTimeColumn get occurredAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

class OrderLines extends Table {
  TextColumn get id => text()();
  TextColumn get orderId => text()();
  TextColumn get productId => text().nullable()();
  TextColumn get productVariantId => text().nullable()();

  /// Snapshots. What the cashier quoted, captured at add-to-cart, never re-read from the
  /// catalog afterwards.
  TextColumn get nameSnapshot => text()();
  IntColumn get priceSnapshot => integer()();
  TextColumn get fulfillmentType => text()();

  IntColumn get quantity => integer()();
  IntColumn get lineTotal => integer()();
  TextColumn get fulfillmentStatus => text()();

  @override
  Set<Column> get primaryKey => {id};
}

class OrderLineModifiers extends Table {
  TextColumn get id => text()();
  TextColumn get orderLineId => text()();
  TextColumn get modifierId => text().nullable()();
  TextColumn get nameSnapshot => text()();
  IntColumn get priceDeltaSnapshot => integer()();

  @override
  Set<Column> get primaryKey => {id};
}

class LedgerEntries extends Table with LocalSyncColumns {
  TextColumn get id => text()();
  TextColumn get accountId => text()();
  TextColumn get storeId => text()();
  TextColumn get orderId => text().nullable()();
  TextColumn get entryType => text()();

  /// Signed minor units: reversals are negative.
  IntColumn get amount => integer()();
  TextColumn get reversesEntryId => text().nullable()();
  TextColumn get paymentMethod => text()();
  TextColumn get authorizedByUserId => text().nullable()();
  DateTimeColumn get occurredAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

class StockMovements extends Table with LocalSyncColumns {
  TextColumn get id => text()();
  TextColumn get storeId => text()();

  /// `product_variant` for retail, `ingredient` for F&B.
  TextColumn get stockableType => text()();
  TextColumn get stockableId => text()();

  /// Signed milli-units.
  IntColumn get deltaMilli => integer()();
  TextColumn get reason => text()();
  TextColumn get sourceOrderId => text().nullable()();
  TextColumn get sourceOrderLineId => text().nullable()();
  TextColumn get createdByUserId => text().nullable()();
  TextColumn get note => text().nullable()();
  DateTimeColumn get occurredAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

/// Advisory only. Used to grey a tile or show "low"; never consulted to decide whether a
/// sale may proceed. It is a stale projection by construction — another till may have sold
/// the same ingredients since the last sync — and that is acceptable precisely because
/// nothing is allowed to depend on it.
class StockLevels extends Table {
  TextColumn get id => text()();
  TextColumn get storeId => text()();
  TextColumn get stockableType => text()();
  TextColumn get stockableId => text()();
  IntColumn get quantityOnHandMilli => integer().withDefault(const Constant(0))();

  @override
  Set<Column> get primaryKey => {id};
}

class Customers extends Table with LocalSyncColumns {
  TextColumn get id => text()();
  TextColumn get accountId => text()();
  TextColumn get name => text()();
  TextColumn get phone => text().nullable()();
  TextColumn get email => text().nullable()();
  TextColumn get notes => text().nullable()();
  DateTimeColumn get occurredAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

class TimeEntries extends Table with LocalSyncColumns {
  TextColumn get id => text()();
  TextColumn get accountId => text()();
  TextColumn get storeId => text()();
  TextColumn get userId => text()();
  DateTimeColumn get clockedInAt => dateTime()();
  DateTimeColumn get clockedOutAt => dateTime().nullable()();

  @override
  Set<Column> get primaryKey => {id};
}

// ---------------------------------------------------------------------------
// The outbox.
// ---------------------------------------------------------------------------

/// The queue of facts waiting to reach the server.
///
/// Each row is a **snapshot taken at enqueue time**, not a pointer to be re-read when the
/// batch finally goes out. The row it describes may have moved on in between — an order
/// going `created` to `paid` — and what must reach the server is what was true when the
/// thing happened. Same reasoning as the `name_snapshot` columns: capture the state at the
/// moment it mattered.
class SyncOutbox extends Table {
  TextColumn get id => text()();

  /// `customer` | `order` | `ledger_entry` | `stock_movement` | `time_entry`
  TextColumn get entityType => text()();
  TextColumn get entityId => text()();

  /// `create` | `update`. No `delete`: the ledger is append-only and catalog edits arrive
  /// from above rather than leaving from here.
  TextColumn get operation => text().withDefault(const Constant('create'))();

  /// The JSON snapshot, exactly as it will be sent.
  TextColumn get payload => text()();

  TextColumn get syncStatus => text().withDefault(const Constant('pending'))();

  /// Drives the backoff schedule and the stuck-record alert. A permanently silent failure
  /// is worse than a noisy one.
  IntColumn get attempts => integer().withDefault(const Constant(0))();
  TextColumn get lastError => text().nullable()();

  /// When the next attempt becomes eligible. Backoff is stored rather than held in memory
  /// so it survives the app being killed.
  DateTimeColumn get nextAttemptAt => dateTime().nullable()();

  DateTimeColumn get createdAt => dateTime()();

  @override
  Set<Column> get primaryKey => {id};
}

/// Small key/value store for sync cursors and device identity.
class SyncState extends Table {
  TextColumn get key => text()();
  TextColumn get value => text().nullable()();

  @override
  Set<Column> get primaryKey => {key};
}
