# POSlah

A point-of-sale system that serves **two verticals — retail and F&B — from one codebase**, without
becoming two tangled implementations sharing a repo. A shop selling bags of coffee beans and a café
selling lattes made from those beans run the same till, the same backoffice, and the same sync
engine; the difference lives in a per-item `fulfillment_type` and a pair of strategy interfaces, not
in a mode flag branched on throughout the UI.

The till is **local-first**: every sale writes to local SQLite and returns to the cashier
immediately, network or not. The server is a replica the till pushes to, never a dependency in the
checkout path.

---

## Status

| Step | Scope | State |
|---|---|---|
| 1 | Schema — migrations, models, factories, seeders | ✅ |
| 2 | Sync engine — outbox, push/pull, reconciliation | ✅ |
| 3 | Retail till loop (Flutter) | ✅ |
| 4 | Entitlement / module system | — |
| 5 | F&B inventory — recipes, BOM expansion | — |
| 6 | KDS — Reverb, kitchen screen, polling reconciliation | — |

## Layout

```
/api          Laravel 11 · PostgreSQL · Redis · Reverb
/backoffice   Angular 18 — merchant backoffice and (step 6) KDS
/till         Flutter — the till app (step 3)
/docs         The six planning documents; source of truth for scope
```

## Running it

```bash
docker compose up -d                 # Postgres 16 + Redis 7

cd api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate:fresh --seed     # schema + a two-vertical demo account
php artisan test
```

The seed produces one account with **both** modules enabled: a retail store with SKUs and variants,
and an F&B menu with ingredients and recipes — so the divergence the architecture exists to handle
is present from the first `artisan` run. It also produces two single-vertical accounts, which are
what the entitlement tests in step 4 will need.

The till:

```bash
cd till
flutter pub get
dart run build_runner build        # drift codegen
flutter test
```

### Running the two halves against each other

Most till tests run against an in-process fake, which on its own only proves the till agrees
with *our description* of the contract. One test proves the two halves actually agree, and it
is skipped unless pointed at a running server:

```bash
cd api && php artisan serve &
php artisan poslah:device-token "Front Counter"      # prints a token, once

cd ../till
POSLAH_API_URL=http://127.0.0.1:8000 \
POSLAH_DEVICE_TOKEN=<token> \
flutter test test/sync_live_test.dart
```

## The sync engine

The till is **local-first, not offline-fallback**. Every sale writes to local SQLite and returns
to the cashier immediately — not as a fallback when the network drops, but on every sale, so the
path that runs offline is the same path that runs all the time and cannot rot unnoticed.

**Push results are per entry, never batch-level.** Each entry lands or fails alone. A batch-level
transaction would let one malformed row wedge a till's queue permanently: the till resends, the
same row fails, and nothing behind it ever syncs again.

**Idempotency is the whole trick.** IDs are minted by the till, so every server-side insert is
`ON CONFLICT DO NOTHING` and a resend is free. After a timeout the till simply sends again rather
than asking whether the first attempt landed — a question that is racy, expensive, and unnecessary.

**Stock levels are recomputed as a full SUM over the append-only movement log**, not adjusted by a
delta. That is what makes a duplicate push incapable of double-decrementing: the safety rests on
the projection being derived rather than on duplicate detection being perfect.

**Negative stock raises an exception, never a rejection.** Two tills sell the last unit while
offline; both sales are facts. Stock lands at `-1`, a `stock_exceptions` row is opened for the
merchant, and no sale is refused.

### Test scenarios covered

