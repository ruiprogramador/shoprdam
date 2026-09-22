# Inventory reservations (`feat/inventory-reservations`)

Implemented truth only. Where something is **not** implemented, or is proven
only partially, this document says so.

## 0. Summary

| | |
|---|---|
| Implemented | one `Inventory` row per Product; one `InventoryReservation` per OrderItem; `InventoryReservationService::{reserve, commit, release}` (order-level, all-or-nothing, idempotent); read-only `InventoryIntegrityChecker`; DB constraints; model guards; architecture scans |
| **Not** implemented (deliberately) | automatic **expiry** (no `expires_at`, no worker, no config); **payment integration** (nothing commits or releases on any payment event); **Order-creation integration**; cancellation; stock creation/adjustment in production; any UI |
| Why the last three | §12 (late payment after release) and §13 (no safe commit point) — each is an unresolved product/financial decision, not an implementation detail |
| Production effect today | **none.** No production code can create stock (`no setStock`), and nothing calls the service (an empty caller allowlist is asserted by `InventoryBoundaryTest`). This branch establishes the inventory *domain contract*; wiring it is a decision (§12, §18) |
| Payment/Wallet/Order-lifecycle code changed | **no** — zero lines |
| Real-engine proof | **MySQL 8.0.30 / InnoDB / REPEATABLE READ: proven** with independent OS-process connections (`tests/Concurrency`, §11). It found and fixed two real InnoDB deadlocks invisible on SQLite. **PostgreSQL, MariaDB and other MySQL versions/isolation levels: not run** |
| What this branch is | a *reservation-domain* contract that is correct on the engine it was proven on. It is **not** an operational inventory system: no stock creation, no stock control, no callers, no expiry, no payment/checkout wiring (§21) |

## 1. Audit findings (before any change)

Searched `app/` (and the whole tree, files-with-matches) for `stock`,
`inventory`, `quantity`, `available`, `reserved`, `reservation`, `decrement`,
`increment`, `sold`, `inStock`, `outOfStock`, `availability`.

| Hit | Classification |
|---|---|
| `OrderItem.quantity`, `OrderCreationService` (quantity validation/merging, `MAX_QUANTITY`), `InvalidOrderCreationException` | authoritative backend domain — quantity of a *purchase line*, not stock |
| `Product` docblock, `OrderCreationService` docblock ("No stock check — Inventory does not exist") | comments; confirm absence |
| `PayoutService` / `Payout*` / `PayoutStatus::Reserved` ("reservation debit") | irrelevant naming collision — a Wallet *accounting* reservation, unrelated to stock (it is nonetheless the closest precedent for "reserve now, release exactly once") |
| `PaymentEventProcessor` `$stored->increment('replay_attempts')` | irrelevant — a retry counter on a provider-event row |
| `PaymentsHealthCheck` docblock mentions `->increment()` | comment |
| `RateLimiter::availableIn`, `Translation::availableLocales/Groups`, `AuditWalletLedger` ("available") | irrelevant naming collisions |
| `docs/**`, `README.md` | documentation (`ORDER-ITEMS.md` §11 explicitly defers stock to this branch) |
| `resources/js/**` (`cartStore.js`, `CartDropdown.vue`, `QuickViewModal.vue`, `DailyDealCard.vue`, `WishlistTab.vue`, `OrderDetails.vue`, `dashboardStore.ts`, …) | frontend scaffolding — mock data; **not** turned into domain truth |
| `database/migrations/0001_01_01_000002_create_jobs_table.php` (`reserved_at`) | irrelevant — Laravel queue table |
| `tests/**` | tests |

**Unsafe direct stock writers found: none** — there is no stock to write.

### Stop-gate answers

1. **Does authoritative inventory exist today?** No.
2. **Any stock source of truth?** No (Product has no stock/quantity field; `CatalogDomainBoundaryTest` bans one).
3. **Unsafe direct decrement paths?** None.
4. **Is Product the smallest persisted sellable identity?** Yes (`products`; no Variant/SKU anywhere).
5. **Does quantity belong to OrderItem?** Yes — `order_items.quantity`, immutable, positive integer ≤ 2³¹−1.
6. **Any Variant/SKU requirement?** No. `CatalogDomainBoundaryTest` and `OrderItemBoundaryTest` ban them.
7. **Does cancellation exist?** No (`OrderLifecycleState`: `pending`, `paid`, `failed`, `refunded`; no `cancelled`).
8. **Which payment event is durable success?** `PaymentEventProcessor::applySucceeded()`: one `DB::transaction` containing `WalletTransactionService::confirm()` (sale → `completed`) + `markSettled()` (`OrderLifecycleService::markPaid`, `Payment` → `paid`, attempt → `succeeded`). Provider-neutral, at-least-once, idempotent.
9. **Can provider success arrive after a reservation expiry/release?** Yes. Provider webhooks are at-least-once with no ordering guarantee, `PaymentAttemptStatus::Failed` is the only attempt state that permits a new attempt, and reconciliation can discover a late success. Nothing in the payments domain consults anything about stock.
10. **Which production DB must provide the concurrency guarantee?** The repository is not fully explicit: `README.md` names **MySQL**; migrations/docs are written portable across MySQL/MariaDB/PostgreSQL; dev **and** test are SQLite (`phpunit.xml`: `DB_DATABASE=:memory:`). The mechanism chosen (§7) is valid on all three server engines.

