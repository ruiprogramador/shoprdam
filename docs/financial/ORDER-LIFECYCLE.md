# Order Lifecycle — shoprdam

Canonical description of what exists after `feat/order-lifecycle`. Everything
here is either evidenced by production code, a database constraint, or a test
cited by name. Where a state or rule is **not** implemented, this document says
so and says why, rather than describing an aspiration as fact.

See also: `INVARIANTS.md` (`ORDER-XX`), `STATE-MACHINES.md`, `MONEY-FLOWS.md`,
`ARCHITECTURE.md` §10.

## 1. Scope

This branch formalizes the Order lifecycle **that already existed** — four
states, one writer — behind a single canonical transition boundary, keeps it
strictly separate from Payment and Wallet state, and stops there. It does not
invent fulfillment states, cancellation, or refund-initiation, because the
product does not yet define them (§3).

## 2. Audit — what existed before this branch

Verified by grepping the whole of `app/`, `routes/`, `database/`, `resources/js`
and `tests/`, not by trusting the obvious call sites.

**States in the database** (`order_statuses`, seeded by `OrderStatusSeeder`;
slugs "must never be changed in production"): `pending`, `paid`, `failed`,
`refunded`. **All four are produced by production code.** No dormant states.

**Production writers of an Order's status** — exactly one:

- `PaymentEventProcessor::markSettled()` — a bare `$order->update([...])`:
  no state check, no row lock, no compare-and-set. Reached from
  `applySucceeded()` (→ `paid`), `applyFailed()` (→ `failed`) and
  `applyRefunded()` (→ `refunded`, full refunds only).
- Creation only: `CreateTestStripeOrder` (a manual, non-production test tool,
  creates at `pending`) and `OrderFactory`. **No production code creates an
  Order at all** — there is no checkout flow yet.

No controller, job, command, listener, or admin/vendor/customer action changes
an Order's status. `OrderPolicy` has only `view` and `pay`.

**Original transition graph** (from the code and `CrossProviderFailoverTest`):

```
pending ──► paid ──► refunded
   │         ▲
   └──► failed ┘        (failed ──► paid: a later attempt succeeds)
```

`paid → failed`, `refunded → *` and the like were merely *unreachable* through
`PaymentService` gating — nothing enforced their absence.

**What does not exist:** fulfillment/shipping/delivery columns or states;
cancellation; any Order event, listener or notification (the six existing
events are all KYC); lifecycle timestamps (only `created_at`/`updated_at`); a
buyer identity (`OrderPolicy`: an Order "only belongs to a Store"); order
items. `resources/js/Components/Dashboard/OrdersList.vue` shows
`Pending/Cancelled/Completed/Processing`, but it is **hardcoded mock data**
(`toDo: substituir por defineProps`), not evidence of backend states.

## 3. Proposed states that did NOT survive the audit

The candidate `pending_payment → paid → accepted → preparing → ready →
completed` (+ `cancelled`) was treated as a hypothesis. Only `pending` (=
`pending_payment`) and `paid` map onto existing states. **No state was created,
renamed or deleted to fit the diagram.**

| Proposed | Verdict | Why it cannot be defined safely today |
|---|---|---|
| `accepted` / `preparing` / `ready` / `completed` | **Not implemented** | No order items, no buyer, no fulfillment actor. `OrderPolicy` treats the *vendor* as the payer of their own Order, so "who accepts, and what?" is undefined. |
| `cancelled` | **Not implemented** | No cancellation producer or product rule; and cancelling a `paid` Order would require initiating a refund, for which no canonical operation exists (§6). Cancelling an unpaid Order with an in-flight attempt could race a provider payment that can't be cancelled through `PaymentProviderContract`. |
| `failed` as a *terminal* verdict | **Rejected** | Contradicts established, tested behavior (§5). |

## 4. The state machine

`App\Domain\Orders\Enums\OrderLifecycleState` — a typed view over the existing
`order_statuses` slugs (which remain the source of storage).

