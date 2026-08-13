# POSlah — F&B Inventory Plan (v1)

**Depends on:** all prior plans
**Position in build order:** step 5 of 6 — after entitlement/module system, before KDS

> This is the step that makes F&B genuinely different from retail rather than cosmetically different. It is also the step that will force a revision of `StockStrategy` — as predicted in step 4. That revision is the expected outcome, not a failure.

---

## The actual difference

Retail: sell 1 unit of SKU → decrement 1 unit of that SKU. One product, one stockable, one movement.

F&B: sell 1 burger → decrement 1 bun, 150g patty, 2 cheese slices. One product, N stockables, N movements. **The product itself has no stock level at all.**

That asymmetry is the whole step. Everything below follows from it.

---

## `StockStrategy` — the revision step 4 predicted

The step-4 interface assumed a stockable maps 1:1 to a sold item. It does not. Revised:

```dart
abstract class StockStrategy {
  /// Expand one cart line into the stock movements it causes.
  /// Retail: returns one movement. F&B: returns one per recipe line.
  List<StockMovement> expandLine(CartLine line);

  /// Advisory availability for UI hints. Never blocks a sale.
  StockHint? hintFor(String productId, {String? variantId});
}
```

**What changed and why:** `expandLine` returns a *list*, not a single movement. `hintFor` returns a nullable hint rather than a quantity, because "how many burgers can I make" is a computed minimum across ingredients, not a stored number — and for untracked products it is simply unknown.

Retail's implementation is a one-element list. The abstraction now fits both without either side being contorted, which is the test of whether it was the right abstraction. Note this revision in the README alongside the step-4 note about planned churn.

---

## Data model

Already in the schema; restating what matters here.

**`ingredients`** — the F&B stockable. `unit` is `g` | `ml` | `unit`.

**`recipe_lines`** — `product_id` → `ingredient_id` + `quantity`. Flat, one level deep.

**`modifiers.consumes_ingredient_id`** — "extra shot" decrements beans on top of the base recipe.

**`products.tracks_stock`** — three cases, and the model must handle all three cleanly:

| Case | `tracks_stock` | Has recipe lines | Stock behaviour |
|---|---|---|---|
| Retail SKU | true | no | Decrement the variant |
| F&B menu item | false | yes | Decrement each ingredient |
| Service / untracked | false | no | No movements at all |

> The third row is why `tracks_stock` and "has a recipe" must be separate concepts rather than one flag. A corkage fee or a delivery charge is a sellable product that touches no inventory. Collapsing these into one boolean produces a schema that cannot express it.

---

## Recipe expansion — on the till

Restating the decision from the sync plan because this is where it gets implemented:

**Expansion happens on the till, at sale time, using the recipe as it exists locally at that moment.**

If a merchant changes the burger recipe on Tuesday, a Monday sale that syncs on Wednesday must still decrement Monday's ingredients. Server-side expansion would silently apply Tuesday's recipe to Monday's burger.

Consequence: `recipe_lines` must be part of the pull-sync payload and stored locally. Already listed in the sync plan's pull set.

**Expansion algorithm:**

```
expandLine(cartLine):
  movements = []

  if product.tracks_stock:                     // retail path
    movements.add(variant, -quantity)
    return movements

  for each recipe_line of product:             // F&B path
    movements.add(
      ingredient_id,
      -(recipe_line.quantity * cartLine.quantity)
    )

  for each modifier on cartLine:
    if modifier.consumes_ingredient_id:
      movements.add(
        modifier.consumes_ingredient_id,
        -(1 * cartLine.quantity)
      )

  return movements                             // empty list is valid
```

**An empty movement list is a legitimate outcome.** Do not treat it as an error or a missing-recipe warning — the untracked-product case is real and common.

**Aggregate before writing.** A cart with two lattes as separate lines produces two `-18g beans` movements. Both are correct and both should be written — they are distinct facts about distinct sales. Do *not* merge them into one `-36g` movement; the ledger is append-only and per-line provenance is what makes reconciliation debuggable later.