No repository fact contradicts the prompt's assumptions, so no STOP applied to the *core*. STOP conditions did apply to expiry and payment wiring — see §12–§13.

Existing behaviors relied on (unchanged): Payment 1:N PaymentAttempt; attempt failure does not fail the Payment; Order `failed` is non-terminal and may become `paid`; settlement idempotent and fail-closed; legacy line-less Orders intentionally supported; `orders.is_line_backed` persistent provenance.

## 2. Identity

**Inventory identity = Product.** One `inventories` row per `products` row (`unique(product_id)`). No Variant/SKU/warehouse/location/lot/serial/supplier was introduced.

**Reservation identity = OrderItem.** One `inventory_reservations` row per `order_items` row (`unique(order_item_id)`). The chain `Product → Inventory → InventoryReservation ← OrderItem ← Order` is total: Product and quantity come from the OrderItem snapshot, the Order through the item, the stock through the Inventory. Not `Order` (one Order has several Products/quantities), not "both" (the Order is reachable through the item).

`inventory_reservations.quantity` **duplicates** `order_items.quantity` on purpose: release/commit must undo exactly what reserve added to the counter even if the OrderItem is later tampered with by a raw write, and the checker compares the two. No `product_id` is stored (reachable via both parents; their agreement is checked).

## 3. Single source of truth and conservation

```
on_hand_quantity   physical units present and not yet consumed        (authoritative)
reserved_quantity  units held by `reserved` reservations              (maintained aggregate)
available          on_hand_quantity − reserved_quantity               (derived, never stored)
```

`reserved_quantity` is an aggregate of the live reservation rows, **not** an
independent fact: it moves only in the same transaction, by the same quantity,
as a reservation state transition, and `InventoryIntegrityChecker` detects any
drift. There is no `available` column and no `committed` aggregate (a
committed total is `SUM(quantity) WHERE status='committed'`).

| Operation | `on_hand` | `reserved` | `available` | Reservation |
|---|---|---|---|---|
| `reserve(q)` | — | `+q` iff `available ≥ q` | `−q` | `reserved` |
| `release(q)` | — | `−q` | `+q` | `released` |
| `commit(q)` | `−q` | `−q` | — | `committed` |

Commit reduces **physical** stock at commit time (model A): reserve holds, commit consumes, release gives back. Conservation:

```
reserved_quantity  = Σ quantity of reservations with status 'reserved'
on_hand_quantity   = initial − Σ quantity of reservations with status 'committed'   (no stock ledger exists, see §16)
0 ≤ reserved_quantity ≤ on_hand_quantity
```

The last line is a **database CHECK** (§5), and `reserve` cannot produce a violation because of the predicate (§7). `InventoryIntegrityCheckerTest` runs a 120-step deterministic sequence of reserve/commit/release checking every equation after every step.

## 4. Schema

`inventories`: `id`, `product_id` (FK RESTRICT, unique), `on_hand_quantity` bigint, `reserved_quantity` bigint default 0, timestamps.
`inventory_reservations`: `id`, `inventory_id` (FK RESTRICT), `order_item_id` (FK RESTRICT, unique), `quantity` int, `status` varchar(16), `committed_at`, `released_at`, timestamps; index `(inventory_id, status)`.

* Columns are **signed** on purpose: MySQL evaluates `unsigned − unsigned` as `BIGINT UNSIGNED` and errors instead of yielding a negative, which would turn the availability predicate into an exception. Non-negativity is a CHECK.
* No `SoftDeletes`, no `expires_at`, no warehouse/sku/variant/supplier/lot/backorder column (`InventorySchemaTest` pins the exact column lists).

## 5. Database constraints

| Constraint | Table | Purpose |
|---|---|---|
| `unique(product_id)` | inventories | one Inventory per Product |
| `unique(order_item_id)` | inventory_reservations | logical idempotency identity (INVENTORY-03/04) |
| CHECK `on_hand ≥ 0`, `reserved ≥ 0`, `reserved ≤ on_hand` | inventories | INVENTORY-01 backstop |
| CHECK `quantity ≥ 1` | inventory_reservations | |
| CHECK status ∈ {reserved, committed, released} **and** evidence: `reserved` ⇒ both timestamps null; `committed` ⇒ `committed_at` only; `released` ⇒ `released_at` only | inventory_reservations | a half-written terminal transition cannot be stored |
| every FK `RESTRICT` | both | history is never cascade-deleted (INVENTORY-18) |

**Portability, stated honestly.** On MySQL/MariaDB/PostgreSQL these are named `ALTER TABLE … ADD CONSTRAINT … CHECK`; on SQLite (dev and test) they are `BEFORE INSERT/UPDATE` triggers, following `create_order_items_table`. **Only the SQLite form is exercised by this repository's tests.** MySQL enforces CHECK only from 8.0.16 (MariaDB 10.2.1); older servers parse and silently ignore it. The CHECKs are a *backstop*; the conditional UPDATE is the guarantee.

There is **no** database rule stopping a terminal reservation from being flipped back by raw SQL, nor a raw `DELETE` of a reservation row. Terminal protection is the CAS in the service + model guards + static scans; a vanished reservation is *detected* (checker), not prevented.

## 6. State machine

```
Reserved ──commit──▶ Committed   (terminal)
   └─────release───▶ Released    (terminal)
```

