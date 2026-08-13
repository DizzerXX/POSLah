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
| 2 | Sync engine — outbox, push/pull, reconciliation | — |
| 3 | Retail till loop (Flutter) | — |
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

## Known limitations

Deliberate, and documented rather than fixed: single store per account · flat one-level recipes
(no sub-recipes) · no ingredient costing or COGS · no payment processing, method recorded only ·
no loyalty, gift cards, or promotions · no multi-currency · no table management or open tabs ·
no held carts, split payments, or tipping · no physical printing · mixed-type carts supported by the
schema but not built in the UI · **clock skew unmitigated** — `occurred_at` comes from an untrusted
client clock, so anything financial orders by the server's `recorded_at` instead.
