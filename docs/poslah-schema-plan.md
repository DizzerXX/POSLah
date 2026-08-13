# POSlah — Schema Plan (v1)

**Stack:** Laravel 11 · PostgreSQL · Redis · Laravel Reverb · Angular 18+ (backoffice) · Flutter (till)

**Build order:** schema → offline sync engine → retail till loop → entitlement/module system → F&B inventory (BOM) → KDS

---

## Locked foundational decisions

| Decision | Choice | Rationale |
|---|---|---|
| Primary keys | Client-generated UUIDv7 | Offline tills mint IDs without coordination; time-sortable, so ledger ordering is free |
| Stock conflict on sync | Accept both, allow negative, flag | A completed sale is a fact; rejecting it on sync is fiction. Negative stock is an exception to surface, not an error to prevent |
| Transaction mutability | Append-only ledger | Refunds/voids write reversing entries; original sale rows are immutable |
| Vertical branching | Item-level `fulfillment_type` | Not a store-level mode toggle; one state machine collapses when no routed items exist |
| Entitlements | Server-enforced, per-account | Client gating is UX; API rejects non-entitled requests independently |

---

## Entity map

### Tenancy & access

**`accounts`**
- `id` (uuid, pk)
- `name`, `timezone`, `currency`
- `enabled_modules` (jsonb array: `["retail"]`, `["fnb"]`, or both)
- `default_fulfillment_type` (enum: `direct` | `routed`) — pre-sets new products, UX convenience only
- timestamps

> Registration must force selecting ≥1 module. An account with an empty `enabled_modules` is a state you prevent, not one you handle at login.

**`users`**
- `id` (uuid, pk), `account_id` (fk)
- `name`, `email`, `password_hash`
- `role` (enum: `owner` | `manager` | `cashier`)
- `pin_hash` — for manager override on void/refund, distinct from login password
- `is_active`

**`stores`**
- `id` (uuid, pk), `account_id` (fk)
- `name`, `address`, `tax_rate`
- v1 is single-store per account, but the fk exists so multi-store is additive later, not a migration

**`devices`**
- `id` (uuid, pk), `account_id`, `store_id`, `registered_by_user_id`
- `device_name`, `last_seen_at`, `last_sync_at`
- Needed for sync debugging: when stock goes negative, you need to know which till did it

---

### Catalog

**`categories`**
- `id` (uuid, pk), `account_id` (fk), `name`, `sort_order`

**`products`**
- `id` (uuid, pk), `account_id`, `category_id`
- `name`, `sku` (nullable — F&B menu items often have none), `barcode` (nullable)
- `price` (integer, minor units — never float for money)
- `fulfillment_type` (enum: `direct` | `routed`) ← **the hinge of the whole design**
- `tracks_stock` (bool) — a latte tracks ingredients, not itself; a service item tracks nothing
- `is_active`

**`product_variants`** *(retail)*
- `id` (uuid, pk), `product_id` (fk)
- `name` (e.g. "Large", "Blue"), `sku`, `price_delta`, `barcode`

**`modifiers`** *(F&B)*
- `id` (uuid, pk), `product_id` (fk)
- `name` (e.g. "Extra shot"), `price_delta`
- `consumes_ingredient_id` (nullable fk → ingredients) — extra shot decrements beans

---

### Inventory — the diverging module

**`ingredients`** *(F&B stock unit)*
- `id` (uuid, pk), `account_id`
- `name`, `unit` (enum: `g` | `ml` | `unit`), `low_stock_threshold`