`InventoryReservationStatus::allowedTransitions()` is the single definition. Forbidden: `Committed → Released`, `Released → Committed`, and any transition out of a terminal state. Same-state repeats are idempotent no-ops (`commit` on a committed reservation changes nothing). There is no `Expired` and no `Cancelled`. No caller can assign a status: every Eloquent write path on both models throws, and the only status write in production is the compare-and-set inside `transition()`.

## 7. Canonical service and the concurrency mechanism

`App\Domain\Inventory\Services\InventoryReservationService` is the only writer. Operations are **order-level**: they act on every line of an Order inside one transaction.

### Reserve (INVENTORY-01/02/03/04/05)

1. `OrderLineIntegrityChecker::assertConsistent()` (re-reads the Order and its lines; a line-backed Order whose lines no longer add up is refused).
2. Read the Order's items; **zero items ⇒ refuse** (`InvalidReservationException::noLines`) — this is how legacy/line-less Orders are excluded (§14).
3. Read Inventory `(id, product_id)` only — **no quantity is read**. A Product without Inventory is refused (stock is never assumed).
4. If reservations already exist: all lines must have one (else `partiallyReserved`), each must still match its OrderItem quantity and its Product's Inventory, and be `reserved` → return them, **no effect** (idempotent replay). A terminal one refuses.
5. Otherwise, in deterministic order, per line: write-lock the Inventory row (`SELECT id … WHERE id = ? FOR UPDATE`, by primary key — lock acquisition only, no quantity is read); `INSERT` the reservation (the unique claim); then **the guard**:

```sql
UPDATE inventories
   SET reserved_quantity = reserved_quantity + :q, updated_at = :now
 WHERE id = :inventory
   AND on_hand_quantity - reserved_quantity >= :q
```

   `affected != 1` ⇒ `InsufficientStockException`, which rolls back the whole transaction.

**Why the Inventory row is locked before its child is written (found on real InnoDB).** A reservation `INSERT` checks its foreign key with a *shared* lock on the parent `inventories` row; two buyers that each hold that shared lock and then ask for the *exclusive* lock for the guard `UPDATE` deadlock (`1213`). Without the pre-lock, 3 of 4 concurrent buyers of one Inventory failed with a deadlock instead of a clean `InsufficientStock` — no oversell, but legitimate buyers would have failed spuriously with stock available. Locking first makes them queue. The guard is unchanged; the lock only orders acquisition.

**Why exactly one of two concurrent reservations for the last unit wins.** It is one statement, not read-then-write. The database takes the row's write lock, evaluates the predicate against the *latest committed* row and applies the increment as one atomic step; the second statement therefore finds `on_hand − reserved = 0 < q`, changes zero rows, and its transaction rolls back. No SELECT of a quantity ever decides anything (asserted by SQL-shape test). If a buggy writer skipped the predicate, the `reserved ≤ on_hand` CHECK would still refuse the row.

### Commit / release (INVENTORY-06/07/08/09/10)