| From \ To | `pending` | `paid` | `failed` | `refunded` |
|---|:-:|:-:|:-:|:-:|
| `pending` | — | ✅ | ✅ | ❌ |
| `paid` | ❌ | (no-op) | ❌ | ✅ |
| `failed` | ❌ | ✅ | (no-op) | ❌ |
| `refunded` | ❌ | ❌ | ❌ | (no-op, terminal) |

- **Terminal:** only `refunded`.
- **Owner of every transition:** `App\Domain\Orders\Services\OrderLifecycleService`.
- **Authorization:** each transition requires the Order's own `sale` Wallet
  transaction as evidence, re-read from the database:

| Transition | Evidence required |
|---|---|
| `→ paid` | the sale is `completed` |
| `→ failed` | the sale is `failed` |
| `→ refunded` | the sale is `completed` **and** has a `completed` `customer_refund` reversal |

  The evidence must be an original `sale` (not a reversal) that references
  *this* Order. "References" is **morph-aware model identity**: the sale's
  polymorphic `referenceable` is resolved the way Eloquent itself resolves it
  (honoring a morph map if one is ever configured) and compared to the Order —
  never the raw stored `referenceable_type` string, which is a class name on
  every historical row and could be a morph alias on later ones. A reference that
  resolves to no `Order` at all is refused as an ordinary refused transition.
  Historical financial rows are never rewritten to make this work. (An earlier
  draft compared the raw string, which would have refused valid settlements the
  moment a morph map appeared while legacy rows existed; that is fixed and
  regression-tested in `OrderLifecycleServiceTest` and
  `OrderPaymentIntegrationTest`.) Only `WalletTransactionService` can create such
  rows, so arbitrary code cannot fabricate them: an Order cannot become `paid`
  because someone asked — only because the Wallet says the payment settled.
- There is deliberately **no** `setStatus($anything)`. The three public methods
  are `markPaid`, `markPaymentFailed`, `markRefunded`.

## 5. Payment → Order (and what stays separate)

Direction of authority is always **financial settlement → allowed Order
transition**, never the reverse. `PaymentEventProcessor::markSettled()` calls
the lifecycle service *after* it has settled the Wallet, inside the same
`DB::transaction`. The Orders domain has no dependency on the Payments domain
(no `Payment`, `PaymentStatus`, `PaymentAttempt`, provider events — asserted by
`OrderLifecycleBoundaryTest`) and reads Wallet rows only as evidence.

**PaymentAttempt failure** (`PaymentEventProcessor::applyFailed()`, unchanged):
the attempt becomes `Failed`; the **Payment stays `Pending`** and payable; the
Order becomes `failed`. `failed` therefore means *"the latest payment attempt
failed"* and is **non-terminal**: a later attempt (another provider/method) that
succeeds moves it `failed → paid`
(`OrderPaymentIntegrationTest`, `CrossProviderFailoverTest`). The name `failed`
is a legacy of the seeded slug; it is not a verdict on the Order.

**If a transition is refused** (the Order's state contradicts the financial fact
being settled — only possible from corrupt/inconsistent data), the exception
propagates out of `markSettled()` and **rolls the Wallet mutation back too** —
the same fail-closed precedent `PaymentAttemptNotFoundException` set. A
webhook that hits this returns an error and is retried; nothing half-applies.
This is a deliberate choice, with a cost: an inconsistent Order blocks
settlement until a human repairs it (see §11).

