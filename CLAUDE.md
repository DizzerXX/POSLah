# CLAUDE.md — POSlah

A multi-vertical POS system: **retail and F&B in one codebase**, not two products sharing a repo.

> **The six documents in `/docs` are the source of truth for scope.** If a request conflicts with
> them, say so before implementing. If something is underspecified, ask — do not guess and do not
> silently deviate. Anything not in those documents is out of scope for v1.

---

## Stack

| Component | Technology | Directory |
|---|---|---|
| API | Laravel 11, PHP 8.2+ | `/api` |
| Database | PostgreSQL 16 | — |
| Cache / queue | Redis 7 | — |
| Realtime | Laravel Reverb (step 6) | `/api` |
| Backoffice + KDS | Angular 18+ | `/backoffice` |
| Till | Flutter 3.47 + SQLite via drift | `/till` |
| Plans | The six planning documents | `/docs` |

Local infrastructure is `docker compose up` (Postgres + Redis only). PSR-12 for PHP; idiomatic
Dart and TypeScript elsewhere.

## Build order

Each step is one session. Do not scaffold ahead into a later step.

1. **Schema** — migrations, models, factories, seeders ✅
2. **Sync engine** — outbox, push/pull, reconciliation ✅
3. **Retail till loop** — Flutter, `direct` fulfilment only ✅
4. **Entitlement / module system** — `PosModule` registry, server middleware ✅
5. **F&B inventory** — recipes, BOM expansion, `StockStrategy` revision ✅
6. **KDS** — Reverb channels, kitchen screen, polling reconciliation ✅

---

## Invariants

These are the decisions a later session is most likely to violate by accident. Each one has a
reason; none is a style preference.

### 1. Primary keys are client-generated UUIDv7 — never auto-increment

Every syncable table takes a `uuid` primary key **minted by the client**, time-sortable v7.

- The database must never generate an ID. No `bigserial`, no `gen_random_uuid()` column default.
- Eloquent models set `$incrementing = false` and `$keyType = 'string'`.
- Server-side creation (seeders, backoffice) uses `Str::uuid7()`; it is the same generator, not an
  exception to the rule.

**Why:** an offline till must mint IDs with no coordination. It also makes the ID the idempotency
key — every sync insert is `ON CONFLICT (id) DO NOTHING`, so a retry after a timeout is always
safe and the till never has to ask "did that go through?"

### 2. Money is integer minor units — everywhere

`bigint` in Postgres, `int` in Dart, `number` (integer) in TypeScript. No floats, no decimal
strings in transport, no currency formatting below the render layer. This includes intermediate
values: tax, discounts, and change-due arithmetic are integer arithmetic.

Stock quantities are the exception and are `numeric` — 150 g of patty is not a count.

### 3. `ledger_entries` and `stock_movements` are append-only — enforced by the database

No `UPDATE`, no `DELETE`, ever. This is enforced by a Postgres trigger (`poslah_forbid_mutation`),
not by convention, so an ORM call, a tinker session, and a hand-typed SQL statement all fail
identically.

Corrections are **new reversing rows**: a refund is a negative `ledger_entry` with
`reverses_entry_id` pointing at the sale, plus positive `stock_movements`. The original rows are
byte-identical afterward.

The one permitted mutation nearby is `orders.status` (→ `voided`) and `orders.payment_status`.
`stock_levels` is also mutable — it is a *projection* of the movement sum, not a record of fact.

### 4. Negative stock is legal and expected — never block a sale

`stock_levels.quantity_on_hand` is signed `numeric` with no non-negative check, deliberately.

**No code path may read stock and refuse a sale.** Not the till, not the sync endpoint, not the
backoffice. When reconciliation drives a level below zero it appends the movements anyway,
recomputes the level, and raises a `stock_exceptions` row. The sale stands; the merchant gets a
flag; resolution is a human stocktake (`manual_adjustment` movement).

Stock figures on the till are **advisory hints** for greying a tile. They are stale by design and
are never authoritative.

### 5. Entitlements are authorised from the database, per request

`accounts.enabled_modules` is read from the DB on every request that needs it — **never from a
token claim**. A token issued this morning carries this morning's entitlements; reading the row
closes the window between an admin disabling a module and the token expiring.

The claim in the login response is for *rendering*. The database is for *authorising*. Client-side
gating is UX; a modified APK skips it. The same principle governs manager-PIN checks on void and
refund: the server verifies the authorising user's role independently.

An account with zero modules is prevented by a DB `CHECK`, not handled as an error branch.

### 6. No mode branching on vertical

There is no `business_type` column and there must not be one. **Do not write
`if (module == 'fnb')`** in screen, component, or controller code.

Vertical differences travel through interfaces: `PosModule`, `CheckoutStrategy`, `StockStrategy`
on the till (step 4); route guards and view filters in the backoffice. An unentitled module's
routes are *not registered*, so its screens are unreachable rather than hidden.

The branch that does exist is **per line item**: `products.fulfillment_type` is `direct` or
`routed`, copied onto each order line. The order state machine collapses (`created → paid`) when no
routed line is present — one machine, not two.

### 7. Snapshot columns are load-bearing, not redundancy

`order_lines.name_snapshot`, `order_lines.price_snapshot`, `order_lines.fulfillment_type`,
`order_line_modifiers.name_snapshot` / `price_delta_snapshot`, and `orders.tax_rate_snapshot` are
written at the moment of sale and **never joined from the live catalog**.