| Scenario | Where |
|---|---|
| Three sales offline → reconnect, all land, stock reconciles | `api` · `SyncScenariosTest` |
| Same batch sent twice → all duplicate, no double decrement | `api` · `SyncScenariosTest` |
| **Kill the app mid-batch** → stranded entries recovered, resend is a duplicate | `till` · `crash_recovery_test` |
| Two tills sell the last unit offline → both land, stock `-1`, one exception | `api` · `SyncScenariosTest` |
| Poison entry in a batch of 50 → 48 land, 2 rejected, queue keeps draining | `api` · `SyncScenariosTest` |
| Product deactivated server-side → arrives in the delta as `is_active: false` | `api` · `CatalogPullTest`, `till` · `catalog_applier_test` |
| Batch arriving with dependents ahead of their parent still lands | `api` · `SyncScenariosTest` |
| Multi-line sale with variants completed offline, no transport wired | `till` · `till_flow_test` |
| Cash rounding to 5 sen, change on awkward amounts and overpayment | `till` · `money_test` |
| Void writes a reversing entry; the original sale is byte-identical | `till` · `reversal_service_test` |
| **Void authorised by a cashier is refused at the API**, not just hidden | `api` · `ReversalAuthorizationTest` |
| Barcode resolves to the exact variant, not its parent product | `till` · `catalog_repository_test` |
| Receipt renders from snapshots after the product is renamed and discontinued | `till` · `receipt_pdf_test` |
| A stolen token cannot write into another account | `api` · `SyncAuthorizationTest` |
| Full loop over real HTTP against real PostgreSQL | `till` · `sync_live_test` |

## The five architecture decisions

Detail and reasoning in [`CLAUDE.md`](CLAUDE.md) and `/docs`.

**1 · Client-generated UUIDv7 primary keys.** An offline till mints IDs with no coordination, and
the ID doubles as the idempotency key — every sync insert is `ON CONFLICT DO NOTHING`, so retrying
after a timeout is always safe and the till never has to ask "did that go through?"

**2 · Stock conflicts accept both writes, allow negative, and flag.** Two tills sell the last unit
while offline; both sales are facts. Rejecting one on sync is fiction. Stock goes to `-1`, an
exception is raised for the merchant, and no sale is ever blocked on stock availability.

**3 · Append-only ledger.** `ledger_entries` and `stock_movements` refuse `UPDATE` and `DELETE` at
the database level via trigger — not by convention. Refunds and voids write reversing rows; the
original is byte-identical afterward.

**4 · Item-level `fulfillment_type`, not a store-level mode.** A line is `direct` or `routed`. One
order state machine serves both verticals and *collapses* (`created → paid`) when no routed line
exists, rather than branching. A mixed cart is expressible in the schema from day one.

**5 · Server-enforced entitlements.** `enabled_modules` is read from the database on every request,
never from a token claim — so disabling a module takes effect immediately rather than at token
expiry. Client-side gating is UX; a modified APK skips it.

## The retail till

Four screens — catalog, cart, checkout, receipt — and the plan is explicit about resisting a
fifth. What matters is what is *absent* from them.

**No network call in the checkout path, not even an optimistic one.** Checkout is one local
SQLite transaction and then the cashier is free. The outbox drains afterwards and its success
is invisible: there is no spinner, no "syncing…", no retry button. Sync appears in exactly one
place, a small `3 pending` chip in the app chrome.

**Receipt numbers are generated locally**, `T1-0042`, from a per-device prefix assigned at
registration. Server-assigned numbering would hand a customer a receipt reading "pending"
until the shop's wifi returned.

**Cash rounds to the nearest 5 sen; the order does not.** The order records the exact
arithmetic total and the ledger records the money that actually moved — two different facts,
kept apart so a receipt and a ledger entry never sit a few sen apart with nothing to explain
why. Card and QR are never rounded.

**A void is a manager PIN on the till and a role check on the server.** The till caches a
bcrypt PIN hash for managers and owners only, so a void works at the counter while offline.
That gate is UX — a rebuilt APK skips it — so the server re-reads the authorising user's role
when the reversal syncs and rejects it outright if they are not a manager or owner.

## Known limitations

Deliberate, and documented rather than fixed: single store per account · flat one-level recipes
(no sub-recipes) · no ingredient costing or COGS · no payment processing, method recorded only ·
no loyalty, gift cards, or promotions · no multi-currency · no table management or open tabs ·
no held carts, split payments, or tipping · no physical printing · mixed-type carts supported by the
schema but not built in the UI · **clock skew unmitigated** — `occurred_at` comes from an untrusted
client clock, so anything financial orders by the server's `recorded_at` instead.