Per reservation (`transition()`), inside the order-level transaction, the Inventory row is write-locked first (same reason as above, and found the same way: changing `status` re-inserts the entry of the `(inventory_id, status)` index — the foreign key's supporting index — which makes InnoDB take a shared lock on the parent row), then:

```sql
UPDATE inventory_reservations SET status = :target, <committed_at|released_at> = :now
 WHERE id = :id AND status = 'reserved'          -- compare-and-set
```

Exactly one concurrent caller sees `1` row; only it then moves the counters (in the same transaction):
release `reserved −= q WHERE reserved ≥ q`; commit `on_hand −= q, reserved −= q WHERE reserved ≥ q AND on_hand ≥ q`. A counter that cannot absorb the change (drift) makes the statement affect 0 rows → `InventoryIntegrityException` → the CAS rolls back with it.

A loser sees `0`, re-reads the row with `lockForUpdate()` (a *current* read — under MySQL REPEATABLE READ a plain SELECT could still show the pre-winner snapshot) and: same-state ⇒ idempotent no-op; different terminal state ⇒ `InvalidReservationException`; unknown value ⇒ `InventoryIntegrityException`. Inventory protects itself: it does not rely on provider-event idempotency or a caller never retrying.

### Deterministic lock order and multi-line atomicity

One lock hierarchy everywhere: the **Inventory row first, then its reservation rows**, and lines always in ascending **(inventory id, order item id)**, in every operation. Two multi-line operations therefore acquire inventory rows in the same global order and cannot wait on each other in a cycle. Order-level commit/release also refuse (rolling everything back) if any line is in a conflicting state or the Order is only partially reserved.

### Reserve idempotency race

`unique(order_item_id)` is the identity. If two identical `reserve()` calls race, the loser's `INSERT` fails on that unique index (only that constraint is recognised — an FK/CHECK violation propagates), its transaction rolls back completely (undoing any counter change), and it re-runs **once**, then observes the winner's rows. Inside a caller-owned REPEATABLE READ transaction the retry may still fail with the duplicate error rather than see the winner — it fails closed, never double-holds.

### Timeout / unknown outcome

There is no catch-and-compensate anywhere. An exception, deadlock, lock-wait timeout or crash means the transaction rolled back (or never committed) and stored state is the truth; an unknown outcome is resolved by re-reading it or repeating the idempotent call. Nothing ever increases availability because an exception occurred. Laravel's `DB::transaction` is used with its default single attempt: a MySQL deadlock (1213) / lock timeout (1205) or a PostgreSQL serialization failure surfaces as an exception to the caller; it is **not** retried automatically. The two FK-lock deadlocks the real-engine tests found are fixed (§11) and none occurs in the exercised scenarios, but that is evidence, not a proof of deadlock freedom: any residual deadlock/lock-wait error would surface to the caller with no partial effect.

### No provider call under a lock

The inventory domain contains no HTTP and no Payment/Wallet dependency (asserted by architecture scan). Its transactions are short, local, and never span a provider call.

## 8. Order creation

`OrderCreationService` is **unchanged** and does not know inventory. Order + OrderItems + reservation are all local database state, so a checkout composes them in **one outer transaction**:

```php
DB::transaction(function () use (...) {
    $order = $orders->create($store, $lines);
    $inventory->reserve($order);
    return $order;
});
```

`InventoryReservationServiceTest` ("composes with Order creation atomically") proves both directions: short stock leaves neither Order nor reservation; success leaves both. No orchestration service was added because no checkout exists to need one (`OrderCreationService` still has no production caller — `docs/orders/ORDER-ITEMS.md` §14.9).

## 9. Product changes after reservation (INVENTORY-15)

Rename, reprice, re-currency, deactivate and soft-delete change nothing about a reservation (`InventoryReservationServiceTest` "O"); commit/release use `order_items.quantity` only. The domain reads no price/currency (architecture scan bans `price_amount`, `unit_price_amount`, `currency`, `amount`). A Product with an Inventory row cannot be hard-deleted: `inventories.product_id` is RESTRICT (in addition to the existing `order_items` RESTRICT and the `Product::forceDelete` guard).

## 10. Stock creation and adjustment

**There is no production path that creates or changes physical stock**, and no `setStock`. Tests seed stock with a raw insert (`tests/Helpers.php::inventorySeed`). Production creation/adjustment (receiving, write-offs, "may an operator drop stock below what is reserved?") needs product decisions and an audit ledger; it is future work (§18). Consequently the service can never *increase* `on_hand`; `release` only lowers `reserved`.

## 11. Concurrency guarantee: what is proven, on which engine

* **Mechanism:** one atomic conditional `UPDATE` (reserve / counter moves) + a CAS `UPDATE … WHERE status='reserved'` (terminal transitions) + a unique index (idempotency), with the Inventory row write-locked first (§7). No advisory locks; no `SELECT … FOR UPDATE` decides availability.

### Proven on MySQL 8.0.30 / InnoDB / REPEATABLE READ (production default)

`tests/Concurrency/InventoryMysqlConcurrencyTest.php` (+ `worker.php`), 16 tests, run twice on a freshly migrated disposable database, identical results. **Every "buyer" is a separate OS process with its own MySQL connection** — not sequential calls on one connection.

* **Deterministic scenarios:** worker A runs the real service call inside a still-open outer transaction (holding its InnoDB locks); worker B is started behind it; the test then verifies in `performance_schema.data_lock_waits` that B's connection is *genuinely blocked on a lock* before A may commit. This is exactly the behavior the design relies on: the loser waits, then re-evaluates against the winner's committed result.
* **Race scenarios:** N workers released from a file barrier at once, many rounds, invariants asserted.

| Scenario | Result on MySQL |
|---|---|
| stock=1, two different Orders (deterministic; B verified blocked) | exactly one reserves; B `InsufficientStock`; `on_hand=1, reserved=1, available=0`; exactly one live reservation |
| stock=1, 4 Orders × 10 barrier rounds | exactly one winner every round |
| duplicate concurrent `reserve()` of the *same* Order (deterministic + 3 workers × 8 rounds) | loser waits on the unique claim, retries, converges on the winner's reservation; stock held once |
| `commit` vs `release`, both directions (deterministic) + 10 barrier rounds | one terminal winner; loser refused; only the winner's stock effect |
| duplicate concurrent `commit()` / `release()` (deterministic + 3 workers × 6 rounds each) | one performs it, others are no-ops; one stock effect |
| multi-line reserve, lines stored in opposite order, × 25 rounds | no deadlock; all reservations held |
| multi-line `commit` of one Order vs `release` of another over shared inventories × 12 rounds | no deadlock |
| Order whose last line lacks stock, contending with another buyer for its first line × 12 rounds | fails cleanly; no ghost hold; the contender always gets the unit |
| CHECK constraints and RESTRICT FKs executed by MySQL itself | refused (`ER_CHECK_CONSTRAINT_VIOLATED` 3819) — the CHECKs are real on 8.0.30 |
| integrity audit of all data the above produced (144 inventories, 206 reservations) | **zero findings**: `0 ≤ reserved ≤ on_hand`, `reserved = Σ live reservations` |

### Defects the real engine found (both fixed; SQLite could not show either)

1. **Reserve: InnoDB FK shared-lock deadlock** (§7). 4 concurrent buyers → 3 × `1213`. Fixed by locking the Inventory row before inserting its child.
2. **Commit/release: the same deadlock through the `(inventory_id, status)` FK index** (InnoDB's own deadlock report showed both transactions holding `S` and waiting for `X` on the same `inventories` record). Fixed by the same rule in `transition()`.

Neither ever produced an oversell or a double effect (the counters/CHECKs held); both produced spurious *failures*.

### Mutation probes run against MySQL

| Defect injected | Detected by |
|---|---|
| remove the reserve pre-lock | 4-buyer race (`1213` in round 1) |
| remove the settle pre-lock | multi-line commit/release scenario (`1213` in round 1) |
| remove the sufficiency predicate | both stock=1 scenarios — the `reserved ≤ on_hand` CHECK still refuses the oversell (backstop demonstrated), the guard tests fail |
| disable the reservation CAS | commit-vs-release scenarios, both directions |
| loser re-reads **without** `lockForUpdate` | duplicate-commit scenario — the only defect previously protected by an architecture pin alone; now behaviorally proven necessary on REPEATABLE READ |
| remove deterministic lock ordering | opposite-order multi-line scenario (`1213` in round 1) |

### Not proven, and not claimed

* **PostgreSQL, MariaDB, other MySQL versions, other isolation levels (READ COMMITTED, SERIALIZABLE), replicated/clustered setups** — no run. The mechanism is argued valid there (PostgreSQL READ COMMITTED re-evaluates the `WHERE` after a row-lock wait; REPEATABLE READ raises a serialization failure, not an oversell) but **not executed**.
* Deadlock/lock-wait *freedom* under arbitrary load: the exercised interleavings are 2–4 workers; no soak/scale test. Timeouts (`innodb_lock_wait_timeout`, default 50 s) would surface as exceptions.
* Contention-scale behavior (throughput, lock queue length under a flash sale) — the design serializes buyers per Inventory row by construction.

### SQLite (default suites)

Single writer; proves the *algorithm* (SQL shape, competitor-between-read-and-write simulation, stale-model CAS, all-or-nothing, ordering) and the state machine, **not** lock waiting, current-read semantics, FK lock modes or CHECK enforcement.

### How to run the real-engine suite

Opt-in and outside every `phpunit.xml` suite. Against a **disposable local** MySQL 8 database whose name ends in `_concurrency_test` (the test and the worker refuse anything else), already migrated:

```
INVENTORY_MYSQL_CONCURRENCY=1 DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=… \
DB_DATABASE=<name>_concurrency_test DB_USERNAME=… DB_PASSWORD=… \
[INVENTORY_MYSQL_WORKER_PHP_ARGS="-d extension=pdo_mysql"] \
php vendor/pestphp/pest/bin/pest tests/Concurrency
```

Note: the repository's migrations had never run on MySQL before this round — `2026_09_17_090000_create_payment_reconciliation_findings_table` (a previous branch) fails on MySQL (index name > 64 characters). It is out of scope here; the disposable database was prepared by marking that one migration as run. The two inventory migrations, and everything they depend on, migrate cleanly on MySQL 8.0.30.

## 12. Expiry and late payment — NOT implemented (STOP condition 9)

Automatic expiry was **not** built. The exact race:

1. stock = 1; Order A reserves it; A's Payment is not completed before the reservation "expires";
2. expiry releases A; Order B reserves the freed unit; B pays and commits;
3. later the provider reports **success for A** (permitted: webhooks are at-least-once and unordered; `PaymentAttemptStatus::Failed` is the only attempt state that permits a new one; reconciliation can discover a late success).

Now A has financially succeeded and B owns the only unit. **What the code does today** (pinned by `InventoryPaymentBoundaryTest`, "late provider success after a release"): `PaymentEventProcessor` settles A (Wallet `completed`, Order `paid`) — it must, and it knows nothing of stock; `commit(A)` is then **refused** (`InvalidReservationException`, Released → Committed forbidden) and `on_hand`/`reserved` stay correct — stock is not invented, stolen or made negative.

The existing domain defines no answer for "A paid, stock is gone": no cancellation, no automatic refund initiation, no backorder, no manual-adjustment workflow. Choosing among them is a product/financial decision, not an implementation detail:

| Option | Consequence |
|---|---|
| Never auto-release while a Payment can still succeed (release only when the Payment has no attempt that is `pending`/`claimed`/`needs_attention`) | keeps A safe; needs a defined "no live attempt" rule and a stock-hoarding bound |
| Expiry that extends while any attempt is non-terminal | same, plus a duration policy |
| Allow the sale, treat as backorder/oversold | contradicts "prevent overselling"; needs a backorder concept |
| Auto-refund A | no refund-initiation path exists; new financial flow |
| Manual resolution queue | new operational subsystem |

**Smallest missing decision:** what happens to an Order that has *paid* when its stock is no longer available. Until it is made, no reservation may be released by anything automatic. Consequently there is no `expires_at`, no expiry config (a duration would be a guess), no worker, and INVENTORY-11/12 are N/A. `release()` exists as a tested primitive (§7) with **no production caller**; whoever first wires it (cancellation, expiry, operator action) must resolve this first.

## 13. Payment relationship — NOT wired (STOP conditions 8/10)

* **Candidate commit point:** the durable settlement in `PaymentEventProcessor::applySucceeded()`.
* **Inside** that transaction: an inventory failure (reservation released/absent/corrupt) would roll back a financial settlement *after the provider already captured money* — rejected; a financial invariant would be weakened.
* **After** it: `OrderTransitioned` is `DB::afterCommit`, at-most-once, no outbox; a crash between commit and callback loses the commit, and recovery needs a new reconciler over "paid line-backed Orders with `reserved` reservations" — whose failure branch (reservation released/absent) is exactly the §12 policy gap.

So **no payment event commits or releases inventory.** Behaviour pinned by tests:

* `PaymentAttempt` failure ⇒ **no release**; Order `failed` ⇒ **no release**; a later attempt may succeed and the *same* reservation is still there — no reservation per attempt (INVENTORY-13/14; `InventoryPaymentBoundaryTest` M/N);
* provider success does not consume stock; called explicitly, `commit` consumes exactly once, provider redelivery and repeated commit have no further effect;
* legacy line-less Orders pay exactly as before;
* **no financial settlement behavior changed** (no payment/wallet/order-lifecycle file was modified; the architecture scan forbids them from referencing inventory).

Structurally, this is enforced by the **empty caller allowlist**: no file outside `app/Domain/Inventory` may mention the inventory domain.

## 14. Legacy Orders (INVENTORY-17)

Legacy line-less Orders have no Product/quantity; none is inferred from `orders.amount`. `reserve()` refuses them (`noLines`), `commit`/`release` refuse them (`noReservations`); nothing is written. Nothing is backfilled: the tables start empty and no stock or reservation is derived from Order history (`InventorySchemaTest` "starts empty"). Legacy payment tests are untouched and pass.

## 15. Delete policy (INVENTORY-18)

All FKs RESTRICT (`InventoryReservationServiceTest` "Q" reads the actual `PRAGMA foreign_key_list` and attacks each parent). The database refuses to delete a Product with an Inventory row, an Inventory or OrderItem a reservation references, or (transitively) an Order. Reservations are never deleted by any code path (architecture scan bans delete/truncate in the domain; terminal rows are evidence). **Gap:** nothing references a reservation, so raw SQL can delete one; that is detected, not prevented (§16).

## 16. Drift detection (INVENTORY-20)

`InventoryIntegrityChecker::audit()` — read-only, chunked, no lock, **never repairs**. Findings: `negative_on_hand`, `negative_reserved`, `reserved_exceeds_on_hand`, `counter_disagrees_with_reservations` (covers a raw counter edit, a raw reservation delete, a terminal reservation still counted, a live one not counted), `unknown_status`, `order_item_missing`, `quantity_mismatch`, `inventory_product_mismatch`. It **cannot** verify `on_hand_quantity` against history: there is no stock ledger, so physical stock is taken as authoritative. Nothing calls it automatically and no command was added. Duplicate logical reservations are prevented by the unique index, so they are not a finding.

Fail-closed at operation time: `reserve` refuses a mismatching existing reservation or a tampered Order; `commit`/`release` refuse a reservation whose quantity/Inventory disagrees with its OrderItem and any transition the counters cannot absorb. **Not preventable:** a raw write that *lowers* `reserved_quantity` makes more stock look available to `reserve` — it can only be detected afterwards.

## 17. Architecture enforcement

`tests/Architecture/InventoryBoundaryTest.php` — comments stripped, exact path allowlists, non-vacuous counts (>100 production files; the domain's exact 8-file list), and a synthetic positive **and** negative self-test for every detector. Scanned roots: `app`, `routes`, `bootstrap`, `config`, `database/seeders` (+ the two inventory migrations by name).

| Rule | Allowlist |
|---|---|
| only the service touches `inventories`/`inventory_reservations` via builder/raw SQL | `InventoryReservationService.php` |
| no Eloquent write (static, `new`, chain, relation) of either model | none |
| stock columns/tables appear nowhere outside `app/Domain/Inventory` | none |
| **no production caller of the inventory domain** (controllers, jobs, listeners, provider adapters, Payment/Wallet/Order code) | **empty** — a tripwire: wiring is a decision |
| the domain has no Payment/Wallet/provider/HTTP/Order-lifecycle dependency and reads no price/currency/amount | — |
| no excluded concept (variant, sku, warehouse, supplier, expiry, cancellation, fulfilment, adjust, setStock, …) | — |
| no delete/truncate/raw DDL/bare increment/SoftDeletes in the domain | — |
| every inventory FK `restrictOnDelete()`; no cascade/`nullOnDelete`/softDeletes | — |
| the service keeps its load-bearing statements (conditional predicate, CAS on `status='reserved'`, `lockForUpdate`, transactions, ordering; single status write behind an enum target) | — |

**Blind spots:** a name or SQL assembled at runtime; anything outside the scanned roots; direct database access. The load-bearing-statement pins are brittle by design (they catch removal, e.g. of `lockForUpdate`, which SQLite behavior cannot). The empty caller allowlist proves no *known static* caller, not correctness of a future one. These scans are not database enforcement.

## 18. INVENTORY-XX invariants

| ID | Statement | Status | Runtime | DB | Architecture | Tests | Limitation |
|---|---|---|---|---|---|---|---|
| 01 | Reservable quantity never negative via canonical ops | **ENFORCED** | conditional UPDATE predicate | CHECKs `on_hand≥0, reserved≥0, reserved≤on_hand` | statement pin | `InventoryReservationServiceTest` A/B; `InventorySchemaTest` "INVENTORY-01"; CONSERVATION | CHECK only executed on SQLite; a raw write can lower `reserved` (detected) |
| 02 | stock=1, two concurrent reserve(1) cannot both succeed | **ENFORCED — proven on MySQL 8.0.30/InnoDB; not run on PostgreSQL/MariaDB** | single atomic conditional UPDATE, Inventory row locked first | CHECK backstop (demonstrated by probe) | SQL-shape/statement pins | "C" (SQLite algorithm tests); `tests/Concurrency` stock=1 scenarios (2 independent connections, verified lock wait; 4-way barrier ×10) | other engines/isolation levels not executed (§11) |
| 03 | ≤ 1 live reservation per OrderItem | **ENFORCED** | unique-claim INSERT + idempotent replay | `unique(order_item_id)` | — | "D", `InventorySchemaTest` | — |
| 04 | Reservation creation atomic and idempotent | **ENFORCED** | one transaction; replay returns existing; lost-race rollback + one retry | unique index | — | "D" ×3, "composes … atomically" | retry inside a caller-owned REPEATABLE READ transaction may fail closed instead of converging (the top-level retry converges — proven on MySQL) |
| 05 | Multi-line reservation all-or-nothing | **ENFORCED** | one transaction; any failure throws | — | transaction pin | "E" ×3, "S", ordering test | — |
| 06 | Duplicate release ≤ 1 stock effect | **ENFORCED** | CAS `status='reserved'`; counters only for the winner | — | CAS pin | "F" ×3 | no DB terminal-state protection against raw SQL |
| 07 | Duplicate commit ≤ 1 stock effect | **ENFORCED** | same | — | same | "G" ×2 | same |
| 08 | Committed cannot be released | **ENFORCED** | CAS + illegal-transition refusal | evidence CHECK | enum-target pin | "H", state-machine test | raw SQL can flip status |
| 09 | Released cannot be committed silently | **ENFORCED** | same (throws) | same | same | "I", late-payment test | same |
| 10 | Concurrent commit/release: exactly one terminal winner | **ENFORCED — proven on MySQL 8.0.30/InnoDB; not run elsewhere** | CAS + Inventory pre-lock + locking re-read | — | `lockForUpdate` pins | "J" ×3 (SQLite stale-model); `tests/Concurrency` commit-vs-release both directions + barrier ×10 | other engines not executed |
| 11 | Expiry eligibility alone does not invent stock | **N/A** | no expiry implemented | — | `expires_at` banned | — | §12 |
| 12 | Concurrent expiry workers cannot double-release | **N/A** | no worker | — | — | — | §12 |
| 13 | PaymentAttempt failure does not release | **ENFORCED** | nothing calls release | — | empty caller allowlist | `InventoryPaymentBoundaryTest` M/N; probe 12 | structural, not a semantic proof about future callers |
| 14 | Order.failed does not release | **ENFORCED** | same | — | same | same | same |
| 15 | Mutation never depends on Product price/currency | **ENFORCED** | quantities from OrderItem only | — | dependency scan | "O" | — |
| 16 | No provider HTTP under inventory locks | **ENFORCED** | no HTTP/Payment dependency; short local txns | — | dependency scan | — | structural |
| 17 | Legacy Orders get no fabricated reservation | **ENFORCED** | `noLines`/`noReservations` | tables start empty | — | "P", `InventorySchemaTest` | — |
| 18 | History not cascade-deleted | **PARTIALLY** | model delete guards; no delete code | all FKs RESTRICT | migration + shape scans | "Q" | raw `DELETE` of a reservation is not blocked (detected) |
| 19 | No production bypass of the canonical boundary via known static paths | **PARTIALLY** | model guards (instance writes, `increment`, `saveQuietly`, `withoutEvents`) | — | raw-table, Eloquent-write, hidden-stock, caller scans | "R"; probe 13 | runtime-assembled SQL; direct DB access |
| 20 | Corruption detected / fails closed where it could oversell or mis-commit | **PARTIALLY** | mismatch + counter-drift refusals; checker | CHECKs | — | tampering tests, `InventoryIntegrityCheckerTest` | a lowered counter is detected only after the fact; no stock ledger |

## 19. Crash / concurrency matrix

| # | Scenario | Result |
|---|---|---|
| 1 | reserve one unit successfully | prevented-oversell; counters `+q` |
| 2 | reserve more than available | rolled back (`InsufficientStock`) |
| 3 | stock=1, two concurrent buyers | prevented (atomic predicate) — real-engine race **not run** |
| 4 | duplicate reserve request | idempotent no-op |
| 5 | request commits but response lost | safe retry → idempotent replay |
| 6 | multi-line, last Product lacks stock | rolled back |
| 7 | exception halfway through multi-line | rolled back |
| 8 | DB rollback | rolled back |
| 9 | DB commit failure | rolled back / never committed; state is truth; safe retry |
| 10 | duplicate commit | idempotent no-op |
| 11 | duplicate release | idempotent no-op |
| 12 | commit vs release race | one CAS winner; loser refused, no effect |
| 13 | expiry vs commit race | **N/A — expiry not implemented (§12)** |
| 14 | expiry worker vs expiry worker | **N/A** |
| 15 | PaymentAttempt failure | no release (structural + tested) |
| 16 | payment retry success | same reservation; single explicit commit |
| 17 | Order failed then later paid | no inventory effect either way |
| 18 | provider success redelivery | no inventory effect (nothing wired); explicit commit idempotent |
| 19 | late provider success after release | **unresolved product decision** (§12); settlement stands, commit refused, no stock invented |
| 20 | Product becomes inactive | reservation unchanged |
| 21 | Product soft-deleted | reservation unchanged; hard delete refused by FK |
| 22 | quantity tampering | detected / fail closed (refused at reserve/commit/release; checker) |
| 23 | reservation row tampering | detected / fail closed |
| 24 | inventory counter drift | detected / fail closed (transition rolled back; checker) |
| 25 | raw SQL deletion of a reservation | **not mechanically preventable**; detected by checker |
| 26 | raw SQL stock mutation | CHECKs refuse negative/over-reserved; a lowered `reserved` is **not preventable**, detected |
| 27 | scheduler overlap | N/A — no scheduled job added |
| 28 | worker crashes after claiming expiry candidate | N/A |
| 29 | worker crashes after state transition | committed transaction is complete (CAS + counters atomic); otherwise rolled back |
| 30 | legacy Order payment | unaffected; no reservation fabricated |

## 20. Mutation probes performed

Each defect was injected one at a time, the permanent tests run, and the original restored (checksums verified).

| Probe | Caught by |
|---|---|
| remove the stock-sufficiency predicate | 10 tests: "B", "C" ×3, "E" ×2, CONSERVATION, "composes …", exact-remaining, statement pin |
| disable the reservation CAS (duplicate release/commit double effect) | 13 tests: "F" ×3, "G" ×2, "H", "I", "J" ×3, "N", late-payment, statement pin |
| allow Released → Committed | 8 tests incl. "I", "J", late-payment, statement pin |
| allow Committed → Released | 6 tests incl. "H", "J", "G", statement pin |
| remove the reserve transaction | 7 tests incl. "E" ×2, "S", "B", CONSERVATION, statement pin |
| remove deterministic lock ordering | "locks inventory rows in ascending Inventory-id order" |
| drop the idempotent-reserve existing-check | "D" repeated reserve, terminal re-reserve, quantity-tampering |
| remove the counter predicate on commit/release | "counter drift" |
| commit forgets to consume `on_hand` | 10 tests incl. CONSERVATION, "G" ×2, checker |
| replace `lockForUpdate()` on the loser's re-read | **only the architecture pin** — SQLite cannot observe it |
| drop `unique(order_item_id)` | "D" lost-race, "D" classifier, `InventorySchemaTest` |
| release inventory on PaymentAttempt failure (injected into `PaymentEventProcessor`) | "M", "N" **and** the empty-caller architecture test |
| direct stock write from a controller | raw-table and hidden-stock architecture tests |

Expiry-CAS probe: N/A (no expiry).

## 21. Known limitations

1. **Inert in production**: no way to create stock, no caller (§10, §13).
2. Real-engine proof exists **only for MySQL 8.0.30/InnoDB/REPEATABLE READ** (§11). PostgreSQL, MariaDB, other MySQL versions and isolation levels are argued, not executed. No soak/scale test.
3. **No expiry / release trigger**: reservations live until explicitly committed or released, so a future checkout could hoard stock — bounded only once the §12 decision exists.
4. **No payment commit integration**; a paid Order can have a `reserved` reservation indefinitely (detectable: paid line-backed Order + `reserved` row).
5. **Late payment after release** is unresolved (§12).
6. **`on_hand_quantity` is trusted, not verified.** `InventoryIntegrityChecker` checks the *internal consistency of persisted state* (counters vs reservation rows vs OrderItems). It **cannot** prove that `on_hand_quantity` equals the quantity that physically exists, and there is no stock ledger to reconstruct it from. Stock creation, receipts, adjustments, stocktakes/cycle counts and physical reconciliation belong to a future *inventory-control* capability, deliberately **not** part of inventory reservations, and nothing for them was created here (no tables, no code).
7. Raw SQL can flip a terminal status, delete a reservation, or lower `reserved_quantity`; detected by the checker, which nothing runs automatically.
8. Deadlocks/lock timeouts/serialization failures surface as exceptions and are not auto-retried.
9. The unique-race retry may fail closed inside a caller-owned REPEATABLE READ transaction.
10. Static scans have the blind spots in §17.
11. **Operational completeness is deliberately absent** (§0): correctness of the reservation domain is not the same as a working inventory system. Nothing creates stock, nothing calls the service, nothing expires or cancels a hold, nothing commits on payment.
12. **Pre-existing, out of scope:** migration `2026_09_17_090000_create_payment_reconciliation_findings_table` fails on MySQL (identifier > 64 chars), so the full migration set had never run on MySQL; and `config/database.php` references `PDO::MYSQL_ATTR_SSL_CA`, deprecated on PHP 8.5. Neither was touched.

## 22. Future decisions

* what a paid Order with no stock means (§12) — then expiry duration, config (strict integer parsing per `ConfigInteger`), worker, and the release/commit wiring;
* the checkout contract (who reserves, when, with which composition);
* how stock is created/adjusted, with a ledger, and whether it may drop below reserved;
* cancellation must use `release()`, never a new stock path;
* a real two-connection concurrency test on the production engine.

## 23. Non-goals confirmed absent

ProductVariant, SKU, warehouses, locations, suppliers, procurement, purchase orders, stock receipts, serials, lots, backorders, negative stock, safety stock, preorder, low-stock alerts, inventory UI, cancellation, fulfilment, shipping, tax, discounts, partial refunds, automatic refund, Payment/Wallet redesign.
