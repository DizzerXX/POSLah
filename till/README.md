# POSlah Till (Flutter)

Placeholder. The Flutter application is scaffolded in **step 3** (`docs/poslah-retail-till-plan.md`),
with the local SQLite store and sync outbox arriving in **step 2** (`docs/poslah-sync-engine-plan.md`).

This directory exists now so the monorepo layout is complete. It is deliberately empty of code:
`flutter create` has not been run, and nothing here has been compiled. Committing a hand-written,
never-built Flutter scaffold would be committing a guess.

## What lands here

| Step | Content |
|---|---|
| 2 | `drift`/`sqflite` local mirror of the server schema, `sync_outbox`, push/pull client — drivable from tests, no UI |
| 3 | Retail till loop: catalog, cart, checkout, receipt |
| 4 | `PosModule` registry, `RetailModule` / `FnbModule` |
| 5 | BOM expansion via the revised `StockStrategy` |

## Non-negotiables when this is built

Carried from `CLAUDE.md`, repeated here because they are easy to violate at the UI layer:

- Money is `int` minor units end to end. No `double`, no `Decimal` in the UI layer.
- IDs are client-generated UUIDv7, minted locally before any network call.
- Checkout writes to local SQLite in one transaction and returns. **No network call in the checkout path.**
- Cart totals are derived on read, never stored in state.
- Stock hints are advisory and never block adding to cart.
