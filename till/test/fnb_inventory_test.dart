import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/modules/fnb_module.dart';
import 'package:poslah_till/sync/outbox.dart';
import 'package:poslah_till/till/cart.dart';
import 'package:poslah_till/till/checkout_service.dart';
import 'package:poslah_till/till/till_session.dart';
import 'package:uuid/uuid.dart';

import 'support/catalog_seed.dart';
import 'support/till_fixture.dart';

/// Bill-of-materials expansion — the step that makes F&B genuinely different from retail
/// rather than cosmetically different.
///
/// Retail: sell 1 unit of a SKU, decrement 1 unit of that SKU. One product, one stockable.
/// F&B: sell 1 burger, decrement 1 bun, 150g of patty and 2 cheese slices. One product, N
/// stockables — and the product itself has no stock level at all.
void main() {
  late LocalDatabase db;
  late Outbox outbox;
  late RecipeRepository recipes;
  late CheckoutService checkout;
  late SeededMenu menu;

  const session = TillSession(
    deviceId: deviceId,
    accountId: accountId,
    storeId: storeId,
    numberPrefix: 'T2',
  );

  setUp(() async {
    db = LocalDatabase.memory();
    outbox = Outbox(db);
    recipes = RecipeRepository(db);
    menu = await seedMenu(db);

    checkout = CheckoutService(
      db,
      outbox,
      stockStrategy: FnbStockStrategy(recipes, storeId),
      clock: () => DateTime.utc(2026, 8, 14, 12),
    );
  });

  tearDown(() => db.close());

  /// Builds a cart line the way the till does: recipe and modifiers snapshotted at
  /// add-to-cart, so a catalog change mid-transaction cannot alter a sale in progress.
  Future<CartLine> lineFor(
    String productId, {
    int quantity = 1,
    int price = 2200,
    List<String> modifierIds = const [],
  }) async {
    final recipeLines = await recipes.forProduct(productId);
    final product = await (db.select(db.products)..where((t) => t.id.equals(productId))).getSingle();
    final available = await recipes.modifiersFor(productId);

    return CartLine(
      lineId: const Uuid().v7(),
      productId: productId,
      nameSnapshot: product.name,
      priceSnapshot: price,
      quantity: quantity,
      fulfillmentType: product.fulfillmentType,
      tracksStock: product.tracksStock,
      recipe: [
        for (final line in recipeLines)
          RecipeComponent(ingredientId: line.ingredientId, quantityMilli: line.quantityMilli),
      ],
      modifiers: [
        for (final modifier in available.where((m) => modifierIds.contains(m.id)))
          CartModifier(
            modifierId: modifier.id,
            nameSnapshot: modifier.name,
            priceDeltaSnapshot: modifier.priceDelta,
            consumesIngredientId: modifier.consumesIngredientId,
            consumesQuantityMilli: modifier.consumesQuantityMilli,
          ),
      ],
    );
  }

  Future<List<StockMovement>> movements() => db.select(db.stockMovements).get();

  group('expansion', () {
    /// One burger, three rows.
    test('selling a burger writes one movement per recipe ingredient', () async {
      await checkout.checkout(
        cart: CartState(lines: [await lineFor(menu.burgerId)]),
        session: session,
        method: PaymentMethod.card,
      );

      final written = await movements();

      expect(written, hasLength(3));
      expect(written.every((m) => m.stockableType == 'ingredient'), isTrue);

      final byIngredient = {for (final m in written) m.stockableId: m.deltaMilli};

      expect(byIngredient[menu.bunId], -1000, reason: 'one bun');
      expect(byIngredient[menu.pattyId], -150000, reason: '150g of patty');
      expect(byIngredient[menu.cheeseId], -2000, reason: 'two cheese slices');
    });

    test('quantity multiplies every ingredient', () async {
      await checkout.checkout(
        cart: CartState(lines: [await lineFor(menu.burgerId, quantity: 3)]),
        session: session,
        method: PaymentMethod.card,
      );

      final byIngredient = {for (final m in await movements()) m.stockableId: m.deltaMilli};

      expect(byIngredient[menu.bunId], -3000);
      expect(byIngredient[menu.pattyId], -450000);
      expect(byIngredient[menu.cheeseId], -6000);
    });

    /// The modifier consumes *on top of* the base recipe, not instead of it.
    test('extra cheese writes the base recipe plus the modifier ingredient', () async {
      await checkout.checkout(
        cart: CartState(
          lines: [await lineFor(menu.burgerId, modifierIds: [menu.extraCheeseModifierId])],
        ),
        session: session,
        method: PaymentMethod.card,
      );

      final written = await movements();

      // Three recipe rows plus one for the modifier — kept separate rather than folded into
      // the cheese line, so the reason for the extra slice stays visible.
      expect(written, hasLength(4));

      final cheese = written.where((m) => m.stockableId == menu.cheeseId).toList();

      expect(cheese, hasLength(2));
      expect(cheese.map((m) => m.deltaMilli).toList()..sort(), [-2000, -1000]);
    });

    test('a modifier that consumes nothing moves no stock but still prices the line', () async {
      final line = await lineFor(menu.latteId, price: 1300, modifierIds: [menu.lessIceModifierId]);

      expect(line.modifiers, hasLength(1));
      expect(line.lineTotal, 1300, reason: 'Less Ice is free');

      await checkout.checkout(
        cart: CartState(lines: [line]),
        session: session,
        method: PaymentMethod.card,
      );

      // Beans and milk only.
      expect(await movements(), hasLength(2));
    });

    /// The third tracks_stock case, and the reason it cannot be one boolean with "has a
    /// recipe": a corkage fee is sellable and touches no inventory whatsoever.
    test('an untracked product writes zero movements and does not error', () async {
      await checkout.checkout(
        cart: CartState(lines: [await lineFor(menu.corkageId, price: 3000)]),
        session: session,
        method: PaymentMethod.card,
      );

      expect(await movements(), isEmpty);
      expect(await db.select(db.orders).get(), hasLength(1));
      expect(await db.select(db.ledgerEntries).get(), hasLength(1));
    });

    /// Two lattes rung up separately are two facts about two sales. Merging them into one
    /// -36g movement would destroy the per-line provenance that makes reconciliation
    /// debuggable months later.
    test('two lattes as separate lines produce two separate movements each', () async {
      await checkout.checkout(
        cart: CartState(lines: [await lineFor(menu.latteId), await lineFor(menu.latteId)]),
        session: session,
        method: PaymentMethod.card,
      );

      final beans = (await movements()).where((m) => m.stockableId == menu.beansId).toList();

      expect(beans, hasLength(2), reason: 'not merged into one -36g movement');
      expect(beans.every((m) => m.deltaMilli == -18000), isTrue);

      // And each is attributable to its own order line.
      expect(beans.map((m) => m.sourceOrderLineId).toSet(), hasLength(2));
    });

    /// A café sells bags of beans as well as lattes. The F&B module handles that through the
    /// same strategy rather than needing the retail one.
    test('a stock-tracking product inside the fnb module still decrements its variant', () async {
      final retail = await seedCatalog(db);

      final line = CartLine(
        lineId: const Uuid().v7(),
        productId: retail.beansProductId,
        variantId: retail.smallBagVariantId,
        nameSnapshot: 'Ethiopia Yirgacheffe — 250g',
        priceSnapshot: 4500,
        quantity: 2,
      );

      await checkout.checkout(
        cart: CartState(lines: [line]),
        session: session,
        method: PaymentMethod.card,
      );

      final written = await movements();

      expect(written, hasLength(1));
      expect(written.single.stockableType, 'product_variant');
      expect(written.single.deltaMilli, -2000);
    });
  });

  group('the recipe is snapshotted at add-to-cart', () {
    /// The definition-of-done case: a sale made before a recipe change must decrement the
    /// ingredients it was actually made from, even though it syncs afterwards. Re-reading the
    /// recipe at checkout — or expanding server-side at sync time — would silently apply
    /// Tuesday's recipe to Monday's burger.
    test('a recipe change after add-to-cart does not alter the sale', () async {
      final line = await lineFor(menu.burgerId);

      // The merchant reformulates: half the patty, no cheese.
      await (db.update(db.recipeLines)..where((t) => t.id.equals('r-burger-patty')))
          .write(const RecipeLinesCompanion(quantityMilli: Value(75000)));
      await (db.delete(db.recipeLines)..where((t) => t.id.equals('r-burger-cheese'))).go();

      await checkout.checkout(
        cart: CartState(lines: [line]),
        session: session,
        method: PaymentMethod.card,
      );

      final byIngredient = {for (final m in await movements()) m.stockableId: m.deltaMilli};

      expect(byIngredient[menu.pattyId], -150000, reason: 'the burger that was actually made');
      expect(byIngredient[menu.cheeseId], -2000, reason: 'cheese it was actually made with');
    });
  });

  group('availability hints', () {
    /// "How many burgers can I make" is a minimum across ingredients, not a stored number.
    test('the hint is the minimum makeable across the recipe', () async {
      await seedIngredientStock(db, menu.bunId, 10);
      await seedIngredientStock(db, menu.pattyId, 900); // 900g / 150g = 6
      await seedIngredientStock(db, menu.cheeseId, 20); // 20 / 2 = 10

      final hint = await FnbStockStrategy(recipes, storeId).hintFor(menu.burgerId);

      expect(hint!.quantity, 6, reason: 'the patty is the constraint');
      expect(hint.isLow, isFalse, reason: 'six is above the low threshold');
    });

    test('the hint flags low when the tightest ingredient runs down', () async {
      await seedIngredientStock(db, menu.bunId, 10);
      await seedIngredientStock(db, menu.pattyId, 300); // 300g / 150g = 2
      await seedIngredientStock(db, menu.cheeseId, 20);

      final hint = await FnbStockStrategy(recipes, storeId).hintFor(menu.burgerId);

      expect(hint!.quantity, 2);
      expect(hint.isLow, isTrue);
    });

    test('a product with no recipe is unknowable rather than out of stock', () async {
      expect(await FnbStockStrategy(recipes, storeId).hintFor(menu.corkageId), isNull);
    });

    test('an ingredient with no known level makes the whole hint unknowable', () async {
      await seedIngredientStock(db, menu.bunId, 10);
      // Patty and cheese levels are absent.

      expect(await FnbStockStrategy(recipes, storeId).hintFor(menu.burgerId), isNull);
    });

    /// The locked decision, at the layer most tempted to betray it. A hint of zero — or of a
    /// negative — never stops a sale.
    test('a hint of zero does not block the sale', () async {
      await seedIngredientStock(db, menu.bunId, 0);
      await seedIngredientStock(db, menu.pattyId, 0);
      await seedIngredientStock(db, menu.cheeseId, 0);

      final hint = await FnbStockStrategy(recipes, storeId).hintFor(menu.burgerId);
      expect(hint!.quantity, 0);

      await checkout.checkout(
        cart: CartState(lines: [await lineFor(menu.burgerId)]),
        session: session,
        method: PaymentMethod.card,
      );

      expect(await db.select(db.orders).get(), hasLength(1));

      // And the local levels go negative, which is the honest record of what happened.
      final level = await (db.select(db.stockLevels)
            ..where((t) => t.stockableId.equals(menu.pattyId)))
          .getSingle();

      expect(level.quantityOnHandMilli, -150000);
    });
  });

  group('the outbox', () {
    test('every expanded movement is queued for sync, with its modifiers on the line', () async {
      await checkout.checkout(
        cart: CartState(
          lines: [await lineFor(menu.burgerId, modifierIds: [menu.extraCheeseModifierId])],
        ),
        session: session,
        method: PaymentMethod.card,
      );

      final queued = await outbox.nextBatch();

      // order + ledger entry + four movements.
      expect(queued.where((e) => e.entityType == 'stock_movement'), hasLength(4));
      expect(queued.first.entityType, 'order');

      final storedModifiers = await db.select(db.orderLineModifiers).get();
      expect(storedModifiers, hasLength(1));
      expect(storedModifiers.single.nameSnapshot, 'Extra Cheese');
    });
  });
}
