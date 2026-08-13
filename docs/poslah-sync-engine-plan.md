# POSlah — Sync Engine Plan (v1)

**Depends on:** `poslah-schema-plan.md`
**Position in build order:** step 2 of 6 — after schema, before the retail till loop

> This is the hardest subsystem in the project and the one that carries the most portfolio signal. Build it first, build it properly, and everything downstream is comparatively routine.

---

## Design premise

The till is **local-first, not offline-fallback.** Every sale writes to local SQLite and returns to the cashier immediately, whether or not the network is up. The server is a replica the till pushes to, never a dependency in the checkout path.

This is a deliberate inversion of the usual "try the API, fall back to a queue" pattern. Reasons:

- A fallback path that only runs when the network drops is a code path that is almost never exercised and therefore almost always broken. Local-first exercises the same path on every single sale.
- Checkout latency becomes independent of network quality — a real merchant-facing benefit, not just an engineering nicety.
- Testing gets dramatically simpler: there is one write path, not two.

**Cost of this choice:** the till must own conflict semantics, and the server can never be the source of truth for stock at the moment of sale. Accepted — that trade was already locked when we chose "accept both, allow negative."

---

## Local store (Flutter till)

SQLite via `drift` or `sqflite`. Mirror of the server schema, minus backoffice-only tables (no `stock_exceptions`, no `time_entries` reporting views).

**Local-only columns added to syncable tables:**

| Column | Purpose |
|---|---|
| `sync_status` | `pending` \| `in_flight` \| `synced` \| `rejected` |
| `sync_attempts` | Integer, drives backoff and the stuck-record alert |
| `last_sync_error` | Nullable text, surfaced in a debug screen |

**Outbox table** — `sync_outbox`:

- `id` (uuidv7, pk)
- `entity_type` (`order` | `ledger_entry` | `stock_movement` | `time_entry` | `customer`)
- `entity_id` (uuid — points at the local row)
- `operation` (`create` | `update`) — v1 has no `delete`; the ledger is append-only and catalog edits come *down*, not up
- `payload` (json snapshot at enqueue time)
- `created_at`, `sync_status`, `attempts`, `last_error`

> **Why a payload snapshot rather than re-reading the row at send time:** the row could be mutated between enqueue and send (an order goes `created → paid`). Snapshotting per-operation makes each outbox entry a self-contained, replayable fact. This is the same reasoning as the `name_snapshot` columns in the schema — capture the state at the moment it mattered.

---

## Push protocol (till → server)

### Batching

- Drain the outbox in `created_at` order, batches of up to 50 entries.
- **Order matters within a batch** — an `order` must land before its `ledger_entry`. Sort by dependency depth, not just timestamp, or rely on the server processing the batch inside one transaction with deferred FK checks. Prefer the former; explicit beats clever.
- One in-flight batch at a time per device. No parallel batches — parallelism here buys nothing and makes ordering bugs non-deterministic.

### Endpoint

```
POST /api/v1/sync/push
{
  "device_id": "<uuid>",
  "batch_id": "<uuidv7>",
  "entries": [ { "id", "entity_type", "entity_id", "operation", "payload" } ]
}
```

**Response:**

```
{
  "batch_id": "<uuidv7>",
  "results": [
    { "entry_id": "<uuid>", "status": "accepted" },
    { "entry_id": "<uuid>", "status": "duplicate" },
    { "entry_id": "<uuid>", "status": "rejected", "reason": "validation", "detail": "..." }
  ],
  "server_time": "<iso8601>"
}
```

**Per-entry results, not batch-level pass/fail.** One malformed entry must not block 49 valid sales. A batch-level failure means a poison entry can wedge the queue forever — the single most common way naive sync engines die in production.

### Idempotency

Every write is `INSERT ... ON CONFLICT (id) DO NOTHING`. Because IDs are client-generated UUIDv7, re-sending is inherently safe. A `duplicate` result is a **success**, not an error — the till marks the entry synced and moves on.

This means the till never needs to ask "did that go through?" after a timeout. It just resends. That's the entire point of client-generated IDs.

### Retry & backoff

- Exponential backoff: 2s, 4s, 8s, 30s, 2m, 10m, capped at 15m.
- Retry on: network error, 5xx, 429, timeout.
- Do **not** retry on 4xx validation errors — mark `rejected`, surface in the debug screen, keep going. A rejected entry is a bug to fix, not a transient failure to wait out.
- After 10 attempts, raise a local alert ("3 sales haven't synced since 2:14pm"). Silent permanent failure is worse than a noisy one.

### Trigger conditions

Attempt a drain on: app foreground, connectivity regained (`connectivity_plus` listener), every completed sale, and a 60s periodic timer. Cheap when the outbox is empty; the timer is the safety net for missed connectivity events.