**`recipe_lines`** *(BOM — the F&B/retail fork)*
- `id` (uuid, pk), `product_id` (fk), `ingredient_id` (fk)
- `quantity` (decimal, in ingredient's unit)
- One burger → 3 rows: bun ×1, patty ×150g, cheese ×2

**`stock_levels`**
- `id` (uuid, pk), `store_id`
- `stockable_type` / `stockable_id` (polymorphic: `product_variant` for retail, `ingredient` for F&B)
- `quantity_on_hand` (decimal — **signed**, negatives are legal and expected)
- `updated_at`

**`stock_movements`** *(append-only, mirrors the ledger philosophy)*
- `id` (uuid, pk), `store_id`, `stockable_type`, `stockable_id`
- `delta` (signed decimal)
- `reason` (enum: `sale` | `refund` | `manual_adjustment` | `stocktake` | `wastage`)
- `source_order_id` (nullable fk), `source_device_id`
- `occurred_at` (client clock), `recorded_at` (server clock) — **both**, because offline sales happen before they're recorded
- Never UPDATE this table. `stock_levels.quantity_on_hand` is a materialized projection of the movement sum.

> **Reconciliation rule:** on sync, movements are appended unconditionally. `quantity_on_hand` is recomputed. If it lands below zero, a `stock_exceptions` row is raised — the sale stands, the merchant gets a flag.

**`stock_exceptions`**
- `id` (uuid, pk), `store_id`, `stockable_type`, `stockable_id`
- `resulting_quantity` (the negative value), `contributing_order_ids` (jsonb)
- `status` (enum: `open` | `resolved`), `resolved_by_user_id`, `resolution_note`

---

### Orders & the ledger

**`orders`**
- `id` (uuidv7, pk — **client-generated**), `account_id`, `store_id`, `device_id`, `cashier_user_id`
- `order_number` (per-store human-readable sequence — assigned server-side on sync, nullable until then)
- `status` (enum: `created` | `sent` | `preparing` | `ready` | `paid` | `voided`)
- `customer_id` (nullable fk)
- `subtotal`, `tax_total`, `discount_total`, `grand_total` (integer minor units)
- `placed_at` (client clock), `synced_at` (server clock, nullable)
- `sync_status` (enum: `pending` | `synced` | `conflicted`) — client-side column, mirrors to server for audit

> **State machine collapse:** if no line item has `fulfillment_type = routed`, the order skips `sent`/`preparing`/`ready` and goes `created → paid`. Same machine, no branching.

**`order_lines`**
- `id` (uuid, pk), `order_id` (fk)
- `product_id`, `product_variant_id` (nullable)
- `name_snapshot`, `price_snapshot` — **denormalized on purpose**; a receipt from March must not change when you rename a product in June
- `fulfillment_type` (copied from product at time of sale, not joined — same reason)
- `quantity`, `line_total`
- `fulfillment_status` (enum: `pending` | `ready` | `fulfilled`) — `direct` lines are `fulfilled` at ring-up; `routed` lines move via KDS

**`order_line_modifiers`**
- `id` (uuid, pk), `order_line_id`, `modifier_id`, `name_snapshot`, `price_delta_snapshot`

**`ledger_entries`** *(append-only, the audit backbone)*
- `id` (uuid, pk), `account_id`, `store_id`, `order_id`
- `entry_type` (enum: `sale` | `refund` | `void`)
- `amount` (signed integer minor units — refunds are negative)
- `reverses_entry_id` (nullable self-fk) — a refund points at the sale it reverses
- `payment_method` (enum: `cash` | `card` | `qr` | `other`) — v1 records method, does not process
- `authorized_by_user_id` (nullable — manager who approved a void/refund)
- `occurred_at`, `recorded_at`

> **No UPDATE, no DELETE on this table, enforced at the DB level via a trigger or revoked grants.** Worth doing literally, not just by convention — it's the kind of thing a reviewer checks.

---

### Shared modules

**`customers`**
- `id` (uuid, pk), `account_id`
- `name`, `phone`, `email`, `notes`
- Identical for both verticals. No fork.

**`time_entries`**
- `id` (uuid, pk), `account_id`, `store_id`, `user_id`
- `clocked_in_at`, `clocked_out_at` (nullable while open)
- `source_device_id`
- Identical for both verticals. No fork.

---

## Sync metadata pattern

Every syncable table carries:

- `id` — client-generated UUIDv7 (idempotency key; re-sending a sale is a no-op via `INSERT ... ON CONFLICT DO NOTHING`)
- `occurred_at` — client clock, when the human did the thing
- `recorded_at` — server clock, when the server learned about it
- `device_id` — provenance for conflict investigation

**Idempotency is the whole trick.** The till may send the same batch twice (crash mid-request, flaky network, user force-quits). Because the client owns the ID and the server does conflict-do-nothing, retry is always safe. No dedupe logic, no client-side "did that go through?" state.

**Clock skew caveat:** `occurred_at` comes from an untrusted client clock. Sort the ledger by `recorded_at` for anything financial; use `occurred_at` for display and for reconstructing the merchant's day. Do not assume `occurred_at` is monotonic across devices. *(This is a real limitation, not a solved problem — document it in the README rather than pretending otherwise.)*

---

## Open items to resolve during implementation

1. **Concurrent decrement strategy** — row lock (`SELECT ... FOR UPDATE` on `stock_levels`) vs optimistic version column. Row lock is simpler and correct for online sales; the offline path bypasses it entirely by design. Decide and document *why*, since a reviewer will look for it.
2. **Recipe depth** — do recipes nest (a sauce is itself made of ingredients)? v1 says no, flat one level. Nesting is a recursive CTE and a scope trap.
3. **`order_number` assignment** — server-assigned on sync means offline receipts have no human-readable number until reconnect. Alternative: per-device prefix (`T1-0042`). Second option is better UX, decide before building the till UI.
4. **Soft delete vs `is_active`** — currently `is_active` on catalog tables. Products referenced by historical orders must never hard-delete; the snapshot columns protect receipts, but the fk still needs the row.

---

## What this schema deliberately does not support

- Multi-location stock transfer
- Nested/compound recipes
- Loyalty points, gift cards, promotions
- Multi-currency
- Payment processing (records method only)
- Table/floor plan
- Mixed-type carts in the **UI** — schema supports mixed orders from day one, v1 UI builds single-type carts only