---

## Availability hints

Retail hint: read `quantity_on_hand` for the variant. Trivial.

F&B hint: the minimum makeable count across the recipe.

```
hintFor(product):
  if no recipe_lines: return null              // unknowable, show nothing
  return MIN over recipe_lines of
    floor(ingredient.quantity_on_hand / recipe_line.quantity)
```

**This is advisory and it will be wrong.** Local stock is a stale projection; another till may have sold ingredients since the last sync. That is acceptable *because the hint never blocks anything* — it greys a tile or shows "low", and the cashier can still ring the item up.

If you ever find yourself tempted to make this authoritative and block a sale on it, re-read the locked decision: accept both, allow negative, flag for reconciliation. A blocked sale is a customer standing at a counter being told a burger they can see does not exist.

**Performance note:** computing this across a full menu on every catalog render is N recipes × M ingredients per frame. Cache it, invalidate on stock movement or pull-sync. Do not compute it in the build method.

---

## Backoffice: ingredient & recipe management

New sections, F&B-entitled accounts only (route guards per step 4).

**Ingredients list** — name, unit, on-hand, low-stock threshold, exceptions flag.

**Recipe editor** — per menu item: add ingredient rows with quantities. Show a computed cost-per-item if ingredient costs are recorded.

> **Scope check:** ingredient *costing* (purchase price → COGS → margin per menu item) is a genuinely interesting feature and is **out of scope for v1**. It needs purchase orders, weighted average cost, and wastage accounting — that is a subsystem, not a field. Add `cost_per_unit` to `ingredients` now if you like, display a naive computed cost, and stop there.

**Stocktake / adjustment** — the resolution path for negative stock. Records a `manual_adjustment` movement with the counted quantity, closing the `stock_exceptions` row. Append-only, same as everything else.

---

## Reporting divergence

Same aggregation engine, different dimensions — per the locked scope.

- Retail dimensions: SKU, variant, category
- F&B dimensions: menu item, category, and **ingredient consumption** (a retail-meaningless dimension)

Ingredient consumption reporting is `SUM(delta)` over `stock_movements` grouped by ingredient and period. It requires no new machinery — which is the payoff for having made movements append-only in step 1.

---

## Definition of done for step 5

- Selling a burger writes one movement per recipe ingredient, correct quantities
- Selling a burger with "extra cheese" writes the base recipe plus the modifier's ingredient
- Selling an untracked product (corkage) writes zero movements and does not error
- Two lattes as separate cart lines produce two separate movements, not one merged one
- Recipe changed in backoffice → offline sale made before the change still decrements the old recipe on sync
- Ingredient goes negative → exception raised, sale stands
- Availability hint shows a correct minimum across ingredients, and never blocks add-to-cart
- Retail path still works unchanged through the revised `StockStrategy`
- Stocktake adjustment resolves a negative-stock exception

---

## Not in this step

- Nested recipes (a sauce made of ingredients, used in a dish) — flat, one level, locked
- Ingredient costing / COGS / margin analysis
- Purchase orders, supplier management
- Wastage workflows beyond a manual adjustment reason
- Par levels, auto-reorder
- KDS (step 6)

---

## Weakest part of this step

**Flat recipes will feel insufficient the moment you test with a realistic menu.** Real kitchens have sub-recipes — a burger uses "house sauce," which is itself 200ml mayo + 50ml ketchup + spices. Modelling that flat means duplicating the sauce's ingredient list into every dish that uses it, and a sauce recipe change requires editing every dish.

This is a genuine modelling limitation, not a shortcut I am dressing up. [Certain]

I am still recommending flat for v1, because nesting means recursive expansion, cycle detection (sauce A contains sauce B contains sauce A), and unit conversion across levels — a subsystem that would consume the remaining project budget and add no portfolio signal that flat BOM does not already demonstrate.

**Document the limitation explicitly in the README, with the reasoning.** A stated known limitation reads as judgement. The same limitation unstated reads as an oversight.
