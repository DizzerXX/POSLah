import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/till/catalog_repository.dart';

import 'support/catalog_seed.dart';
import 'support/till_fixture.dart';

void main() {
  late LocalDatabase db;
  late CatalogRepository catalogRepo;
  late SeededCatalog catalog;

  setUp(() async {
    db = LocalDatabase.memory();
    catalogRepo = CatalogRepository(db);
    catalog = await seedCatalog(db);
  });

  tearDown(() => db.close());

  group('barcode scanning', () {
    /// The definition-of-done case. Variants are checked before products because that is
    /// the order of specificity: a 250g bag and a 1kg bag are different barcodes on
    /// different variants of one product, and resolving to the parent would put the wrong
    /// price and the wrong stockable into the cart.
    test('a variant barcode resolves to that exact variant', () async {
      final match = await catalogRepo.findByBarcode('9557001000028');

      expect(match, isNotNull);
      expect(match!.variant!.id, catalog.largeBagVariantId);
      expect(match.product.id, catalog.beansProductId);

      // The 1kg bag is RM 45.00 base plus a RM 120.00 delta.
      final item = CatalogItem(product: match.product, variants: [match.variant!]);
      expect(item.priceFor(match.variant), 16500);
    });

    test('the other variant of the same product resolves to the other one', () async {
      final match = await catalogRepo.findByBarcode('9557001000011');

      expect(match!.variant!.id, catalog.smallBagVariantId);
      expect(CatalogItem(product: match.product, variants: [match.variant!]).priceFor(match.variant), 4500);
    });

    test('whitespace around a scan is tolerated', () async {
      expect((await catalogRepo.findByBarcode('  9557001000011 '))!.variant!.id,
          catalog.smallBagVariantId);
    });

    test('an unknown barcode resolves to nothing rather than guessing', () async {
      expect(await catalogRepo.findByBarcode('0000000000000'), isNull);
      expect(await catalogRepo.findByBarcode(''), isNull);
    });

    test('a deactivated variant is not sellable by scan', () async {
      await (db.update(db.productVariants)
            ..where((t) => t.id.equals(catalog.smallBagVariantId)))
          .write(const ProductVariantsCompanion(isActive: Value(false)));

      expect(await catalogRepo.findByBarcode('9557001000011'), isNull);
    });

    /// A live variant hanging off a discontinued product is not sellable either. The variant
    /// row survives because history references it; that is not the same as being on sale.
    test('a live variant under a discontinued product is not sellable', () async {
      await (db.update(db.products)..where((t) => t.id.equals(catalog.beansProductId)))
          .write(const ProductsCompanion(isActive: Value(false)));

      expect(await catalogRepo.findByBarcode('9557001000011'), isNull);
    });
  });

  group('the catalog grid', () {
    test('lists active products, and only active ones', () async {
      expect(await catalogRepo.items(), hasLength(3));

      await (db.update(db.products)..where((t) => t.id.equals(catalog.teeProductId)))
          .write(const ProductsCompanion(isActive: Value(false)));

      final items = await catalogRepo.items();

      expect(items.map((i) => i.product.id), isNot(contains(catalog.teeProductId)));
      // Still in the database — an old order line references it, and a later delta could
      // bring it back. It is simply not on the grid.
      expect(await db.select(db.products).get(), hasLength(3));
    });

    test('filters by category', () async {
      final items = await catalogRepo.items(categoryId: catalog.categoryId);

      expect(items.map((i) => i.product.id), containsAll([catalog.beansProductId, catalog.teeProductId]));
      expect(items.map((i) => i.product.id), isNot(contains(catalog.corkageProductId)));
    });

    test('searches name and sku, case insensitively', () async {
      expect((await catalogRepo.items(search: 'yirga')).single.product.id, catalog.beansProductId);
      expect((await catalogRepo.items(search: 'BEAN-ETH')).single.product.id, catalog.beansProductId);
      expect(await catalogRepo.items(search: 'nothing here'), isEmpty);
    });

    test('carries the variants a product is sold in', () async {
      final beans = (await catalogRepo.items(search: 'Yirgacheffe')).single;

      expect(beans.variants, hasLength(2));
      expect(beans.hasVariants, isTrue);
    });
  });

  group('stock hints', () {
    /// Advisory, and allowed to be wrong. It greys a tile; it never decides whether a sale
    /// may happen.
    test('reports whole units on hand', () async {
      await seedStock(db, catalog.smallBagVariantId, 7);

      expect(await catalogRepo.stockHint(storeId, catalog.smallBagVariantId), 7);
    });

    test('reports nothing when nothing is known', () async {
      expect(await catalogRepo.stockHint(storeId, catalog.largeBagVariantId), isNull);
    });

    /// A hint that clamped at zero would be lying about the thing it is hinting at, and the
    /// merchant needs to see the negative to know a stocktake is due.
    test('reports negative stock rather than clamping it', () async {
      await db.into(db.stockLevels).insert(
            StockLevelsCompanion.insert(
              id: 'lvl-negative',
              storeId: storeId,
              stockableType: 'product_variant',
              stockableId: catalog.largeBagVariantId,
              quantityOnHandMilli: const Value(-2000),
            ),
          );

      expect(await catalogRepo.stockHint(storeId, catalog.largeBagVariantId), -2);
    });
  });
}
