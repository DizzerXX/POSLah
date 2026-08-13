# POSlah — Retail Till Loop Plan (v1)

**Depends on:** `poslah-schema-plan.md`, `poslah-sync-engine-plan.md`
**Position in build order:** step 3 of 6 — after sync engine, before entitlement/module system

> This step is deliberately the *least* architecturally novel of the six. The hard problems were solved in step 2. What matters here is not repeating them badly at the UI layer.

---

## Resolved: `order_number` assignment

**Decision: per-device prefix, assigned at device registration.**

Add to `devices`:

- `number_prefix` (string, e.g. `T1`) — assigned by backoffice at registration, unique per store
- `next_sequence` (integer, local counter on the till)

Receipt number format: `T1-0042`. Generated **locally at sale time**, before any network call.

Rejected alternative — server-assigned numbering — would leave an offline receipt numberless until reconnect. A customer walking away with a receipt that says "pending" is not a shippable product. Server-side, `order_number` is stored as-received and uniqueness is scoped to `(store_id, order_number)`; the prefix guarantees no cross-device collision without coordination.

> Same principle as UUIDv7 IDs: anything the till needs at sale time, the till must be able to produce alone.

---

## Scope of this step

Build the **retail** path only — `fulfillment_type = direct`. F&B, recipes, and KDS are steps 5 and 6. The module-switching shell is step 4.

For now the app boots straight into the retail till. Don't build a mode switcher yet; it will feel like wasted work to defer it, but building it before the entitlement system exists means guessing at an interface you'll rewrite.

---

## Cart state model

**Cart is ephemeral and local-only.** It is not persisted to the server, never enters the outbox, and has no row in the schema. It becomes an `order` at the moment of checkout, not before.

> A held/parked cart *would* need persistence — that's a v2 feature, explicitly out of scope. Do not build "save cart for later" now.

**State shape (Flutter — `riverpod` or `bloc`, either is fine; pick one and be consistent):**

```
CartState
  lines: List<CartLine>
  customerId: String?
  discountTotal: int          // minor units
  subtotal: int               // derived
  taxTotal: int               // derived
  grandTotal: int             // derived

CartLine
  lineId: String              // local uuid, for keyed list updates
  productId: String
  variantId: String?
  nameSnapshot: String
  priceSnapshot: int          // captured at add-to-cart
  fulfillmentType: String     // 'direct' for all v1 retail lines
  quantity: int
  lineTotal: int              // derived
```

**Derived totals are computed, never stored in state.** Storing `subtotal` as mutable state means two sources of truth for the same number and a class of bug where the display and the charge disagree. Compute on read.

**Money is integer minor units everywhere.** No doubles, no `Decimal` wrappers in the UI layer. Cents in, cents out, format only at render time.

**Snapshot at add-to-cart, not at checkout.** If a pull-sync lands mid-transaction and changes a price, the line already in the cart keeps the price the cashier quoted. Same reasoning as the schema's `price_snapshot` columns, applied one layer up.

---

## Screens

Four screens. Resist adding a fifth.

**1. Catalog / till**
- Category tabs, product grid, search field
- Barcode scan via camera (`mobile_scanner`) — matches `products.barcode` or `product_variants.barcode`
- Tap product → if it has variants, a variant sheet; if not, straight into the cart
- Cart panel: side panel on tablet, bottom sheet on phone
- Low-stock hint on the product tile (advisory only — **never blocks adding to cart**, per the sync design)

**2. Cart review**
- Line list, quantity steppers, line removal
- Discount entry (order-level only in v1 — line-level discounts are scope creep)
- Customer attach (search existing, or quick-create name + phone)

**3. Checkout**
- Payment method selection: cash, card, QR, other
- Cash: amount tendered → change due calculation
- Non-cash: record method only, no processing (locked scope)
- Confirm → writes the order

**4. Receipt**
- Rendered preview, share/export as PDF
- No physical printing in v1 (hardware is out of scope)
- Shows `order_number`, line items, totals, payment method, timestamp

---

## The checkout write path

This is the one place in this step where correctness matters more than UI polish. It must be a **single local transaction** — partial writes here are the source of the ugliest possible bug class (a ledger entry with no order, an order with no stock movement).

```
BEGIN TRANSACTION (local SQLite)
  1. Generate order id        → UUIDv7, client-side
  2. Generate order_number    → "{prefix}-{next_sequence}", increment counter
  3. INSERT order             → status: 'paid' (no routed lines → state machine collapses)
  4. INSERT order_lines       → with name/price/fulfillment snapshots
  5. INSERT ledger_entry      → entry_type: 'sale', signed positive
  6. INSERT stock_movements   → one per variant, reason: 'sale', negative delta
  7. UPDATE local stock_levels→ advisory only
  8. INSERT sync_outbox rows  → order, ledger_entry, stock_movements
COMMIT
→ return to cashier immediately
```

**No network call in this path.** Not even an optimistic one. The outbox drain is triggered *after* commit, asynchronously, and its success or failure is invisible to the cashier.

**Failure handling:** if the transaction fails (disk full, constraint violation), the sale did not happen and the cashier sees an error. This is the only acceptable checkout failure — a local, immediate, honest one. A partially-committed sale is not.

---

## Refunds and voids

Both write **reversing entries**. Neither mutates the original.

**Void** — same-day, before settlement, whole order:
- `ledger_entry` with `entry_type: 'void'`, `amount` negative, `reverses_entry_id` → original
- `stock_movements` with positive deltas, `reason: 'refund'`
- Original `order.status` → `voided` *(the one permitted status mutation; the ledger stays untouched)*

**Refund** — partial or later, line-level:
- `ledger_entry` with `entry_type: 'refund'`, negative amount, `reverses_entry_id` → original
- Positive `stock_movements` for returned lines only

**Both require manager PIN.** Enforced two ways:
- Client: PIN prompt gates the UI
- Server: sync rejects a void/refund whose `authorized_by_user_id` lacks `manager` or `owner` role

> Client-side gating alone is theatre — a modified APK skips it. The server check is what makes it real. This is the same enforcement principle as module entitlements in step 4, and worth stating in the README as a consistent stance rather than a one-off.

---

## Definition of done for step 3

- Cashier rings up a multi-line sale with variants and completes checkout offline
- Receipt renders with a correct, locally-generated `order_number`
- Reconnect → sale, ledger entry, and stock movements all sync
- Void with manager PIN produces a reversing entry; original order row is byte-identical afterward
- Void attempted with a cashier PIN is rejected at the API, not just hidden in the UI
- Cash payment computes change correctly (test with awkward amounts — RM 0.05 rounding, overpayment)
- Barcode scan adds the correct variant to the cart

---

## Explicitly not in this step

- Held/parked carts
- Line-level discounts
- Split payments (two methods on one order)
- Tipping
- Receipt template customisation
- Physical printing
- Any F&B behaviour — no routed lines, no KDS, no recipes
- The retail/F&B module switcher (step 4)

---

## Where this step is most likely to go wrong

**Rebuilding sync logic in the UI layer.** The temptation is a spinner on checkout, a "syncing…" state, a retry button. All of it is wrong — the cashier must never wait on or think about sync. If the checkout screen knows the network state, something has leaked. Sync status belongs in one place: a small persistent indicator in the app chrome (`3 pending`), plus a debug screen behind a settings menu.

**Floating-point money.** It will look fine in testing and be wrong by a cent under specific inputs. Integer minor units, no exceptions, including in the change-due calculation.