**Why roll back, rather than "settle the Wallet and skip the Order"?** Because
`confirm()`, `markFailed()` and `reverse()` are one-shot: once the Wallet
mutation commits, any redelivery or replay hits `TransactionNotPendingException`
/ `TransactionAlreadyReversedException` and never reaches `markSettled()` again
(`PaymentEventProcessor::applySucceeded()`'s own comment states this). A commit
that skipped the Order transition could therefore never be repaired — the Order,
the Payment and the attempt would all stay stuck — whereas a rollback leaves the
pre-settlement state intact and retryable. `OrderPaymentIntegrationTest` proves
the rollback side: the refusal leaves a stored event `pending` with everything
unchanged, and once the Order is repaired the very same replay settles. Note
that it is the transition **matrix**, not the Wallet evidence, that refuses a
corrupt Order — so removing Wallet knowledge from the Orders domain would not
change this behavior.

**Blast radius.** A corrupt Order is reachable only through manual edits or
pre-`feat/order-lifecycle` history, never through any code path. For a
stored/replayed event, recovery is automatic once the Order is repaired (the
reconciler retries the stale event; `payments:health` surfaces repeated replay
failures). For a *live* webhook nothing local is stored, so recovery depends on
the provider redelivering; once it stops, the attempt stays `Claimed` and Payments
reconciliation flags it (`RemoteSucceededNoSettlementPath`) — the documented
no-poll-settlement gap, which this branch does not close. Run the pre-deploy
consistency check (§13) before rollout.

## 6. Cancellation and refund semantics

**Cancellation: not supported.** There is no `cancelled` state or producer.
`cancelling an Order ≠ refunding a Payment`, and the app cannot initiate a
refund at all — refunds arrive only as provider events. Anything that would
require one fails closed by not existing.

**Refunds — current behavior, preserved and formalized:** a **full** refund
(the only kind supported; partial refunds are still ignored by
`PaymentEventProcessor::applyRefunded()`) moves a `paid` Order to `refunded`,
which is terminal. The refund is a Wallet fact first (`reverse()`); the Order
transition follows. EasyPay refunds remain unsupported. Whether a refund should
*replace* the Order's lifecycle state at all, rather than be orthogonal
financial information on a still-fulfilled Order, is an **open product
decision** (§11) — it only becomes meaningful once fulfillment states exist.

## 7. Concurrency and idempotency

One transaction per transition: `SELECT … FOR UPDATE` on the Order row →
re-read the **current** state under the lock (a caller's stale instance is
never used to decide) → validate evidence and matrix → compare-and-set
`UPDATE … WHERE order_status_id = <validated state>`. A CAS that matches zero
rows throws `OrderTransitionConflictException` (fail-closed, nothing written).
The lock serializes on MySQL/PostgreSQL; the CAS is the portable backstop
(SQLite's `lockForUpdate()` is a no-op).

**Idempotency:** repeating a transition into the state the Order is already in
is an explicit no-op (`OrderTransitionResult::$changed === false`): no
timestamp rewrite, no event. Everything else the matrix forbids throws
`InvalidOrderTransitionException`.

**Limitation:** the lock/CAS tests are sequential simulations on SQLite. No
test runs two genuinely concurrent connections on MySQL/PostgreSQL — the same
limit `INVARIANTS.md` states for every other lock-backed invariant.

## 8. Timestamps and creation

`orders.paid_at` and `orders.refunded_at` (nullable, additive migration
`2026_09_18_090000`), written only by the lifecycle service in the same UPDATE
as the status. No `failed_at` (re-enterable, so no stable meaning) and nothing
for non-existent states. Historical rows keep `NULL`: nothing records when they
actually transitioned, and deriving it from `updated_at` would fabricate history.

**Creation is not constrained.** `Order` has no production creator yet, so the
initial status is unguarded (a test-only tool and factories create Orders). The
architecture test pins the one tool to `pending`. Enforce "orders start
`pending`" when a real creation path is built.

## 9. Domain events

One event: `OrderTransitioned(orderId, from, to, occurredAt)` — emitted once per
transition that **changed** state, never for a no-op or a refused transition;
dispatched via `DB::afterCommit`, i.e. only after the **outermost** transaction
commits (so a rolled-back settlement emits nothing — proven through the real
`PaymentEventProcessor`, including a failure *after* the Order transition and a
failure at the Payment-update step); carries scalars only. **No listener
exists.** It is an extension point, not a behavior.

**Delivery semantics — at-most-once, not durable delivery.** The transition
commits first. If the process dies between that commit and the after-commit
callback, the event is lost; there is no outbox. Never rely on it to make
anything happen — re-derive from the Order's persisted state.

**A synchronous listener that throws does so AFTER the durable commit.** Laravel
runs the after-commit callbacks outside the commit's try/catch: the exception
reaches the caller (so a webhook would return an error and be retried), but
nothing is rolled back — the Order, Wallet, Payment and attempt are all already
committed, and the retried delivery is an idempotent no-op that emits no second
event. Proven by `OrderPaymentIntegrationTest`. Listeners must be idempotent,
should be queued, and must never call back into a financial path.

## 10. Enforcement

- **Runtime — `App\Models\Order::performUpdate()`.** Any Eloquent save of an
  existing Order that changes `order_status_id` throws. It guards `performUpdate()`
  rather than the `updating` model event because `saveQuietly()`,
  `updateQuietly()` and `Order::withoutEvents(...)` suppress events but still go
  through `performUpdate()` (an earlier event-based guard was bypassable that
  way — demonstrated, fixed, and now covered by a per-style test:
  `update`, `forceFill`, `fill`, attribute assignment, `saveQuietly`,
  `updateQuietly`, `withoutEvents`, `associate`+`save`/`saveQuietly`, `push`).
  A mixed update throws before any column is written; every non-status update
  (including the quiet variants) still works. The service's query-builder
  compare-and-set never touches a model instance, so it is unaffected.
- **Static — `tests/Architecture/OrderLifecycleBoundaryTest`.** Comment-stripped
  scans over **production code**: `app/`, `routes/`, `bootstrap/` (excluding the
  generated `bootstrap/cache`), `config/` and `database/seeders/`. They assert:
  only the service, the `Order` model and the test-only creation tool mention
  `order_status_id`/`OrderStatus::`; nothing writes the `orders` table via the
  query builder or raw SQL; only `PaymentEventProcessor` calls a transition; the
  Orders domain has no Payments-domain or Wallet-write dependency; and the Orders
  domain contains **exactly one** write-shaped call — the canonical
  compare-and-set `Order::query()…->update()` — so reading Wallet evidence can
  never quietly become writing it (`$sale->update()`, `->delete()`,
  `saveQuietly()` etc. are all flagged).
- **The scanners are themselves tested.** Their detectors are pure functions, and
  permanent self-tests feed them synthetic violations in every scanned root to
  prove they fire. In addition, planting a real violating file in `routes/`,
  `config/`, `bootstrap/`, `database/seeders/` and the Orders domain makes the
  corresponding scan fail (and a file in `bootstrap/cache` is correctly ignored).
- **Outside the boundary on purpose:** migrations, factories and tests create rows
  and place fixtures in precondition states.
- **What none of this proves.** A status column or table name assembled at
  runtime, or raw SQL built from parts, evades a text scan, and no runtime guard
  sees a write that never touches a model instance. Order *creation* is
  unconstrained (§8). Ad-hoc code run outside the repository (tinker, `psql`
  against production) is beyond any test. The Wallet-evidence check is
  defence in depth against such callers, not a substitute for the above.

## 11. Decisions left open (deliberately not invented)

1. **Fulfillment states** (`accepted/preparing/ready/completed`): need a buyer,
   items, a fulfillment actor and ownership rules.
2. **Cancellation:** which states allow it; whether a `paid` cancellation must
   trigger a refund (no canonical refund-initiation path exists); how to handle
   an unpaid Order with an attempt in flight (no provider-cancel capability).
3. **Refund vs. lifecycle:** keep `paid → refunded` as a lifecycle state, or make
   refund orthogonal financial information (`ORDER-09`).
4. **The `failed` name:** keep the legacy slug, or rename/remap (slugs are
   frozen; a rename is a data migration and a product call).
5. **Initial-state enforcement** when Order creation is built.
6. **Recovery from an inconsistent Order** that blocks settlement (§5): an
   operator repair path does not exist — repair today is an out-of-band database
   edit. Detection *before* it bites is §13; a repair tool is out of scope.

## 12. Invariants

Mirrored in `INVARIANTS.md` only where a named test proves them.

| ID | Statement | Status |
|---|---|---|
| ORDER-01 | Every status change of an existing Order goes through the canonical boundary | **ENFORCED** — runtime `performUpdate()` guard (every Eloquent style incl. `saveQuietly`/`updateQuietly`/`withoutEvents`/`associate`; `OrderLifecycleServiceTest`) + static scans of production roots with self-tests (`OrderLifecycleBoundaryTest`). Limits (§10): runtime-assembled names/raw SQL, unconstrained creation (§8), out-of-repo ad-hoc code |
| ORDER-02 | Only explicitly allowed transitions occur | **ENFORCED** — `OrderLifecycleStateTest` (all 16 pairs), `OrderLifecycleServiceTest` |
| ORDER-03 | A terminal state never returns to an active one | **ENFORCED** — `refunded` |
| ORDER-04 | Payment and Order remain separate machines | **ENFORCED** — Orders domain has no Payments dependency (architecture test) |
| ORDER-05 | An Order becomes `paid` only from a settled sale, via the canonical path | **ENFORCED** — morph-aware evidence checks (`OrderLifecycleServiceTest`) + only `PaymentEventProcessor` may call (`OrderLifecycleBoundaryTest`) |
| ORDER-06 | Attempt failure never terminalizes a payable Order | **ENFORCED** — `failed` non-terminal; `OrderPaymentIntegrationTest` |
| ORDER-07 | Cancellation obeys explicit rules | **NOT SUPPORTED** — no cancellation exists |
| ORDER-08 | Cancellation never implies a refund | **NOT SUPPORTED** — no cancellation exists |
| ORDER-09 | Refund info must not arbitrarily replace the lifecycle | **OPEN DECISION** (§6, §11) — a full refund currently *does* set `refunded` |
| ORDER-10 | A repeated transition is an explicit no-op or an error | **ENFORCED** |
| ORDER-11 | Concurrent transitions cannot yield an impossible state | **ENFORCED (lock/CAS)**; true parallelism **unproven** (§7) |
| ORDER-12 | Order lifecycle code never mutates Wallet state | **ENFORCED** — architecture test + `OrderLifecycleServiceTest` |

## 13. Operational: pre-deploy consistency check

**Run this read-only check against production data (ideally a replica) before
deploying this branch.** The lifecycle boundary refuses a transition whose
current Order state contradicts the financial fact being settled (§5). No code
path produces such Orders — but the pre-branch code wrote Order status
unconditionally, and manual edits are possible, so historical data may hold
one. Finding it now is far cheaper than discovering it as a failing webhook.

Every query below **must return zero rows**. They only `SELECT`; nothing here
repairs anything. They were validated against the real schema: zero rows on a
consistent dataset covering every state, and each query detecting its own
inconsistency.

- **Q1** an Order still `pending`/`failed` whose sale already settled;
- **Q2** an Order `paid` with no completed sale;
- **Q3** an Order `refunded` with no completed `customer_refund` reversal;
- **Q4** an Order `paid` although its sale was fully refunded;
- **Q5** Order and Payment disagree;
- **Q6** an Order in a status the lifecycle does not know.

```sql
-- Q1: an Order still `pending`/`failed` although its sale already settled (should be paid or refunded)
SELECT o.id AS order_id, os.slug AS order_status
FROM orders o
JOIN order_statuses os ON os.id = o.order_status_id
WHERE os.slug IN ('pending', 'failed')
  AND EXISTS (
    SELECT 1 FROM store_wallet_transactions t
    JOIN transaction_categories c ON c.id = t.transaction_category_id AND c.slug = 'sale'
    JOIN transaction_statuses ts ON ts.id = t.transaction_status_id AND ts.slug = 'completed'
    WHERE t.referenceable_type = 'App\Models\Order' AND t.referenceable_id = o.id
      AND t.related_transaction_id IS NULL
  );

-- Q2: an Order `paid` with no completed sale behind it
SELECT o.id AS order_id, os.slug AS order_status
FROM orders o
JOIN order_statuses os ON os.id = o.order_status_id
WHERE os.slug = 'paid'
  AND NOT EXISTS (
    SELECT 1 FROM store_wallet_transactions t
    JOIN transaction_categories c ON c.id = t.transaction_category_id AND c.slug = 'sale'
    JOIN transaction_statuses ts ON ts.id = t.transaction_status_id AND ts.slug = 'completed'
    WHERE t.referenceable_type = 'App\Models\Order' AND t.referenceable_id = o.id
      AND t.related_transaction_id IS NULL
  );

-- Q3: an Order `refunded` with no completed customer_refund reversal of a completed sale
SELECT o.id AS order_id, os.slug AS order_status
FROM orders o
JOIN order_statuses os ON os.id = o.order_status_id
WHERE os.slug = 'refunded'
  AND NOT EXISTS (
    SELECT 1 FROM store_wallet_transactions r
    JOIN transaction_categories rc ON rc.id = r.transaction_category_id AND rc.slug = 'customer_refund'
    JOIN transaction_statuses rs ON rs.id = r.transaction_status_id AND rs.slug = 'completed'
    JOIN store_wallet_transactions t ON t.id = r.related_transaction_id
    WHERE t.referenceable_type = 'App\Models\Order' AND t.referenceable_id = o.id
  );

-- Q4: an Order still `paid` although its sale was fully refunded (should be refunded)
SELECT o.id AS order_id, os.slug AS order_status
FROM orders o
JOIN order_statuses os ON os.id = o.order_status_id
WHERE os.slug = 'paid'
  AND EXISTS (
    SELECT 1 FROM store_wallet_transactions r
    JOIN transaction_categories rc ON rc.id = r.transaction_category_id AND rc.slug = 'customer_refund'
    JOIN transaction_statuses rs ON rs.id = r.transaction_status_id AND rs.slug = 'completed'
    JOIN store_wallet_transactions t ON t.id = r.related_transaction_id
    WHERE t.referenceable_type = 'App\Models\Order' AND t.referenceable_id = o.id
  );

-- Q5: Order and Payment disagree
SELECT o.id AS order_id, os.slug AS order_status, p.status AS payment_status
FROM orders o
JOIN order_statuses os ON os.id = o.order_status_id
JOIN payments p ON p.order_id = o.id
WHERE (p.status = 'paid' AND os.slug <> 'paid')
   OR (p.status = 'refunded' AND os.slug <> 'refunded')
   OR (os.slug IN ('paid', 'refunded') AND p.status = 'pending');

-- Q6: an Order in a status the lifecycle does not know
SELECT o.id AS order_id, os.slug AS order_status
FROM orders o
JOIN order_statuses os ON os.id = o.order_status_id
WHERE os.slug NOT IN ('pending', 'paid', 'failed', 'refunded');
```

Notes:

- `referenceable_type` holds the class name on every existing row. The literal
  above is for PostgreSQL/SQLite; on **MySQL a backslash in a string literal must
  be doubled** (`'App\\Models\\Order'`). If a morph map is ever configured, rows
  written after it hold the alias instead — extend the predicate accordingly.
- A non-zero result means that Order's next settlement event will be refused and
  rolled back (§5). Repair is a manual data fix decided case by case; deliberately
  no repair tool exists (§11.6). Decide which side is authoritative *from the
  Wallet ledger and the provider*, not from the Order.
