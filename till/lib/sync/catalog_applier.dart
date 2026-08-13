import 'package:drift/drift.dart';

import '../data/local_database.dart';

/// How many rows of each kind a pull changed.
class CatalogApplyReport {
  CatalogApplyReport(this.counts);

  final Map<String, int> counts;

  int get total => counts.values.fold(0, (sum, count) => sum + count);

  int operator [](String table) => counts[table] ?? 0;
}

/// Writes a pull delta into the local store.
///
/// Upsert, never delete. A row that arrives with `is_active: false` is *stored* that way
/// rather than removed: the till needs to know the product was discontinued so it can stop
/// offering it, and historical order lines still reference it. Deleting locally would also
/// mean a later delta could never correct the decision.
class CatalogApplier {
  CatalogApplier(this._db);

  final LocalDatabase _db;

  Future<CatalogApplyReport> apply(Map<String, dynamic> response) async {
    final changes = (response['changes'] as Map<String, dynamic>?) ?? const {};
    final counts = <String, int>{};

    await _db.transaction(() async {
      counts['stores'] = await _applyStores(_rows(changes, 'stores'));
      counts['users'] = await _applyUsers(_rows(changes, 'users'));
      counts['categories'] = await _applyCategories(_rows(changes, 'categories'));
      counts['products'] = await _applyProducts(_rows(changes, 'products'));
      counts['product_variants'] = await _applyVariants(_rows(changes, 'product_variants'));
      counts['modifiers'] = await _applyModifiers(_rows(changes, 'modifiers'));
      counts['ingredients'] = await _applyIngredients(_rows(changes, 'ingredients'));
      counts['recipe_lines'] = await _applyRecipeLines(_rows(changes, 'recipe_lines'));
    });

    return CatalogApplyReport(counts);
  }

  List<Map<String, dynamic>> _rows(Map<String, dynamic> changes, String key) {
    return ((changes[key] as List<dynamic>?) ?? const []).cast<Map<String, dynamic>>();
  }

  Future<int> _applyStores(List<Map<String, dynamic>> rows) async {
    for (final row in rows) {
      await _db.into(_db.stores).insertOnConflictUpdate(
            StoresCompanion.insert(
              id: row['id'] as String,
              accountId: row['account_id'] as String,
              name: row['name'] as String,
              address: Value(row['address'] as String?),
              taxRate: Value(row['tax_rate'].toString()),
              updatedAt: _at(row),
            ),
          );
    }

    return rows.length;
  }

  Future<int> _applyUsers(List<Map<String, dynamic>> rows) async {
    for (final row in rows) {
      await _db.into(_db.users).insertOnConflictUpdate(
            UsersCompanion.insert(
              id: row['id'] as String,
              accountId: row['account_id'] as String,
              name: row['name'] as String,
              email: row['email'] as String,
              role: row['role'] as String,
              isActive: Value(_bool(row['is_active'])),
              updatedAt: _at(row),
            ),
          );
    }

    return rows.length;
  }

  Future<int> _applyCategories(List<Map<String, dynamic>> rows) async {
    for (final row in rows) {
      await _db.into(_db.categories).insertOnConflictUpdate(
            CategoriesCompanion.insert(
              id: row['id'] as String,
              accountId: row['account_id'] as String,
              name: row['name'] as String,
              sortOrder: Value(row['sort_order'] as int? ?? 0),
              isActive: Value(_bool(row['is_active'])),
              updatedAt: _at(row),
            ),
          );
    }

    return rows.length;
  }

  Future<int> _applyProducts(List<Map<String, dynamic>> rows) async {
    for (final row in rows) {
      await _db.into(_db.products).insertOnConflictUpdate(
            ProductsCompanion.insert(
              id: row['id'] as String,
              accountId: row['account_id'] as String,
              categoryId: Value(row['category_id'] as String?),
              name: row['name'] as String,
              sku: Value(row['sku'] as String?),
              barcode: Value(row['barcode'] as String?),
              price: row['price'] as int,
              fulfillmentType: row['fulfillment_type'] as String,
              tracksStock: Value(_bool(row['tracks_stock'])),
              isActive: Value(_bool(row['is_active'])),
              updatedAt: _at(row),
            ),
          );
    }

    return rows.length;
  }

