import 'package:drift/drift.dart';

import '../data/local_database.dart';

/// A product with its variants, as the catalog grid needs it.
class CatalogItem {
  const CatalogItem({required this.product, required this.variants});

  final Product product;
  final List<ProductVariant> variants;

  bool get hasVariants => variants.length > 1 || (variants.length == 1 && variants.first.name.isNotEmpty);

  /// Minor units, base price plus the variant's signed delta.
  int priceFor(ProductVariant? variant) => product.price + (variant?.priceDelta ?? 0);
}

/// What a barcode resolved to.
class BarcodeMatch {
  const BarcodeMatch({required this.product, this.variant});

  final Product product;
  final ProductVariant? variant;
}

/// Reads the local catalog. Never writes it — the catalog flows downward only.
class CatalogRepository {
  CatalogRepository(this._db);

  final LocalDatabase _db;

  /// Categories a cashier can actually sell from.
  Future<List<Category>> activeCategories() {
    return (_db.select(_db.categories)
          ..where((t) => t.isActive.equals(true))
          ..orderBy([(t) => OrderingTerm.asc(t.sortOrder), (t) => OrderingTerm.asc(t.name)]))
        .get();
  }

  /// Sellable products, optionally filtered by category or a search term.
  ///
  /// Deactivated products are excluded here and *only* here. They still exist in the local
  /// database because historical order lines reference them and a later delta may bring them
  /// back — what they must not do is appear on the grid.
  Future<List<CatalogItem>> items({String? categoryId, String? search}) async {
    final query = _db.select(_db.products)..where((t) => t.isActive.equals(true));

    if (categoryId != null) {
      query.where((t) => t.categoryId.equals(categoryId));
    }

    if (search != null && search.trim().isNotEmpty) {
      final term = '%${search.trim().toLowerCase()}%';
      query.where((t) => t.name.lower().like(term) | t.sku.lower().like(term));
    }

    query.orderBy([(t) => OrderingTerm.asc(t.name)]);

    final products = await query.get();
    final items = <CatalogItem>[];

    for (final product in products) {
      final variants = await (_db.select(_db.productVariants)
            ..where((t) => t.productId.equals(product.id) & t.isActive.equals(true))
            ..orderBy([(t) => OrderingTerm.asc(t.name)]))
          .get();

      items.add(CatalogItem(product: product, variants: variants));
    }

    return items;
  }

  /// Resolves a scanned barcode.
  ///
  /// Variants are checked first and products second, because that is the order of
  /// specificity: a 250g bag and a 1kg bag of the same beans are different barcodes on
  /// different variants, and matching the parent product would put the wrong price and the
  /// wrong stockable into the cart.
  Future<BarcodeMatch?> findByBarcode(String barcode) async {
    final trimmed = barcode.trim();
    if (trimmed.isEmpty) return null;

    final variant = await (_db.select(_db.productVariants)
          ..where((t) => t.barcode.equals(trimmed) & t.isActive.equals(true))
          ..limit(1))
        .getSingleOrNull();

    if (variant != null) {
      final product = await (_db.select(_db.products)
            ..where((t) => t.id.equals(variant.productId) & t.isActive.equals(true)))
          .getSingleOrNull();

      // A live variant hanging off a discontinued product is not sellable.
      return product == null ? null : BarcodeMatch(product: product, variant: variant);
    }

    final product = await (_db.select(_db.products)
          ..where((t) => t.barcode.equals(trimmed) & t.isActive.equals(true))
          ..limit(1))
        .getSingleOrNull();

    if (product == null) return null;

    final variants = await (_db.select(_db.productVariants)
          ..where((t) => t.productId.equals(product.id) & t.isActive.equals(true)))
        .get();

    // A product barcode with exactly one variant is unambiguous; with several, the caller
    // has to ask which.
    return BarcodeMatch(product: product, variant: variants.length == 1 ? variants.first : null);
  }

  /// Advisory stock hint for a variant, in whole units, or null when nothing is known.
  ///
  /// **This is a hint and it will sometimes be wrong.** Local stock is a stale projection —
  /// another till may have sold the same thing since the last sync. That is acceptable
  /// precisely because nothing depends on it: it greys a tile or shows "low", and the
  /// cashier can still ring the item up. If this value ever starts blocking a sale, re-read
  /// the locked decision.
  Future<int?> stockHint(String storeId, String variantId) async {
    final level = await (_db.select(_db.stockLevels)
          ..where((t) => t.storeId.equals(storeId) & t.stockableId.equals(variantId)))
        .getSingleOrNull();

    if (level == null) return null;

    return level.quantityOnHandMilli ~/ 1000;
  }
}
