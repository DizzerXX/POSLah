import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/sync/catalog_applier.dart';

void main() {
  late LocalDatabase db;
  late CatalogApplier applier;

  setUp(() {
    db = LocalDatabase.memory();
    applier = CatalogApplier(db);
  });

  tearDown(() => db.close());

  Map<String, dynamic> product({
    String id = 'p1',
    String name = 'Ethiopia Yirgacheffe',
    bool isActive = true,
    int price = 4500,
    String updatedAt = '2026-08-13T09:00:00+00:00',
  }) {
    return {
      'id': id,
      'account_id': 'a1',
      'category_id': null,
      'name': name,
      'sku': 'BEAN-ETH',
      'barcode': null,
      'price': price,
      'fulfillment_type': 'direct',
      'tracks_stock': true,
      'is_active': isActive,
      'updated_at': updatedAt,
    };
  }

  /// The classic incremental-sync bug, from the client side.
  ///
  /// The server includes deactivated rows in the delta; the till must *store* that flag
  /// rather than deleting the row. Deleting would drop the record an old order line still
  /// references, and would leave no way for a later delta to reverse the decision.
  test('a deactivated product is stored as inactive, not deleted', () async {
    await applier.apply({
      'changes': {
        'products': [product()],
      },
    });

    expect(await db.select(db.products).getSingle(), isA<Product>());

    await applier.apply({
      'changes': {
        'products': [product(isActive: false, updatedAt: '2026-08-13T10:00:00+00:00')],
      },
    });

    final stored = await db.select(db.products).getSingle();

    expect(stored.isActive, isFalse);
    expect(await db.select(db.products).get(), hasLength(1), reason: 'updated in place, not duplicated');
  });

  test('a repeated delta is an upsert rather than a duplicate row', () async {
    await applier.apply({
      'changes': {
        'products': [product(price: 4500)],
      },
    });
    await applier.apply({
      'changes': {
        'products': [product(price: 4900)],
      },
    });

    final rows = await db.select(db.products).get();

    expect(rows, hasLength(1));
    expect(rows.single.price, 4900);
  });

  /// Decimal quantities cross the wire as strings and land as integer milli-units — the
  /// same scale the server's numeric(14,3) uses. No double is involved at any point, so
  /// 18 g of coffee is 18000 exactly rather than 17999.999999999996.
  test('decimal quantities convert losslessly to milli-units', () async {
    await applier.apply({
      'changes': {
        'ingredients': [
          {
            'id': 'i1',
            'account_id': 'a1',
            'name': 'House Espresso Blend',
            'unit': 'g',
            'low_stock_threshold': '1000.000',
            'cost_per_unit': 8,
            'is_active': true,
            'updated_at': '2026-08-13T09:00:00+00:00',
          },
        ],
        'recipe_lines': [
          {
            'id': 'r1',
            'product_id': 'p1',
            'ingredient_id': 'i1',
            'quantity': '18.000',
            'updated_at': '2026-08-13T09:00:00+00:00',
          },
          {
            'id': 'r2',
            'product_id': 'p1',
            'ingredient_id': 'i1',
            'quantity': '0.500',
            'updated_at': '2026-08-13T09:00:00+00:00',
          },
        ],
      },
    });

    final lines = await db.select(db.recipeLines).get();

    expect(lines.firstWhere((l) => l.id == 'r1').quantityMilli, 18000);
    expect(lines.firstWhere((l) => l.id == 'r2').quantityMilli, 500);
    expect((await db.select(db.ingredients).getSingle()).lowStockThresholdMilli, 1000000);
  });

  test('an empty delta applies cleanly', () async {
    final report = await applier.apply({'changes': <String, dynamic>{}});

    expect(report.total, 0);
  });

  test('the report says what changed', () async {
    final report = await applier.apply({
      'changes': {
        'products': [product(), product(id: 'p2', name: 'Sumatra')],
      },
    });

    expect(report['products'], 2);
    expect(report.total, 2);
  });
}