  Future<int> _applyVariants(List<Map<String, dynamic>> rows) async {
    for (final row in rows) {
      await _db.into(_db.productVariants).insertOnConflictUpdate(
            ProductVariantsCompanion.insert(
              id: row['id'] as String,
              productId: row['product_id'] as String,
              name: row['name'] as String,
              sku: Value(row['sku'] as String?),
              barcode: Value(row['barcode'] as String?),
              priceDelta: Value(row['price_delta'] as int? ?? 0),
              isActive: Value(_bool(row['is_active'])),
              updatedAt: _at(row),
            ),
          );
    }

    return rows.length;
  }

  Future<int> _applyModifiers(List<Map<String, dynamic>> rows) async {
    for (final row in rows) {
      await _db.into(_db.modifiers).insertOnConflictUpdate(
            ModifiersCompanion.insert(
              id: row['id'] as String,
              productId: row['product_id'] as String,
              name: row['name'] as String,
              priceDelta: Value(row['price_delta'] as int? ?? 0),
              consumesIngredientId: Value(row['consumes_ingredient_id'] as String?),
              consumesQuantityMilli: Value(_milli(row['consumes_quantity'])),
              isActive: Value(_bool(row['is_active'])),
              updatedAt: _at(row),
            ),
          );
    }

    return rows.length;
  }

  Future<int> _applyIngredients(List<Map<String, dynamic>> rows) async {
    for (final row in rows) {
      await _db.into(_db.ingredients).insertOnConflictUpdate(
            IngredientsCompanion.insert(
              id: row['id'] as String,
              accountId: row['account_id'] as String,
              name: row['name'] as String,
              unit: row['unit'] as String,
              lowStockThresholdMilli: Value(_milli(row['low_stock_threshold']) ?? 0),
              costPerUnit: Value(row['cost_per_unit'] as int?),
              isActive: Value(_bool(row['is_active'])),
              updatedAt: _at(row),
            ),
          );
    }

    return rows.length;
  }

  Future<int> _applyRecipeLines(List<Map<String, dynamic>> rows) async {
    for (final row in rows) {
      await _db.into(_db.recipeLines).insertOnConflictUpdate(
            RecipeLinesCompanion.insert(
              id: row['id'] as String,
              productId: row['product_id'] as String,
              ingredientId: row['ingredient_id'] as String,
              quantityMilli: _milli(row['quantity']) ?? 0,
              updatedAt: _at(row),
            ),
          );
    }

    return rows.length;
  }

  DateTime _at(Map<String, dynamic> row) {
    return DateTime.parse(row['updated_at'] as String).toUtc();
  }

  /// Postgres booleans arrive as `true`/`false` over JSON, but a raw query builder result
  /// can hand back 1/0 or "t". Be liberal about the shape and strict about the meaning.
  bool _bool(Object? value) {
    return switch (value) {
      bool v => v,
      int v => v != 0,
      String v => v == 'true' || v == 't' || v == '1',
      _ => false,
    };
  }

  /// Decimal string to integer milli-units. "18.000" becomes 18000 — the same scale the
  /// server's numeric(14,3) uses, so nothing is lost or invented in the conversion, and no
  /// double is involved at any point.
  int? _milli(Object? value) {
    if (value == null) return null;

    final text = value.toString();
    final parts = text.split('.');
    final whole = int.parse(parts[0]);
    final fraction = parts.length > 1 ? parts[1].padRight(3, '0').substring(0, 3) : '000';
    final magnitude = whole.abs() * 1000 + int.parse(fraction);

    return text.startsWith('-') ? -magnitude : magnitude;
  }
}
