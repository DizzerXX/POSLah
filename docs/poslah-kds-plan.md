# POSlah — KDS & Order State Machine Plan (v1)

**Depends on:** all prior plans
**Position in build order:** step 6 of 6 — final step

> This is the most demo-able step and the least architecturally load-bearing. Build it last for exactly that reason: if the project runs out of runway before this point, you still have a defensible portfolio piece. If you had built it first, you would have a pretty demo sitting on no foundation.

---

## The state machine

One machine, both verticals. It collapses rather than branches.

```
created → sent → preparing → ready → paid
   └──────────────────────────────────→ paid     (no routed lines)

any state → voided                                (manager PIN)
```

**Transition rules:**

| From | To | Trigger | Guard |
|---|---|---|---|
| `created` | `paid` | Checkout | No line has `fulfillment_type = routed` |
| `created` | `sent` | Checkout | ≥1 routed line |
| `sent` | `preparing` | Kitchen starts | — |
| `preparing` | `ready` | All routed lines marked ready | Per-line, order-level derived |
| `ready` | `paid` | Payment taken | — |
| any | `voided` | Void | Manager PIN, server-verified |

**Order-level status is derived from line-level status, not set independently.** An order is `ready` when every routed line is `ready`. Storing order status as an independent mutable field alongside line statuses creates two sources of truth that will disagree — the same class of bug as storing derived cart totals in state.

Store `orders.status` as a materialised value for query performance, but compute it from lines on every transition. Never set it directly from a UI action.

**Payment timing differs by vertical and the machine already handles it:**
- Retail: pay at checkout → `created → paid` immediately
- F&B quick-service: pay at counter, then kitchen → `created → sent`, `paid` set at checkout, fulfilment continues after
- F&B table-service: fulfil then pay → `ready → paid`

> v1 supports pay-first (quick-service). Pay-last table service needs open tabs, which needs table management — out of scope, locked. The state machine permits it; the UI does not build it.

---

## Reverb channel design

**Private channels, authorised server-side.**

| Channel | Scope | Subscribers |
|---|---|---|
| `private-store.{storeId}.kitchen` | Per store | KDS screens |
| `private-store.{storeId}.tills` | Per store | All till devices |
| `private-account.{accountId}.backoffice` | Per account | Backoffice sessions |

**Channel authorisation must check module entitlement, not just authentication.** A retail-only account subscribing to a kitchen channel is a 403 at the broadcast auth endpoint. Same principle as step 4's API middleware — enforcement lives on the server, and the check reads the DB, not a token claim.

**Events:**

| Event | Channel | Payload |
|---|---|---|
| `OrderSentToKitchen` | kitchen | Order id, number, routed lines only, placed_at |
| `OrderLineStatusChanged` | kitchen + tills | Line id, order id, new status |
| `OrderStatusChanged` | tills + backoffice | Order id, derived status |
| `StockExceptionRaised` | backoffice | Exception id, stockable, resulting quantity |

**Routed lines only in the kitchen payload.** A kitchen screen has no business displaying a bag of coffee beans someone bought alongside their latte. This is the first place mixed orders become concretely visible even though v1's UI cannot create them — the payload filter is written now, correct from the start, and costs nothing.

---

## KDS client

**A web page, not a second app.** Angular, same backoffice codebase, separate route, kiosk-friendly layout. Reasons: kitchens run cheap tablets or repurposed monitors, a browser is universally available, and a second Flutter app would double the release surface for a read-mostly screen.

**Screen:**
- Ticket cards in a column layout, oldest first
- Each card: order number, elapsed timer, routed lines with modifiers
- Tap a line → toggle `ready`
- Card auto-clears when all lines are ready
- Elapsed timer changes colour past a configurable threshold

**Explicitly not building:** drag-and-drop, station routing (grill vs fryer), course timing, bump bars, recall of cleared tickets. All real KDS features, all scope creep here.

---

## The one hard problem in this step