---

## Pull protocol (server → till)

Catalog, prices, staff, and config flow **downward only**. The till never edits a product.

```
GET /api/v1/sync/pull?since=<iso8601>&device_id=<uuid>
```

Returns changed rows for `products`, `product_variants`, `modifiers`, `categories`, `ingredients`, `recipe_lines`, `users`, `stores`, plus the account's `enabled_modules`.

- Cursor is `updated_at`, stored locally as `last_pull_at`.
- Server returns its own `server_time` in the response; the till uses **that** as the next cursor, never its own clock. Prevents skew from silently skipping records.
- Soft-deleted / deactivated rows must appear in the delta with `is_active: false` — otherwise a discontinued product lives forever on the till. This is the classic incremental-sync bug.

**Stock levels are deliberately excluded from pull.** The till's local stock number is advisory (used for low-stock hints in the UI), never authoritative, and never blocks a sale. Pulling stock down would create the illusion of authority it doesn't have.

---

## Reconciliation flow — the stock path

This is where the "accept both, allow negative" decision gets implemented.

**On the till (at sale time):**

1. Write `order` + `order_lines` + `ledger_entry` locally.
2. Compute stock deltas locally:
   - `direct` items → `product_variant` delta
   - `routed` items → expand `recipe_lines`, produce one `stock_movement` per ingredient
3. Write `stock_movement` rows locally. Decrement local `stock_levels` for UI purposes only.
4. Enqueue everything to the outbox.
5. **Return to the cashier.** Total elapsed: local SQLite writes, no network.

> Recipe expansion happens **on the till, not the server.** Reason: the recipe could change server-side between the sale and the sync, and the movement must reflect the recipe as it was when the burger was made. Same snapshot principle as everywhere else in this design.

**On the server (at sync time):**

1. Insert `stock_movement` rows unconditionally, `ON CONFLICT DO NOTHING`.
2. Recompute `stock_levels.quantity_on_hand` for each affected stockable — `SUM(delta)` over movements, inside a transaction with a row lock on the `stock_levels` row.
3. If the result is `< 0`:
   - Upsert an `open` row in `stock_exceptions` with the resulting quantity
   - Append the contributing `order_id`s
   - **Do not reject anything.** The sale happened.
4. Broadcast the exception to the backoffice (Reverb) so it appears without a refresh.

**In the backoffice:** an exceptions view listing negative stock, which orders contributed, which devices, and when. The merchant resolves by doing a stocktake adjustment (`manual_adjustment` movement) — which is itself just another append, closing the loop.

---

## Testing plan

The sync engine is the one subsystem where "it worked when I tried it" is not evidence. Scenarios to cover explicitly:

| Scenario | Expected |
|---|---|
| 3 sales offline → reconnect | All 3 sync, stock reconciles, no duplicates |
| Same batch sent twice | Second returns all `duplicate`, no double-decrement |
| Kill app mid-batch | On relaunch, `in_flight` entries revert to `pending`, resend safely |
| Two tills sell last unit offline | Both accepted, stock = -1, one exception raised |
| Poison entry in batch | Other 49 succeed, poison marked `rejected`, queue keeps draining |
| Product deactivated server-side | Disappears from till after next pull |
| Clock set 3h back on till | `occurred_at` is wrong, `recorded_at` correct, financial ordering unaffected |
| Reconnect mid-batch | Partial batch is safe to fully resend |

Automate at least the first six. This test suite *is* the portfolio artifact — a reviewer skimming your repo learns more from seeing these cases named than from reading the implementation.

---

## Known limitations (document, don't fix)

- **Clock skew is unmitigated.** `occurred_at` is untrusted. Financial ordering uses `recorded_at`. A till with a badly wrong clock produces misleading day-boundary reports. Fixing this properly needs a server-time handshake and drift correction — out of scope, and honestly out of scope for most real POS systems too.
- **No conflict resolution for concurrent catalog edits.** Catalog is server-authoritative and download-only; last write in the backoffice wins. Not a real limitation at this scope.
- **No compaction of the outbox.** A till offline for a month accumulates unbounded local rows. Acceptable for v1; a real product would need archival.
- **Negative stock has no automatic remedy.** By design — resolution is a human stocktake, not an algorithm.

---

## Decision still open from the schema plan

**`order_number` assignment** — this blocks the till UI in build step 3. Recommendation: per-device prefix assigned at device registration (`T1-0042`, `T2-0018`). Add `number_prefix` and `next_sequence` to the `devices` table. Server-assigned numbering would leave offline receipts numberless until reconnect, which is unacceptable for a printed receipt a customer walks away with.

Settle this before starting the till loop.