A receipt from March must not change when a product is renamed, repriced, retaxed, or switched
from `direct` to `routed` in June. Do not "normalise these away" — the duplication is the point.

The same reasoning governs the sync outbox payload (a snapshot at enqueue time, not a re-read at
send time) and recipe expansion (expanded on the till against the recipe as it was, never
re-expanded server-side).

### 8. Both clocks on every syncable row

- `occurred_at` — **client clock**: when the human did the thing. Untrusted, possibly wrong,
  possibly non-monotonic across devices.
- `recorded_at` — **server clock**: when the server learned about it.

**Anything financial orders by `recorded_at`.** Ledger listings, day totals, and reconciliation use
the server clock. `occurred_at` is for display and for reconstructing the merchant's day.

Clock skew is a documented limitation, not a solved problem. Do not write code that assumes
`occurred_at` is monotonic or trustworthy.

---

## Schema conventions

- **Tenancy:** every table carries `account_id` (directly or via a parent). v1 is single-store per
  account, but `store_id` exists so multi-store is additive rather than a migration.
- **Deletion:** catalog rows use `is_active`, never hard deletion — historical orders hold foreign
  keys to them and the snapshot columns do not remove that need. Deactivated rows **must** appear
  in the pull-sync delta with `is_active: false`, or a discontinued product lives forever on tills.
- **Polymorphic stockables:** `stock_levels` and `stock_movements` point at either a
  `product_variant` (retail) or an `ingredient` (F&B) via `stockable_type` / `stockable_id`.
- **Enums** are Postgres `varchar` + `CHECK`, backed by PHP enums in `App\Enums`. Adding a value is
  a migration, deliberately.
- **Timestamps** are `timestamptz`. Account-level `timezone` governs display only.

## Sync

- **Per-entry results, never batch-level.** One malformed entry must not block the other
  forty-nine. A batch-level failure lets a poison entry wedge a till's queue forever.
- **Database failures must escape the per-entry transaction before being turned into a
  result.** A failed statement aborts the surrounding PostgreSQL transaction; catching it
  inside and returning normally commits a transaction the database has already abandoned,
  and every later query fails with "current transaction is aborted" instead of the real error.
- **`duplicate` is a success.** The till marks the entry synced and moves on.
- **Stock levels are recomputed as a full `SUM(delta)`**, never `+= delta`. This is what makes
  a resend incapable of double-decrementing.
- **The pull cursor is the server's `server_time`**, never the device clock, and deactivated
  rows must appear in the delta.
- **Money is `int` minor units; stock is `int` milli-units on the till** — the same scale as
  the server's `numeric(14,3)`, so decimals cross the wire as strings and convert losslessly.
- **Nothing in the checkout path may await sync.** If a checkout screen knows the network
  state, something has leaked.

## The till

- **Nothing in the checkout path may await the network.** One local transaction, then return.
  If a checkout screen knows the network state, something has leaked. Sync belongs in one
  chip in the app chrome and a debug screen, nowhere else.
- **Cart totals are derived on read**, never stored. Two sources of truth for one number
  means the display and the charge eventually disagree.
- **Snapshot at add-to-cart**, not at checkout: a pull-sync landing mid-transaction must not
  reprice a line the cashier already quoted.
- **Stock quantities on the till are integer milli-units** (1 unit = 1000), matching the
  server's `numeric(14,3)` exactly so decimals convert losslessly with no double involved.
- **Cash rounds to 5 sen; the order total does not.** `orders.grand_total` is the exact sale;
  `ledger_entries.amount` is the money that moved. Card and QR are never rounded.
- **The cached PIN hash is for managers and owners only**, and gating on it is UX. The server
  re-reads the authorising user's role on every reversal, and that check is the one that
  decides.
- **An order's voided status is derived from the void ledger entry arriving**, never pushed
  as an update. Every sync write is an insert.

## Realtime

- **Nothing correctness-critical may depend on a broadcast arriving.** The KDS polls every
  30s and reconciles; the socket is a fast path on top. A broadcast failure must never fail
  the request that triggered it — a sale is not rejected because a kitchen screen was
  unreachable.
- **Channel authorisation checks module entitlement, not just authentication.** A retail-only
  account holds valid credentials and must still be refused the kitchen channel.
- **Test broadcasting against a real broadcaster, never the `log` driver.**
  `LogBroadcaster::auth()` returns true unconditionally and never consults a channel
  callback, so channel-auth tests on `log` pass no matter what those callbacks say.
- **Never use a bare `Event::fake()`** — it fakes Eloquent model events too, and this
  codebase mints UUIDv7 keys in a `creating` hook. Always fake a named list.
- Order status is **derived from lines** on every transition and never set from a client
  payload or a UI action.

## Testing

- Tests run against **real PostgreSQL**, never SQLite. The trigger-enforced append-only rule and
  the jsonb `CHECK` do not exist under SQLite, so a SQLite test suite would pass while the
  invariants it is supposed to protect are unenforced.
- `RefreshDatabase` uses transactions; a test asserting the append-only trigger must therefore
  expect a `QueryException` and let the transaction unwind.
- No mocks unless explicitly requested.

## Known limitations (deliberate — document, do not fix)

Single store per account · flat one-level recipes, no sub-recipes · no ingredient costing or COGS ·
no payment processing (method recorded only) · no loyalty, gift cards, or promotions ·
no multi-currency · no table management or open tabs · no held/parked carts, split payments, or
tipping · no physical printing (PDF export) · mixed-type carts supported by the schema but not
built in the UI · clock skew unmitigated · sync outbox unbounded.