**WebSocket delivery is not guaranteed, and a missed ticket is a customer waiting for food that nobody is cooking.**

Reverb over WebSockets will drop messages on network blips, tab suspension, or server restarts. A KDS that only listens to the socket will silently miss orders. This is the failure mode that matters, and it is invisible in testing because local development never drops a connection.

**Mitigation — three layers:**

1. **Socket as the fast path.** Events arrive in real time under normal conditions.
2. **Polling reconciliation as the safety net.** Every 30s, `GET /api/v1/kitchen/active-orders`. Reconcile against local state — anything present server-side but missing locally is rendered. This makes the socket an optimisation rather than a dependency.
3. **Full refetch on reconnect.** On socket reconnect, discard local state and refetch. Do not attempt to replay missed events.

> This mirrors the till's design stance: the network is unreliable, so no correctness-critical behaviour may depend on a message arriving. The till solved it with a local-first outbox; the KDS solves it with polling reconciliation. Same principle, different mechanism, and worth saying so in the README — a consistent stance on unreliability across two subsystems reads as design, whereas each fix in isolation reads as patching.

**Polling frequency is a real trade-off, not a free safety net.** 30s means a worst-case 30s delay on a dropped ticket. Faster polling costs server load and mostly returns unchanged data. 30s is a judgement call for a portfolio project; a real kitchen would want 10s and would care about the load. [Guessing on the exact right number — it depends on kitchen tempo, which I have no basis to estimate]

---

## Offline behaviour

The till is local-first and keeps selling when the network drops. **The KDS cannot be** — it is inherently a live view of other devices' state, and there is nothing meaningful to show offline.

When the KDS loses connection: display a prominent disconnected banner and freeze. Do not hide the state, do not show stale tickets as if current. A kitchen screen that looks normal while showing five-minute-old data is worse than one that visibly says it is broken.

**F&B orders still complete offline on the till** — they sync, and appear on the KDS on reconnect, late. The elapsed timer will show a large number, which is correct and informative rather than a bug.

---

## Definition of done for step 6

- F&B order placed on till appears on KDS within ~1s
- Marking a line ready reflects on the till without refresh
- All lines ready → order status derives to `ready` → card clears
- Retail order never appears on the KDS
- **Kill the WebSocket mid-service: polling recovers missed tickets within 30s**
- KDS disconnected → banner shown, no stale tickets presented as live
- Retail-only account cannot subscribe to the kitchen channel — verified at the broadcast auth endpoint
- Order placed offline on till → appears on KDS after sync, timer showing true elapsed
- Order-level status is never set directly; always derived from lines

The fifth item is the one worth automating. It is the failure this step exists to handle, and it is the one a reviewer will not expect to find tested.

---

## v1 complete — remaining known limitations

Collect these in the README. Stated limitations with reasoning read as judgement; the same gaps unstated read as oversights.

- Clock skew unmitigated; financial ordering uses server clock
- Flat recipes only, no sub-recipes
- No ingredient costing / COGS
- Single store per account
- No payment processing, method recorded only
- No physical hardware — printing is PDF export
- No mixed-type carts in the UI (schema supports them)
- No held/parked carts, no split payments, no tipping
- No table management or open tabs
- KDS polling floor of 30s on socket failure
- Outbox unbounded — no compaction for long-offline devices

---

## What to write in the README, in order

The repo will be skimmed, not read. Front-load the reasoning:

1. **What it is** — one paragraph, both verticals, one codebase
2. **The five architecture decisions and why** — client-generated UUIDv7, accept-both stock conflicts, append-only ledger, item-level `fulfillment_type`, server-enforced entitlements
3. **The sync engine** — local-first, idempotent push, per-entry results
4. **Known limitations** — the list above, with reasoning
5. **Test scenarios covered** — name the offline and failure cases explicitly

The test scenario list does more work than the code tour. A reviewer who sees "two tills sell the last unit offline" and "kill the WebSocket mid-service" named as test cases already knows what kind of engineer wrote it.
