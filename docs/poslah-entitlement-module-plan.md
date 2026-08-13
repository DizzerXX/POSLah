# POSlah — Entitlement & Module System Plan (v1)

**Depends on:** `poslah-schema-plan.md`, `poslah-sync-engine-plan.md`, `poslah-retail-till-plan.md`
**Position in build order:** step 4 of 6 — after retail till loop, before F&B inventory

> This step converts a retail-only app into a two-vertical platform without duplicating the till. It is the step that justifies the whole "one app, two modes" architecture — and the step where that architecture is most easily undone by convenience.

---

## The thing this step is defending against

An `if (module == 'fnb')` scattered through the UI. Every one of those is a small, reasonable-looking decision that collectively produces two tangled implementations sharing a codebase — worse than two clean separate apps.

The defence is structural, not disciplinary: make the branch **impossible to write** by never exposing a module flag to screen code in the first place.

---

## Data model additions

**`accounts.enabled_modules`** — already in the schema (jsonb array). No change.

**Registration constraint:** an account must be created with at least one module. Enforce at the DB level:

```sql
CHECK (jsonb_array_length(enabled_modules) >= 1)
```

An account with zero modules is a state you *prevent*, not one login has to handle. This removes an entire error branch from the auth flow.

**`devices.assigned_module`** (nullable enum: `retail` | `fnb`) — new.

Rationale: a merchant running both verticals will usually dedicate a physical till to one of them (front counter = retail, kitchen counter = F&B). Pinning a device removes the switcher from that till entirely and eliminates a class of cashier error. Null means "unpinned — show the switcher if the account has both."

---

## Server-side enforcement

**This is the actual security boundary. Everything on the client is UX.**

**Laravel middleware:** `EnsureModuleEnabled`

```php
Route::middleware(['auth:sanctum', 'module:fnb'])->group(function () {
    // KDS endpoints, recipe endpoints, order routing
});

Route::middleware(['auth:sanctum', 'module:retail'])->group(function () {
    // variant/SKU stock endpoints
});
```

The middleware reads `enabled_modules` from the authenticated user's account **on every request** — not from the token claim.

> **Why not trust the token claim:** a JWT/Sanctum token issued this morning carries this morning's entitlements. If an admin disables F&B at noon, a token-claim check keeps granting access until expiry. Reading the account per-request costs one cached query and closes that window. The claim in the login response is for *rendering*; the DB is for *authorising*. Do not let these two uses blur.

**Sync push enforcement:** the batch endpoint rejects individual entries whose entity belongs to a non-entitled module — per-entry `rejected`, never batch-level failure (per the sync design). An F&B order arriving from a retail-only account is a `rejected` entry with reason `module_not_enabled`, and the other 49 entries in the batch still land.

**Test this explicitly.** Hand-craft a request to an F&B endpoint from a retail-only account and confirm a 403. A reviewer reading your test suite and finding this case named learns more about your judgement than any amount of clean code.

---

## Client architecture (Flutter)

### Module registration pattern

Define an interface. Each vertical implements it. Screen code never sees a module string.

```dart
abstract class PosModule {
  String get id;                    // 'retail' | 'fnb'
  String get displayName;
  IconData get icon;

  List<RouteBase> get routes;       // owns its own navigation subtree
  Widget buildTillScreen();
  Widget buildCatalogScreen();

  CheckoutStrategy get checkoutStrategy;
  StockStrategy get stockStrategy;  // SKU decrement vs BOM expansion
}
```

Two implementations: `RetailModule`, `FnbModule`.

**At login:**

1. Auth response returns `enabled_modules`
2. A `ModuleRegistry` registers **only** the modules present in that list
3. If `devices.assigned_module` is set, register only that one
4. Router is built from the registered modules' routes

**Consequences that matter:**

- An unregistered module's screens are not merely hidden — they are **not in the router**. Deep-linking to an F&B route on a retail-only account produces a 404, not an access-denied screen, because the route does not exist in that build of the navigation tree.
- Screen code asks `ModuleRegistry.current.checkoutStrategy`, never `if (module == 'fnb')`. The branch has nowhere to live.
- Adding a third vertical later (services? appointments?) is a new class, not a new `else if` in fifteen files.

### The switcher

Renders **only** when: account has ≥2 modules AND `devices.assigned_module` is null.

Top-right, matching the backoffice placement. Switching swaps which registered module's navigation subtree is mounted.

**Hard rule: an in-progress cart blocks the switch.** Prompt to complete or discard first. Do not attempt to migrate a cart across modules — that is the mixed-cart feature, deferred to v2, and a half-implementation of it here would be worse than its absence.

### Entitlement refresh

Re-read `enabled_modules` on every pull-sync, not just at login. If it changed:

- Module removed → if currently active, force-switch to a remaining module (blocked by an open cart, same rule)
- Module added → register it, switcher appears

A till that stays logged in for three weeks must not run on three-week-old entitlements.

---

## Backoffice (Angular)

**Module toggle** lives in account settings. Toggling off requires a confirmation naming what becomes inaccessible.

**Navigation** is entitlement-driven, same principle as the till: route guards on module-specific sections, and the routes are not registered at all for non-entitled accounts.

**The retail/F&B switcher is a view filter, not a mode.** This bears repeating because it is the point of the whole design: switching to F&B in the backoffice filters the *same* customer list, the *same* time entries, the *same* config to F&B-relevant dimensions. It does not load a different system.

Shared sections (customers, time tracking, users, store config) render identically in both views. Only inventory and reporting change shape.

> If you find yourself building `RetailCustomersComponent` and `FnbCustomersComponent`, stop. The scope doc already ruled this out; this is where the temptation actually arrives.

---

## Definition of done for step 4

- Retail-only account logs into the till: no switcher, no F&B routes in the router
- Both-modules account: switcher present, both navigation subtrees mount correctly
- Device with `assigned_module = 'retail'`: no switcher even though the account has both
- F&B API endpoint returns 403 for a retail-only account — verified by a hand-crafted request, not just UI absence
- Sync batch containing an F&B entity from a retail-only account: that entry `rejected`, remaining entries `accepted`
- Admin disables F&B in backoffice → till reflects it on next pull, without re-login
- Open cart blocks module switch
- Backoffice customer list is one component, rendering in both views

---

## Not in this step

- F&B till behaviour itself (step 5) — `FnbModule` exists here as a registered shell with a stub till screen
- Recipes, BOM decrement (step 5)
- KDS (step 6)
- Mixed-type carts (v2)
- A third vertical

---

## Weakest part of this step

**`FnbModule` is a shell here, so its interface is being designed against an implementation that does not exist yet.** `StockStrategy` and `CheckoutStrategy` will almost certainly need adjustment once step 5 makes F&B real — BOM expansion may want data the interface does not currently pass.

This is acceptable and expected: the interface is cheap to change while there is exactly one real implementor. It becomes expensive later. Do not over-design it now in anticipation; write the minimum that makes retail work through the abstraction, and let step 5 push back on it.

Deliberately building an interface you expect to revise is a different thing from building one carelessly. Worth a line in the README saying so — otherwise a reviewer reading the git history sees churn in `PosModule` and reads it as indecision rather than as a planned second pass.
