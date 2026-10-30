# Inventory control — design (`design/inventory-control`)

**Status: DESIGN ONLY. Nothing in this document is implemented.** It is the
contract that a future `feat/inventory-control` branch implements. Every
mechanism below is marked **PROPOSED**, **PRODUCT DECISION REQUIRED** or
**BLOCKED**. Nothing here is ENFORCED, and nothing here may be cited as
evidence that a capability exists (`CLAUDE.md` §9).

The implemented Inventory truth is `docs/inventory/INVENTORY-RESERVATIONS.md`.
Where this document restates it, the text says **EXISTING** and cites it.
Engine support and real-engine evidence belong to
`docs/architecture/DATABASE-SUPPORT.md`, which is authoritative. No
engine claim made here overrides it.

| Status | Meaning in this document |
|---|---|
| **EXISTING** | implemented today, described in `INVENTORY-RESERVATIONS.md`; restated only so the new contract can be checked against it |
| **PROPOSED** | designed here; to be implemented, tested and real-engine-proven by `feat/inventory-control` |
| **PRODUCT DECISION REQUIRED** | the repository cannot decide it; options and consequences recorded (§20) |
| **BLOCKED** | depends on the reservation/payment lifecycle decision (§21) or on Fulfilment, which does not exist |

## 0. Summary

| | |
|---|---|
| Source of truth | `inventories.on_hand_quantity` stays **authoritative**. Every change to it is paired, in the same transaction, with exactly one append-only `inventory_movements` row (option A, §3) |
| New evidence | `inventory_movements` (every `on_hand` change), `inventory_counts` (physical-count observations, including rejected ones), `inventory_baselines` (where an Inventory's evidence starts: `genesis` or `adopted`) |
| Operations | `createInventory`, `adopt`, `receive`, `writeOff`, `correct`, `submitCount`. `commit` additionally records a `sale` movement. **No** `setStock`, no generic `adjust(±q)`, no `opening_balance` |
| Reservations | Contract unchanged: `reserve`/`release` produce no movement, and `Reserved → Committed \| Released` stays terminal. The only change is inside `commit`, which adds the sale evidence (§6.4) |
| Physical count | `expected_on_hand` compare-and-set. A stale snapshot is recorded as `stale` and changes nothing. A count below `reserved_quantity` is recorded as `discrepancy_open` and changes nothing (P1 = A+B, §7) |
| Idempotency | NOT NULL key + unique `(inventory_id, idempotency_key)` + a field-level request fingerprint. Deterministic keys for `sale` and `correction`. No nullable-column uniqueness is relied on (§9) |
| Lock order | Inventory row `FOR UPDATE` (ascending id), then its reservation rows, then evidence inserts and guarded counter updates. The parent is always locked before a child is written (§10) |
| Actor evidence | `actor_type` + `actor_user_id` snapshot, **no foreign key**. Deleting a User neither destroys nor mutates stock history (§4.5) |
| Legacy | An Inventory with `is_baselined = false` (the migration default) is *unevidenced legacy*. Control operations refuse it until `adopt()` records an explicit `adopted` baseline. Nothing is backfilled. Every movement is stamped with `after_baseline`, taken from the locked Inventory row. The reconciliation needs neither a SUM at adoption nor any read-view premise (§8.4) |
| Activation gate | `submitCount` may be implemented and proven, but must **not** get a production caller until O-1 (selling while a discrepancy is open) and O-8 are decided (§7.5) |
| Blocked | when `commit` is called relative to payment; resolving a discrepancy by releasing reservations; count semantics for committed-but-undispatched units (§21) |
| Production effect of this branch | none — documentation only |

## 1. Audit findings (condensed)

The full audit was presented and approved before this document was written.
The facts it rests on:

- **EXISTING writer:** `App\Domain\Inventory\Services\InventoryReservationService`
  is the only code that writes `inventories`/`inventory_reservations`. It has
  **no production caller** (the caller allowlist in
  `tests/Architecture/InventoryBoundaryTest.php` is empty).
- **No production path creates an `inventories` row.** The service never
  inserts one, `Inventory::performInsert()` throws, and tests seed stock with a
  raw insert (`tests/Helpers.php`, `inventorySeed()`). "Opening stock" is
  therefore also "creating the row".
- **Today, "sale" already exists as `commit`** (`on_hand −= q, reserved −= q`),
  but it leaves no evidence beyond the reservation row itself.
- **Nothing** in production models receipts, returns, write-offs,
  adjustments, physical counts, a stock ledger, warehouses, locations,
  suppliers, SKUs, variants, lots, serials, costing, cancellation or
  fulfilment. `inStock`/`stock` in `resources/js/**` is mock data and is not a
  requirement.
- **Actors:** `App\Models\Admin` has no roles. A Store is owned by exactly one
  User (`stores.user_id`). No "stock operator" concept exists.
- **Real-engine lessons (EXISTING, `INVENTORY-RESERVATIONS.md` §11):** two
  InnoDB deadlocks came from a child write taking a *shared* lock on its parent
  `inventories` row through the foreign key, before the same transaction asked
  for the parent's *exclusive* lock. Both were fixed by locking the parent first.
  MariaDB `innodb_snapshot_isolation` can fail a locking read (`HY000`/`1020`).
  On PostgreSQL a unique violation aborts the transaction.
- **Contract tests the implementation must change deliberately:**
  `InventoryBoundaryTest` (8-file domain list, 2 migrations by name, one
  raw-table file, `receipt` in the forbidden-concept list, statement pins) and
  `InventorySchemaTest` (column lists, "starts empty"). §22.13 lists them.

## 2. Existing truths this design preserves

EXISTING, from `INVENTORY-RESERVATIONS.md` §3, §6 and §7. None of them is
changed here:

```
on_hand_quantity   physical units present and not yet consumed     (authoritative)
reserved_quantity  Σ quantity of reservations with status 'reserved' (maintained aggregate)
available          on_hand_quantity − reserved_quantity             (derived, never stored)
0 ≤ reserved_quantity ≤ on_hand_quantity                            (DB CHECK + predicates)

reserve(q):  on_hand unchanged   reserved += q   iff available ≥ q
release(q):  on_hand unchanged   reserved −= q
commit(q):   on_hand −= q        reserved −= q
Reserved ──commit──▶ Committed (terminal)   Reserved ──release──▶ Released (terminal)
```

Nothing in this design requires breaking any of these. Specifically:

- Every new operation keeps `reserved ≤ on_hand` through its own guard
  predicate, and the EXISTING CHECK remains the backstop.
- No new operation writes `reserved_quantity`, except `commit`, which already
  does.
- No new operation changes a reservation's status.
- No reservation is released or cancelled by anything designed here.
- `available` is never stored.

## 3. Source of truth

### 3.1 Decision — PROPOSED: option A

`inventories.on_hand_quantity` is the authoritative current state. An
`inventory_movements` row is **mandatory evidence** of every change to it. The
change and its movement are written in the same transaction, so neither can
exist without the other (STOCK-01).

### 3.2 Options compared

| | **A — Inventory authoritative + append-only evidence** | **B — ledger authoritative (`on_hand = Σ movements`)** |
|---|---|---|
| Correctness | the EXISTING reserve guard (one conditional UPDATE on one row) is untouched | the guard would have to evaluate `Σ movements − reserved ≥ q` atomically; a single-row predicate can no longer express it |
| Concurrency | serialized on the Inventory row lock, exactly as today | still needs a per-Inventory lock to make the SUM-then-write safe, so it degenerates into A plus a slower read |
| Performance | O(1) per operation | O(movements) per reserve, or a cached counter, which is A again |
| Drift | detectable: equation §15 | none by construction, until a cache is added, and then the cache drifts |
| Auditability | every `on_hand` change has a row | same |
| Crash safety | one transaction pairs state and evidence | one transaction |
| Physical count | a count produces one guarded state change plus its evidence | a count produces a movement, but "what is on hand" requires a SUM under lock |
| Reservations | unchanged; the proven guard stays (DBPORT-08/09) | the guard must be redesigned and re-proven on three engines |
| Historical reconstruction | Σ movements from the baseline (§15); point-in-time only approximately (by `created_at`) | the same, natively |
| Operational complexity | low | high |

**B is rejected.** It would reopen the reservation mechanism that is proven on
MySQL, MariaDB and PostgreSQL, for no guarantee that A cannot provide.

**This deliberately does not copy the Wallet ledger** (`LEDGER-*`). Money has
no physical counterpart that can disagree with the books. Stock does:
units are lost unobserved, counted, and held by reservations that are not
movements. Hence the equation in §15 is **internal consistency from a
baseline**, never "the books equal reality". Reality is only observed at
discrete points, through counts.

### 3.3 `on_hand_after` and `sequence` — analysed and omitted

| Field | Property it could add | Why it is omitted |
|---|---|---|
| `on_hand_after` (post-change value per movement) | locating the first divergent movement; detecting a *mid-history* deleted or altered movement even when compensated by a matching raw `on_hand` edit | the chain check needs a total order of an Inventory's movements. That order is only the id order, which equals serialization order **only** if every writer follows the lock discipline in §10. A raw writer, which is exactly what the check targets, breaks that premise. It adds one read per operation and gives an ordering claim the database does not guarantee. It still cannot detect tampering at the tail of the chain |
| `sequence` (`unique(inventory_id, sequence)`) | gap detection | the same premise, plus a second unique index per Inventory. A gap is not proof of anything: rolled-back transactions already leave id gaps |

Neither field proves a property that `on_hand` + `Σ movements` + the FK and
CHECK rules below cannot, **except** detecting coordinated two-row raw
tampering. That residual is declared instead (§15.3). This is the same class as
the ORDER-ITEMS residual "flag flip plus deleting every line".
**Revisit trigger:** a real requirement for point-in-time stock queries, or
for per-step audit localization.

## 4. Evidence model — PROPOSED

### 4.1 Entities

```
Product ─1:1─ Inventory ─1:0..1─ InventoryBaseline      (genesis | adopted)
                  │
                  ├─1:N─ InventoryReservation ◀─1:1─ OrderItem          (EXISTING)
                  │             ▲
                  ├─1:N─ InventoryMovement ── sale ──┘ (reservation_id)
                  │             ├── count_adjustment ─▶ InventoryCount
                  │             └── correction ───────▶ InventoryMovement (corrects_movement_id)
                  └─1:N─ InventoryCount ── resolved_by ─▶ InventoryCount
```

`inventory_reservations` gets no new column. `inventories` gets exactly one:

- `is_baselined` boolean NOT NULL DEFAULT false. It is the lockable projection
  of "a baseline row exists" (§8.4).
- It follows the EXISTING `orders.is_line_backed` precedent: the migration
  default marks every pre-existing row as legacy, and only the canonical
  creator and `adopt()` write `true`.
- `InventorySchemaTest`'s `inventories` column pin therefore changes
  **deliberately** (§22.13). This is not a backfill: `false` asserts only
  "no evidence exists", which is true for every existing row.

Table names use the `inventory_` prefix. The EXISTING ban on `stock_\w+` tables in
`InventoryBoundaryTest` is therefore **kept, not relaxed**.

### 4.2 `inventory_movements`

| Column | Type | Rule |
|---|---|---|
| `id` | bigint | |
| `inventory_id` | FK → `inventories`, RESTRICT | NOT NULL |
| `reason` | varchar(24) | one of the reasons below (CHECK) |
| `quantity_delta` | bigint, **signed** | ≠ 0; sign fixed by `reason` (CHECK) |
| `idempotency_key` | varchar(96) | NOT NULL; `unique(inventory_id, idempotency_key)` named `inventory_movements_idempotency_unique` |
| `reservation_id` | FK → `inventory_reservations`, RESTRICT, nullable | NOT NULL **iff** `reason = sale` (CHECK) |
| `count_id` | FK → `inventory_counts`, RESTRICT, nullable | NOT NULL **iff** `reason = count_adjustment` (CHECK) |
| `corrects_movement_id` | FK → `inventory_movements`, RESTRICT, nullable | NOT NULL **iff** `reason = correction` (CHECK) |
| `actor_type` | varchar(16) | `system` iff `sale`, otherwise `store_owner` (CHECK) |
| `actor_user_id` | bigint, **no FK**, nullable | NULL iff `actor_type = system` (CHECK) |
| `note` | text, nullable | NOT NULL for `receipt`, `write_off`, `correction` (CHECK); non-blank (service) |
| `after_baseline` | boolean | NOT NULL. The value of `inventories.is_baselined` read **under this transaction's Inventory row lock**. It must be true for every reason except `sale` (CHECK), because control operations require a baseline |
| `created_at` | timestamp | NOT NULL; no `updated_at` (append-only) |

`quantity_delta` is bigint (not int) because a count adjustment may span up
to the full `on_hand` range. Operator-entered quantities (`receive`,
`writeOff`) are nonetheless bounded to `1 … 2 147 483 647`, the same portable
ceiling as `order_items.quantity` (`OrderCreationService::MAX_QUANTITY`).

**Reasons:**

| Reason | Sign | Canonical producer | Idempotency identity | Correlation | State it changes | Reversible? | Correction is | Required evidence | Zero? | Arbitrary negative? |
|---|---|---|---|---|---|---|---|---|---|---|
| `receipt` | + | `InventoryControlService::receive` | `client:<key>` from the caller | caller key + note | `on_hand += q` | yes, by `correction` (only within `available`) | a `correction` of −q | actor + note | no | no |
| `sale` | − | `InventoryReservationService::commit` (through the writer) | `sale:reservation:<id>` (deterministic) | `reservation_id` | `on_hand −= q` **and** `reserved −= q` (EXISTING statement) | **no** — a committed reservation is terminal | never corrected; the physical inverse is a return (§6.8) | the committed reservation | no | no |
| `write_off` | − | `InventoryControlService::writeOff` | `client:<key>` | caller key + note | `on_hand −= q` only if `available ≥ q` | yes, by `correction` of +q | a `correction` of +q | actor + note | no | no |
| `count_adjustment` | ± | `InventoryCountService::submitCount` (only for status `applied` with variance ≠ 0) | `count:<count_id>` (deterministic) | `count_id` | `on_hand := observed` under `on_hand = expected` | not by reversal (a count is corrected by a recount, §5) | a later count | the count row (expected, observed, values at submission, actor) | no (zero variance ⇒ no movement) | no — the value is determined by the observation |
| `correction` | opposite of the original | `InventoryControlService::correct` | `correction:movement:<id>` (deterministic, so at most one per original) | `corrects_movement_id` | the original's `on_hand` effect exactly reversed, under that direction's guard | no (a correction is not correctable) | — | actor + note | no | no — exactly `−original.quantity_delta` |

There is **no `restock` reason** until P8 is decided (§6.8). No state is
created without a producer (master prompt §24). There is no `opening_balance`
reason (P6, §8).

### 4.3 `inventory_counts`

| Column | Type | Rule |
|---|---|---|
| `id` | bigint | |
| `inventory_id` | FK → `inventories`, RESTRICT | NOT NULL |
| `idempotency_key` | varchar(96) | NOT NULL, `unique(inventory_id, idempotency_key)` named `inventory_counts_idempotency_unique` |
| `expected_on_hand` | bigint | ≥ 0 — the authoritative value the operator counted *against* (their snapshot) |
| `observed_quantity` | bigint | ≥ 0 — units physically observed |
| `on_hand_at_submission` | bigint | ≥ 0 — authoritative `on_hand` read under the row lock when the count was applied |
| `reserved_at_submission` | bigint | ≥ 0, ≤ `on_hand_at_submission` |
| `status` | varchar(24) | `applied` \| `stale` \| `discrepancy_open` \| `discrepancy_resolved` |
| `resolved_by_count_id` | FK → `inventory_counts`, RESTRICT, nullable | only for `discrepancy_resolved` |
| `resolved_at` | timestamp, nullable | only for `discrepancy_resolved` |
| `actor_type`, `actor_user_id` | as §4.5 | `store_owner`, NOT NULL |
| `note` | text, nullable | optional; the observation itself is the evidence |
| `created_at` | timestamp | NOT NULL |

**Status evidence CHECK:** the stored facts must imply the status.

```
(status = 'stale'                AND expected_on_hand <> on_hand_at_submission
                                  AND resolved_by_count_id IS NULL AND resolved_at IS NULL)
OR (status = 'applied'           AND expected_on_hand =  on_hand_at_submission
                                  AND observed_quantity >= reserved_at_submission
                                  AND resolved_by_count_id IS NULL AND resolved_at IS NULL)
OR (status = 'discrepancy_open'  AND expected_on_hand =  on_hand_at_submission
                                  AND observed_quantity <  reserved_at_submission
                                  AND resolved_by_count_id IS NULL AND resolved_at IS NULL)
OR (status = 'discrepancy_resolved' AND expected_on_hand = on_hand_at_submission
                                  AND observed_quantity <  reserved_at_submission
                                  AND resolved_by_count_id IS NOT NULL AND resolved_at IS NOT NULL)
```

This keeps two things apart:

- **quantity observed physically** (`observed_quantity`, and the snapshot
  `expected_on_hand` it was counted against);
- **authoritative `on_hand` at the moment the observation is applied**
  (`on_hand_at_submission`, `reserved_at_submission`).

### 4.4 `inventory_baselines`

| Column | Type | Rule |
|---|---|---|
| `id` | bigint | |
| `inventory_id` | FK → `inventories`, RESTRICT | NOT NULL, **unique**: at most one baseline per Inventory |
| `kind` | varchar(16) | `genesis` \| `adopted` |
| `on_hand_at_baseline` | bigint | `genesis` ⇒ 0 |
| `reserved_at_baseline` | bigint | `genesis` ⇒ 0; ≤ `on_hand_at_baseline` |
| `actor_type`, `actor_user_id` | as §4.5 | `store_owner`, NOT NULL |
| `note` | text, nullable | NOT NULL for `adopted` |
| `created_at` | timestamp | NOT NULL |

Both quantities are read from the Inventory row **by the lock statement
itself** (a current read), never computed from other rows.

Kind CHECK: `(kind='genesis' AND on_hand_at_baseline=0 AND reserved_at_baseline=0) OR (kind='adopted' AND on_hand_at_baseline>=0 AND reserved_at_baseline>=0 AND reserved_at_baseline<=on_hand_at_baseline AND note IS NOT NULL)`.

**Provenance is persisted, never inferred** (the EXISTING `orders.is_line_backed`
precedent, `ORDER-ITEMS.md` §4). It lives in two places, written in the same
transaction:

- the **baseline row** carries the evidence (actor, quantities, note);
- the **`inventories.is_baselined` flag** is what a writer reads under the
  Inventory lock it already holds (§8.4).

`is_baselined = true` ⇔ exactly one baseline row exists. The checker
verifies this (§15.2).

### 4.5 Actor evidence (P5) — PROPOSED

`actor_type` (`store_owner` | `system`) + `actor_user_id` (the `users.id` of
the acting Store owner), with **no foreign key**.

| Alternative | What survives a User deletion | Why not chosen |
|---|---|---|
| FK RESTRICT to `users` | everything | makes stock history a condition on whether a User may be deleted; couples account lifecycle to Inventory |
| FK SET NULL (precedent: `payment_recovery_actions.admin_id`) | the row, but **not who acted** — the actor becomes indistinguishable from `system` | mutates an append-only evidence row; loses the operational identity; would need the `nullOnDelete` ban in `InventoryBoundaryTest` lifted |
| FK CASCADE | nothing | forbidden (`CLAUDE.md` §6) |
| **No FK, id snapshot** (chosen) | the row and the acting identity (as an id) | after deletion, the id no longer resolves to a name or email. That is the one piece of evidence lost, and it is not a stock fact |
| No FK, id + name/email snapshot | row, id and a human-readable identity | copies personal data into permanent history. That is a privacy-policy decision this repository has not made (not invented here; §20, O-6) |

Facts relied on, argued and **not proven**: MySQL 8, MariaDB 10.2.4+ and
PostgreSQL sequences do not reuse a deleted row's auto-increment id under
default settings, so an `actor_user_id` never silently comes to mean a
different User. The design does **not** rely on the EXISTING
`stores.user_id` CASCADE → `products.store_id` RESTRICT chain that currently
makes a Store owner with Products undeletable. History survives either way.

### 4.6 Reason and evidence text (P9) — PROPOSED

The **minimum** representation:

- a closed `reason` code (the table in §4.2);
- a **mandatory, non-blank `note`** on every manual stock-changing operation
  (`receive`, `writeOff`, `correct`, `adopt`);
- an optional note on counts, whose observation is itself the evidence.

There is deliberately **no** damage/breakage/loss taxonomy. Extending it later
is additive and rewrites no history. Either:

- (a) new `reason` values (e.g. a future `write_off_damage`), where existing
  rows stay `write_off`; or
- (b) a new nullable classification column, where existing rows read NULL,
  meaning "unclassified at the time".

The choice between (a) and (b) belongs to whoever defines the taxonomy (§20, O-5).

### 4.7 What is deliberately *not* a movement

| Event | Why no movement |
|---|---|
| `reserve`, `release` | `on_hand` does not change; the reservation row is its own append-once evidence with timestamps (EXISTING) |
| `createInventory` | creates the row at `on_hand = 0`; the `genesis` baseline is the evidence |
| `adopt` | changes no quantity; the `adopted` baseline is the evidence |
| a `stale` or `discrepancy_open` count, or an `applied` count with zero variance | no `on_hand` change; the count row is the evidence |

## 5. Append-only history and corrections — PROPOSED

- **Movements are append-only.** The model refuses every Eloquent write (the
  `perform*` guards, like `OrderItem`). There is no update/delete code
  (architecture scan), and the FKs are RESTRICT.
- **Baselines are append-once.**
- **Counts are append-once, except for one CAS transition**
  (`discrepancy_open → discrepancy_resolved`, §16).
- **A wrong movement is never edited or deleted.** It is compensated by a
  new `correction` row:
  - `corrects_movement_id` → the original (FK RESTRICT). The original
    therefore cannot be deleted while it has a correction.
  - `quantity_delta = −original.quantity_delta`. It is a full reversal; the
    right value is then entered as an ordinary new movement.
  - **Correctable reasons: `receipt`, `write_off` only.**
    - `sale` is never corrected: a committed reservation is terminal, and a
      generic correction must not undo a sale; the physical inverse is a
      return (§6.8).
    - `count_adjustment` is not reversed: it is the effect of a physical
      observation, and reversing it would reassert a value the observation
      showed to be wrong. The remedy is a new count.
    - A `correction` is not correctable.
  - **At most one correction per movement, durably:** the key is the
    deterministic `correction:movement:<original id>` under
    `unique(inventory_id, idempotency_key)`. A second, different correction
    request hits the same key and is refused as an idempotency conflict. An
    identical retry replays.
  - The correction runs under the **guard of its direction**:
    - reversing a receipt is a decrease, allowed only if `available ≥ q`, so
      stock that has since been reserved is never un-received;
    - reversing a write-off is an increase, under the overflow guard.
    - A refused correction changes nothing (fail closed).
- **Why this is justified for stock, not copied from finance:** an edited
  quantity would silently rewrite the evidence that §15 reconciles against,
  and a deleted receipt would make a real physical arrival disappear. Neither
  is recoverable from any other source.

## 6. Operations — PROPOSED

### 6.0 Rules common to every control operation

1. **Validation first, touching no database.**
   - Quantities must be a real PHP `int` (no float, numeric string or bool;
     never coerced, as in `OrderCreationService`).
   - `receive`/`writeOff` quantities: `1 … 2 147 483 647`.
   - A count's `expected_on_hand` and `observed_quantity`:
     `0 … 9 223 372 036 854 775 807`.
   - Notes: non-blank, bounded length.
   - Caller keys: `^[a-z0-9][a-z0-9_-]{0,63}$` (§9.2).
2. **Actor.** Every control operation takes a `StoreOwnerActor` (a User id).
   - The service re-reads `products.store_id → stores.user_id` and refuses
     unless the actor is the owner of the Store that owns the Inventory's
     Product (P4).
   - This is a domain precondition, not RBAC. Authentication, HTTP policy and
     any future non-owner staff or Admin override are separate, future work
     (§20, O-7).
3. **Product ↔ Inventory correlation.** Callers address an Inventory by its
   Product. `correct()` also takes the Product and refuses if the original
   movement's Inventory is not that Product's Inventory.
4. **Baseline required.** `receive`, `writeOff`, `correct` and `submitCount`
   refuse an Inventory with no baseline (`InventoryNotBaselinedException`):
   legacy stock must be adopted first (§8).
   - The decision uses `is_baselined` **as read by the lock statement in step
     6** (a current read), never a separate plain read.
   - The refusal rolls the transaction back.
5. **Replay pre-check** runs *before* the mutating transaction opens (an
   autocommit read). It reads `(inventory_id, idempotency_key)`:
   - an identical fingerprint returns the stored result with `replayed = true`;
   - any other fingerprint throws `InventoryIdempotencyConflictException`.
6. **The Inventory lock comes before any write**
   (`SELECT id, on_hand_quantity, reserved_quantity, is_baselined FROM
   inventories WHERE id = ? FOR UPDATE`, by primary key). The order is in
   §10.2.
   - Every value any decision or evidence row needs from the Inventory is
     taken from this statement's result, which is a current read on MySQL,
     MariaDB and PostgreSQL (§10.5).
   - No decision depends on when the transaction's read view was created, so
     a caller-owned or test (`RefreshDatabase`) transaction is not a
     correctness problem.
7. **The unique claim insert comes before any counter change.** A duplicate
   therefore fails before touching a counter, as `reserve` does (EXISTING).
8. **Every counter change is one conditional UPDATE whose affected-row count
   is the answer**, never a comparison in PHP. The guard predicates:

   | Direction | Predicate |
   |---|---|
   | increase | `on_hand_quantity <= :max − :q` |
   | decrease | `on_hand_quantity − reserved_quantity >= :q` |
   | count | `on_hand_quantity = :expected AND reserved_quantity <= :observed` |

   `affected ≠ 1` throws, and the whole transaction, including the claim,
   rolls back.
9. **Retry**, bounded (the EXISTING `MAX_RETRY_ATTEMPTS = 8` pattern), only
   for:
   - this operation's own unique-claim violation, classified by SQLSTATE,
     driver code and the named constraint; the retry re-runs step 5 and
     replays;
   - the MariaDB `HY000`/`1020` snapshot conflict (EXISTING classifier).

   Every other error propagates. Deadlocks, lock-wait timeouts and
   serialization failures are **not** retried (`CLAUDE.md` §4). The caller
   may repeat the same key safely.
10. **Refusals are not memoized.** A refused operation (insufficient
    available, overflow, missing baseline, wrong actor) leaves no row.
    Repeating the same key later re-evaluates against the state of that later
    moment. Idempotency covers *effects*, not refusals. A count's `stale` and
    `discrepancy_open` are **outcomes**, not refusals: they are stored and
    replayed (§6.7).
11. **No HTTP, no Payment/Wallet/Order-lifecycle dependency and no price or
    currency read.** The EXISTING INVENTORY-15/16 scans apply to every new
    file.

### 6.1 `createInventory(Product|int $product, StoreOwnerActor $actor): Inventory`

| | |
|---|---|
| Effect | inserts `inventories (product_id, on_hand_quantity = 0, reserved_quantity = 0, is_baselined = true)` **and** an `inventory_baselines (kind = genesis, 0, 0)` row in one transaction (P6/P11) |
| Identity | `unique(inventories.product_id)` (EXISTING). No key is needed |
| Replay | if the Inventory exists **with a `genesis` baseline**, return it (`replayed = true`). If it exists **without a baseline** (legacy), refuse: it must be adopted, and silently returning it would hide its unevidenced state |
| Refusals | Product missing or soft-deleted; actor does not own the Product's Store |
| Lock order | no existing Inventory row to lock. `INSERT inventories` takes the `products` FK shared lock, which is never followed by a request for an exclusive lock on `products`, so there is no upgrade and no cycle. Then `INSERT inventory_baselines` (the FK to its own uncommitted parent row) |
| Lost race | `unique(product_id)` violation → whole transaction rolls back → retry → replay |
| Not here | Catalog/`ProductService` never creates an Inventory (P11; `CatalogDomainBoundaryTest` keeps banning it). An inactive Product may be stocked: `is_active` is catalog visibility, never inventory (CATALOG-07) |

### 6.2 `adopt(Product|int $product, StoreOwnerActor $actor, string $note): InventoryBaseline`

This is for an EXISTING Inventory row that has **no baseline** (P7).

| | |
|---|---|
| Effect | records `inventory_baselines (kind = adopted, on_hand_at_baseline, reserved_at_baseline)` and flips `inventories.is_baselined` from false to true. **Changes no quantity and fabricates no movement** |
| Identity | `unique(inventory_baselines.inventory_id)`, plus the flag CAS |
| Fingerprint | `actor_user_id`, `note` |
| Replay | an existing `adopted` baseline with the same fingerprint is returned; a different fingerprint throws `InventoryIdempotencyConflictException`; an existing `genesis` baseline is refused (it is not legacy) |
| Order | 1. `FOR UPDATE` on the Inventory row: `on_hand`, `reserved` and `is_baselined` as a current read. If the flag is already true, go to replay. 2. `INSERT inventory_baselines` (the claim) with the locked `on_hand`/`reserved`. 3. `UPDATE inventories SET is_baselined = true WHERE id = ? AND is_baselined = false` (CAS; `affected ≠ 1` ⇒ `InventoryIntegrityException`, impossible under the lock unless corrupt) |
| No aggregate read, no top-level requirement | adoption reads no other row and sums nothing. Movements that pre-date it are excluded from the reconciliation by their own stamp (`after_baseline = false`), not by a sum captured at adoption (§8.4). It is safe inside a caller-owned or `RefreshDatabase` transaction |
| Operational note | adoption asserts nothing about physical truth. The recommended next step is a physical count (§6.7), whose activation is gated (§7.5), or reviewing the stock before any receipt or write-off. Neither is enforced |

### 6.3 `receive(Product|int $product, int $quantity, string $key, StoreOwnerActor $actor, string $note): MovementResult`

| | |
|---|---|
| Effect | `on_hand += q`; `reserved` unchanged; movement `receipt`, `+q` |
| Identity | `client:<key>`; fingerprint `(reason, |delta|, actor_user_id, note)` |
| Order | lock Inventory (X) → `INSERT inventory_movements` (claim) → `UPDATE inventories SET on_hand_quantity = on_hand_quantity + :q, updated_at = :now WHERE id = :id AND on_hand_quantity <= :max − :q` |
| Refusals | overflow (`affected = 0` → `InventoryQuantityCeilingException`); no baseline; actor |
| Currency / cost / supplier / PO | none. Nothing about origin is modeled beyond the note (no Supplier or PurchaseOrder exists) |
| Future originator | an authorized Store owner, through a future endpoint. None is created here |

### 6.4 Sale — the change inside `InventoryReservationService::commit()`

EXISTING per-reservation order (`transition()`): the Inventory row X lock → CAS
`UPDATE inventory_reservations … WHERE id = ? AND status = 'reserved'` → only
the winner moves the counters (`on_hand −= q, reserved −= q WHERE reserved ≥ q
AND on_hand ≥ q`).

**PROPOSED:** the winner's counter statement moves into the movement writer
(§13), **unchanged**. In the same call, immediately after it succeeds, the writer
inserts:

```
inventory_movements(inventory_id = reservation.inventory_id, reason = 'sale',
                    quantity_delta = −reservation.quantity, reservation_id = reservation.id,
                    idempotency_key = 'sale:reservation:<reservation.id>',
                    actor_type = 'system', actor_user_id = NULL,
                    after_baseline = <inventories.is_baselined read under this transaction's lock>,
                    created_at = <the same $now as committed_at>)
```

**Where the flag comes from:** `consumeReservation` obtains `is_baselined`
with a locking read (`FOR UPDATE`) of the Inventory row this transaction
already holds exclusively. That adds no wait. It is a current read on every
engine, the same property the EXISTING loser re-read relies on and the
real-engine suite already proved necessary and sufficient (INVENTORY-10). The
EXISTING `transition()` lock statement may alternatively be changed to select
the flag instead of `id`. Either way, its architecture pin changes
deliberately (§22.13).

The resulting chain is:

```
Inventory X lock → reservation CAS (the claim) → counter UPDATE → sale INSERT
```

It is all inside the EXISTING order-level transaction, so all lines or none.
This rules out each failure mode:

| Requirement | Mechanism |
|---|---|
| no decrement without a movement, and no movement without a decrement | one method performs both, in one transaction; any failure rolls back both **and** the CAS |
| no duplicate sale movement | only the CAS winner reaches the writer; `unique(inventory_id, 'sale:reservation:<id>')` is the backstop |
| no duplicate commit, and a retry never consumes twice | EXISTING CAS: a repeated commit loses it and is a same-state no-op, which means zero counter change and zero movement |
| deterministic correlation | `reservation_id` (FK RESTRICT) + the key derived from it. A reservation belongs to exactly one Inventory, so the per-Inventory scope is sufficient |

**A unique violation on a sale key is corruption, never a replay.** A winner
of the `reserved → committed` CAS can only already have a sale movement if the
reservation's status was raw-flipped back. The violation surfaces as
`InventoryIntegrityException` and rolls back. It is never classified as
retryable or benign.

A commit on an **unbaselined legacy** Inventory still records its sale
movement, stamped `after_baseline = false`. The EXISTING commit behavior is
not narrowed. §8.3–§8.4 explain why this is consistent.

**BLOCKED BY RESERVATION/PAYMENT LIFECYCLE DECISION:** *when* commit is
called relative to payment (and by whom). Nothing is wired. The mechanism can
be implemented and tested with `commit()` still having no production caller.

### 6.5 `writeOff(Product|int $product, int $quantity, string $key, StoreOwnerActor $actor, string $note): MovementResult`

| | |
|---|---|
| Effect | `on_hand −= q`; movement `write_off`, `−q` |
| Guard (P2) | **only `available` may be written off**: `WHERE id = :id AND on_hand_quantity − reserved_quantity >= :q` (the same predicate shape as the EXISTING reserve guard). Reserved stock is never consumed silently |
| Order | lock Inventory (X) → `INSERT` (claim) → guarded `UPDATE` |
| Refusal | `affected = 0` → `InsufficientStockException::forWriteOff` → rollback. Nothing is recorded |
| Physical loss inside reserved stock | not a write-off. It is observed through a count and becomes a discrepancy (P1, §7.3). A count shortage is never converted into a write-off automatically |

### 6.6 `correct(Product|int $product, int $movementId, StoreOwnerActor $actor, string $note): MovementResult`

| | |
|---|---|
| Preconditions | the original exists and belongs to this Product's Inventory; its reason is `receipt` or `write_off` |
| Identity | `correction:movement:<movementId>`; fingerprint `(corrects_movement_id, actor_user_id, note)` |
| Order | read the original (plain read; it is immutable) → pre-check → lock Inventory (X) → `INSERT` correction (claim; the FK to the original takes a shared lock on an immutable row that no one ever locks exclusively) → guarded UPDATE in the reversing direction |
| Effect | receipt original → decrease under the `available` guard; write-off original → increase under the overflow guard |

### 6.7 `submitCount(Product|int $product, int $expectedOnHand, int $observedQuantity, string $key, StoreOwnerActor $actor, ?string $note): CountResult`

Order inside one transaction:

1. `SELECT id, on_hand_quantity, reserved_quantity … FOR UPDATE` → `H`
   (on_hand) and `R` (reserved), as a current read.
2. Decide the status from the locked row, in this order:
   - `H ≠ expected` → `stale`;
   - else `observed < R` → `discrepancy_open`;
   - else `applied`.
3. `INSERT inventory_counts (…, on_hand_at_submission = H, reserved_at_submission = R, status)` (the claim).
4. Only if `applied` and `observed ≠ H`: the writer runs `UPDATE inventories SET
   on_hand_quantity = :observed WHERE id = :id AND on_hand_quantity = :expected
   AND reserved_quantity <= :observed` (`affected ≠ 1` ⇒
   `InventoryIntegrityException`, which is impossible under the lock unless
   corrupt), then `INSERT inventory_movements (reason = count_adjustment,
   quantity_delta = observed − H, count_id, key = 'count:<count id>')`.
5. Only if `applied`: `UPDATE inventory_counts SET status =
   'discrepancy_resolved', resolved_by_count_id = :new, resolved_at = :now
   WHERE inventory_id = :id AND status = 'discrepancy_open'` (a CAS; §16).

`stale` and `discrepancy_*` outcomes **are durable results, not refusals.** The
observation is preserved as evidence (P1: "do not hide the observation by
rejecting it without evidence"). A replay of the same key returns the stored
outcome. A re-count needs a new key.

Fingerprint: `(expected_on_hand, observed_quantity, actor_user_id, note)`.

### 6.8 Returns and restock — PRODUCT DECISION REQUIRED (P8)

What the contract fixes:

```
financial refund  ≠  physical return  ≠  restock
```

- A **refund** is a Wallet/Payment fact (EXISTING: `PaymentEventProcessor::applyRefunded()`,
  full refunds only). It never changes stock, and Inventory never reads it
  (STOCK-15).
- A **physical return** is goods coming back. A return may have no refund,
  and a refund may have no return.
- **Restock** is returned goods re-entering `on_hand` because they are
  resellable. A non-resellable return does not restock.

The repository cannot decide:

- the restock ceiling (Σ restocked ≤ committed quantity per reservation?);
- whether correlation with a committed reservation is mandatory;
- how a non-resellable return is recorded (no stock effect, or restock
  followed by write-off);
- repeated or partial physical returns.

Until then there is **no `restock` reason and no return operation.** The
correlation column it would need (a nullable FK to `inventory_reservations`,
or reuse of `reservation_id` with a new reason) is additive later. No RMA,
return authorization or refund integration is invented.

Interim, stated honestly: goods that physically come back enter `on_hand` only
through an evidenced operation the operator chooses (a count). They are never
entered as a `receipt` presented as a return.

### 6.9 Manual adjustment — deliberately **not** an operation

Every legitimate manual change maps onto an operation with a specific guard:

| Intent | Operation |
|---|---|
| goods arrived | `receive` (+, overflow guard) |
| goods lost, damaged or broken, within `available` | `writeOff` (−, `available` guard) |
| a recorded receipt or write-off was wrong | `correct` (exact reversal) |
| "the shelf says N" | `submitCount` (snapshot CAS) |

A generic `adjust(±q)` would have no semantic guard. A negative adjust
could silently consume reserved stock; a positive one could invent stock
with no physical event. `setStock(n)` is the stale-snapshot defect of §7.1.
Both stay banned (the EXISTING `setStock`/`set_stock` scan).

The distinction the master prompt asks for:

- **operational adjustment** = a delta caused by a physical event the operator
  asserts (`receive`, `writeOff`);
- **physical-count observation** = an absolute observation, applied as a
  delta only under a verified snapshot.

## 7. Physical counts

### 7.1 P3 — stale snapshots: strategy chosen, **PROPOSED**

A plain `physicalCount(product, absoluteQuantity)` is rejected, because it
cannot tell a count taken before a concurrent movement from one taken after it.

| Strategy | How it works | Verdict |
|---|---|---|
| Absolute set | `on_hand := observed` | **rejected**: silently undoes any movement between the count and its submission |
| **`expected_on_hand` CAS** (chosen) | the count carries the authoritative `on_hand` it was counted against; applied only if `on_hand = expected` under the row lock, otherwise recorded as `stale` | simplest; verifies the snapshot against the authoritative value; one comparison under a lock plus a conditional UPDATE, with equivalent semantics on MySQL, MariaDB and PostgreSQL |
| Variance relative to the snapshot | `on_hand += observed − expected`, whatever happened in between | **rejected**: silently assumes every intermediate system movement was physically reflected before the count (the exact assumption in doubt, §7.4), and trusts an unverified client-supplied `expected` |
| Count session / watermark token | the server issues a token at count start; any movement since invalidates it | **rejected for now**: an extra stateful entity, and its watermark depends on id ordering (§3.3). It only adds detection of A→B→A, which is harmless for value (case E below) |
| Freeze | block operations while counting | **rejected**: blocks selling; not needed for correctness |

**Demonstration.** At t0 the operator reads `on_hand = 10`, counts the shelf
and finds **9**. Then, before submission:

| Case | Between count and submission | `on_hand` at submission | Absolute set would give | CAS outcome |
|---|---|---|---|---|
| A | sale commits 1 | 9 | 9 — **the sale's decrement is undone** | `stale` → re-count; nothing changes |
| B | receipt of 5 | 15 | 9 — **the receipt is erased** | `stale` |
| C | write-off of 2 | 8 | 9 — **the write-off is undone (+2 phantom units)** | `stale` |
| D | a reservation of 3 is made (moves no goods) | 10 | 9 | `applied`: `on_hand 10 → 9`, if 9 ≥ reserved; correct, because reservations move no physical units |
| E | sale 1, then receipt 1 (A→B→A) | 10 | 9 | `applied` → 9. Physical 9 − 1 + 1 = 9: **value-correct** |
| F | a second count of the same snapshot was applied first with variance ≠ 0 | ≠ 10 | — | `stale` |

In cases A, B and C the concurrent operation is **never** undone. The system
does not guess: the observation is kept as a `stale` row, with both the
observed and the authoritative values, and the operator re-counts. In case F
with zero variance for the first count, two human observations of the same
snapshot disagree. Both rows are kept, the later one is applied, and the
system cannot adjudicate between people.

**Trust boundary:** `expected_on_hand` is client-reported. An operator who
reports the *current* value while having counted earlier defeats the CAS.
That is inherent to any snapshot scheme, and the row records exactly what was
claimed (§18, T-5).

### 7.2 Count outcomes

| Outcome | `on_hand` | Movement | Discrepancies |
|---|---|---|---|
| `stale` | unchanged | none | untouched |
| `discrepancy_open` | unchanged | none | a new open one |
| `applied`, variance 0 | unchanged | none | all open ones for the Inventory → `resolved` |
| `applied`, variance ≠ 0 | `:= observed` | `count_adjustment` of `observed − H` | all open ones → `resolved` |

A new Inventory's **initial stocktake** is simply a count with
`expected_on_hand = 0` (§8.1).

### 7.3 P1 — count below reservations: **A + B, PROPOSED**

Case: `on_hand = 10, reserved = 7`, count observes 5.

- `on_hand` is **not** changed. Writing 5 would violate `reserved ≤ on_hand`
  (the EXISTING CHECK would refuse it anyway), so Policy C is rejected.
- The observation is **preserved** as a `discrepancy_open` count
  (`observed 5, on_hand_at_submission 10, reserved_at_submission 7`).
  Policy A alone (reject without evidence) is rejected.
- **No reservation is released, cancelled or modified.**
- It is **not** converted into a write-off (P2). The operator *may* separately
  write off units they know are lost from `available`, with their own
  evidence. That is a deliberate operator action, never automatic.
- **Resolution:** only a later `applied` count on the same Inventory, which
  proves physical ≥ reserved at that point, resolves open discrepancies (§16).

How it gets there (units found, stock received, or reservations resolved) is
not decided here:

- resolving it by releasing reservations is **BLOCKED** (§21);
- the reservation lifecycle owns `release()`, which has no production caller.

**Consequence the fixed policy does not remove, and which needs a decision.**
While the discrepancy is open, the system still reports `available = 3`
(10 − 7), although physically there are 5 units and 7 are promised. New
reservations can still take those 3 phantom units, deepening the physical
shortage. Stopping that requires one of:

- blocking `reserve` while an open discrepancy exists, which changes the
  EXISTING reserve guard. That would be a deliberate change of the reservation
  contract (`INVENTORY-RESERVATIONS.md`) with new real-engine proof, never an
  incidental one;
- an explicit operational response policy, if the risk is accepted.

Partial application (Policy D) is excluded, because P1 was fixed as A+B.

**PRODUCT DECISION REQUIRED (§20, O-1).** Nothing here changes the reserve
guard. Until O-1 is decided, counting is **not activated** (§7.5).

### 7.5 Physical-count activation gate — PROPOSED boundary

Three things are kept distinct:

| Concern | Status | May `feat/inventory-control` do it? |
|---|---|---|
| **Evidence and state machine** of counts (`inventory_counts`, `submitCount`, the CAS, `stale`/`discrepancy_*`, resolution, checker findings) | PROPOSED | **yes**: implement, test and prove on real engines. It is independent of how O-1 is answered: every O-1 option leaves the count's outcomes and state machine as designed |
| **Operational activation** of physical counting (any production caller of `InventoryCountService`: endpoint, command, job, listener, admin tool) | **PRODUCT DECISION REQUIRED (O-1)** + **BLOCKED (O-8, §7.4)** | **no**: `InventoryCountService` stays out of every caller allowlist |
| **Selling/reservation policy while a discrepancy is open** | PRODUCT DECISION REQUIRED (O-1) | no. The EXISTING reserve guard is not touched |

**Boundary chosen: A.** `submitCount()` is implemented, but it has no
production caller until O-1 is decided and the commit-timing question behind
O-8 is resolved.

- **Rejected, B** (leave counting out of the first branch): it would postpone
  the one part of the design with the most concurrency surface (count vs
  commit, count vs reserve) and its real-engine proof, for no safety gain,
  since an uncalled service carries no operational risk.
- **Rejected, C** (activate only counts that cannot produce a discrepancy):
  refusing or hiding a below-reserved observation contradicts P1.

Wording rule for every document and PR:

- detecting a known physical discrepancy is **not** an operational feature
  until the system has an explicit policy for what happens next;
- `feat/inventory-control` must describe counts as *implemented and proven,
  not activated*.

The gate is enforced mechanically there: `InventoryCountService` is added to
the Architecture caller scan with an **empty** allowlist, and adding a caller
is a deliberate change that must cite the O-1 decision (§22.13).

Observation, not a decision: an initial stocktake of a fresh `genesis`
Inventory (`expected_on_hand = 0`) can never produce a discrepancy, because
`reserved ≤ on_hand = 0`. A future branch may weigh activating that narrow case
first. That is not decided here.

### 7.4 Units committed but not yet physically dispatched — **BLOCKED**

`on_hand` means "present and **not yet consumed**" (EXISTING), and `commit`
consumes. If `commit` is ever called before goods physically leave (e.g. at
payment time), committed units sit on the shelf but are no longer in
`on_hand`. A counter who counts them inflates `on_hand` through a positive
`count_adjustment`. That creates phantom stock and a later oversell.

Whether this can happen depends on **when commit is called**, which is
**BLOCKED BY RESERVATION/PAYMENT LIFECYCLE DECISION**, and on a future
Fulfilment that would know what is awaiting dispatch. Until then, the
operational rule for counts is: *count only units not allocated to committed
Orders*. The system cannot verify that rule.

## 8. Opening stock and legacy state

### 8.1 New Inventory (P6/P11) — PROPOSED

- `createInventory` creates `on_hand = 0, reserved = 0` + a `genesis` baseline.
- **The first increase must be an evidenced operation.** Either:
  - `receive`, when goods physically arrive; or
  - an **initial stocktake** (`submitCount` with `expected_on_hand = 0`),
    when goods are already on the shelf. The `count_adjustment` then carries
    the observation as its evidence. It is available only once counting is
    activated (§7.5); until then `receive` is the only activatable first
    increase.
- There is no `opening_balance` reason and no stock set at creation.

For a genesis Inventory, the equation `on_hand = Σ movements` holds from its
first instant (§15).

### 8.2 Legacy Inventory (P7) — PROPOSED

An Inventory row created before `feat/inventory-control`, necessarily by a raw
write (no canonical creator exists today), has no baseline.

- **No migration or backfill** creates baselines or movements for it. History
  is not invented (`CLAUDE.md` §9; `ORDER-ITEMS.md` §4 precedent).
- It keeps working for reservations exactly as today (EXISTING contract).
- Control operations refuse it until `adopt()`.
- `adopt()` records `kind = adopted` plus the authoritative values **at
  adoption time**. It is an explicit, attributed statement: "evidence starts
  here", not "this was always true".
- The checker distinguishes three classes:
  - `genesis`: fully evidenced since creation;
  - `adopted`: evidenced since adoption, and **nothing is claimed** before it;
  - `unbaselined`: evidence cannot be evaluated at all.

  For the second class, `on_hand = Σ movements since genesis` is **never**
  asserted.
- We cannot infer from a local database whether legacy rows exist in any
  deployment (`CLAUDE.md` §6). The mechanism exists for whichever case is real.

### 8.3 Why pre-adoption sale movements are consistent

Between deploying `feat/inventory-control` and adopting a legacy Inventory,
`commit` records `sale` movements on it (§6.4). Control operations refuse it,
so **only `sale` rows can pre-date its baseline**. Each is stamped
`after_baseline = false` at insert, from the flag read under the same lock
that serializes it against `adopt()`. The equation (§15.1) sums only
`after_baseline = true` rows. No sum is captured at adoption, and no ordering
between rows is needed.

### 8.4 Adoption design: options compared (decision)

The question is how the reconciliation excludes movements that pre-date an
adopted baseline, without weakening auditability.

- **A — previous draft.** The baseline stores `movement_sum_at_baseline`,
  computed by a plain `SUM` after the Inventory X lock, and `adopt()` must run
  top-level.
- **B — cutoff by durable identity (chosen).** The baseline captures only the
  locked row's state. Every movement carries its own `after_baseline` stamp,
  taken from `inventories.is_baselined` read under the lock the writer
  already holds.
- **C — other.**
  - **C1:** make `commit` refuse unbaselined Inventories, so no pre-baseline
    movement can exist.
  - **C2:** a cutoff by `last_movement_id_at_baseline`.
  - **C3:** a nullable `baseline_id` on movements, found by a locking read of
    `inventory_baselines`.

| | A | **B** | C1 | C2 | C3 |
|---|---|---|---|---|---|
| Correctness (MySQL / MariaDB RR) | only if the read view is created after the lock (the first consistent read comes after `FOR UPDATE`) | the flag is read by a locking read: a current read, so always the committed value | correct | needs `MAX(id)` (a plain read, same premise as A) **and** id order = commit order per Inventory | correct, but see deadlocks |
| Correctness (PostgreSQL RC) | per-statement snapshot: correct | locking read returns the latest version: correct | correct | same premises as A | correct |
| PostgreSQL RR (non-default) | lock fails `40001`: fail closed | same | same | same | same |
| Read-view dependency | **yes: a new, unproven engine premise** | **none new.** It relies only on "a locking read is a current read", which the EXISTING design already depends on and which is proven on all three engines (INVENTORY-10; the loser re-read mutation probe) | none | yes, plus an id-ordering premise | none |
| MariaDB `innodb_snapshot_isolation` | the plain SUM is unaffected, but the premise is unproven | correct either way. If `adopt()` changed the row after the commit transaction's read view was created, that transaction's EXISTING Inventory `FOR UPDATE` fails with `1020` and is retried from clean (EXISTING classifier). Otherwise the locking read returns the current value. No stale flag can be read | — | as A | as B |
| Concurrency with commit | serialized on the Inventory X lock; the SUM must see the prior sale | serialized. A sale committed before adoption is stamped `false` and its effect is inside `on_hand_at_baseline`; a sale after adoption is stamped `true` and counted. Both orders give an exact equation | — | as A | **deadlock risk on MySQL/MariaDB**: a locking read of a *missing* baseline takes a gap lock on the unique index that can cover neighbouring Inventory ids, and a multi-line commit (Inventory ascending) can close a cycle with an `adopt()` of another Inventory |
| Crash safety | one transaction | one transaction (flag CAS + baseline INSERT together) | — | one transaction | one transaction |
| Proving the equation | exact if the premise holds | exact: `on_hand = on_hand_at_baseline + Σ delta WHERE after_baseline` | exact | premise-dependent | exact |
| `RefreshDatabase` / caller-owned transactions | **incompatible**: must refuse nested calls | compatible | compatible | incompatible, as A | compatible |
| Reservation contract | unchanged | unchanged (commit's behavior; only its evidence gets the stamp) | **changed**: commit gains a refusal, and legacy holds become uncommittable. That is an incidental reservation redesign, **rejected** | unchanged | unchanged |
| Complexity | one aggregate column + a top-level rule + a new real-engine proof obligation | one flag column on `inventories` (a deliberate schema-pin change) + one stamp column on movements + one locking read in the writer | smallest schema, largest contract change | one column + two premises | one nullable FK + gap-lock analysis |

**Decision: B.** It removes the engine-dependent read-view premise entirely,
instead of turning it into an acceptance criterion. It keeps adoption usable
inside caller-owned and test transactions and changes no reservation behavior.
Its only cost is one deliberate column on `inventories`, justified above, and
one stamp per movement.

Auditability is not weakened. The pre-baseline sale rows stay in history and
remain individually checked against their reservations (§15.2). They are
merely outside the equation, exactly as the legacy period before them is.

`movement_sum_at_baseline` and the "`adopt()` must be top-level" rule are
**removed** from this design.

## 9. Idempotency — PROPOSED

### 9.1 Threat and home

The threat: request → DB commits → response lost → caller retries. The
idempotency identity therefore lives **in the database, on the evidence row
itself**, under a unique constraint. It is never in a controller, a cache or
memory. Every key column is **NOT NULL**. No uniqueness depends on NULL
semantics, which `DATABASE-SUPPORT.md` §15 lists as not exercised per engine.

A `unique(reservation_id)` backstop was considered and **rejected**. On an
engine or configuration treating NULLs as not distinct, it would make every
second non-sale movement fail.

| Operation | Stored identity | Unique scope | Fingerprint compared on replay |
|---|---|---|---|
| `createInventory` | `product_id` | `inventories.unique(product_id)` (EXISTING) | none (no payload; replay only if a `genesis` baseline exists) |
| `adopt` | `inventory_id` | `inventory_baselines.unique(inventory_id)` | `actor_user_id`, `note` |
| `receive` | `client:<key>` | `(inventory_id, idempotency_key)` | `reason`, `|quantity_delta|`, `actor_user_id`, `note` |
| `writeOff` | `client:<key>` | same | same |
| `correct` | `correction:movement:<id>` | same | `corrects_movement_id`, `actor_user_id`, `note` |
| `submitCount` | `client:<key>` | `inventory_counts (inventory_id, idempotency_key)` | `expected_on_hand`, `observed_quantity`, `actor_user_id`, `note` |
| `count_adjustment` | `count:<count id>` | movements `(inventory_id, idempotency_key)` | derived from the count row; a violation is corruption |
| `sale` | `sale:reservation:<id>` | same | derived; a violation is corruption (§6.4) |
| `reserve`/`commit`/`release` | EXISTING (`unique(order_item_id)`; status CAS) | — | — |

**The fingerprint is the stored row itself.** Replay compares the listed
columns exactly; no hash or serialization format is involved.

- An identical replay returns the original row and effect (`replayed = true`),
  even if `on_hand` has changed since. The result means "your effect
  happened", not "current state".
- The same key with any differing field throws
  `InventoryIdempotencyConflictException`, fails closed, and changes nothing.
- The same caller key used for a `receive` and then a `writeOff` on the same
  Inventory maps to the same stored key, so it is a conflict, not a second
  operation.
- Counts have their own table, so a count and a movement with the same caller
  key do not conflict. This is stated, not hidden.

### 9.2 Key grammar — collation-independent by construction

Caller keys must match `^[a-z0-9][a-z0-9_-]{0,63}$`. They are rejected, never
normalized.

MySQL and MariaDB default collations are case-insensitive, and MariaDB's
default is PAD SPACE. PostgreSQL compares bytes. An unrestricted key could be
unique on one engine and a duplicate on another, which would be a portability
defect in an idempotency guarantee. Lowercase ASCII without spaces compares
identically everywhere.

Caller keys contain no `:`, and every stored caller key is prefixed `client:`.
A caller therefore **cannot** forge `sale:…`, `count:…` or `correction:…` and
pre-empt a system identity.

The scope is per Inventory. The same caller key on two different Products'
Inventories identifies two independent operations. Every operation targets
exactly one Inventory, so no cross-Inventory identity is needed.

### 9.3 Lost unique race

The loser's transaction is **rolled back completely**; nothing continues
inside it after the error (PostgreSQL abort semantics, `CLAUDE.md` §4). The
bounded retry re-runs the pre-check, now seeing the winner's committed row,
and replays. If it is still absent, it runs the operation in a fresh
transaction.

As with EXISTING INVENTORY-04, a caller that wraps the call in its own
REPEATABLE READ transaction may see the retry fail closed instead of converge.
It never double-applies. Operator operations have no composition need, so
top-level calls are the expected use.

## 10. Concurrency and lock order — PROPOSED

### 10.1 Global order

```
1. inventories rows, FOR UPDATE by primary key, ascending inventory id      (parent first — always)
2. inventory_reservations rows of those inventories                          (EXISTING, only in commit/release)
3. evidence inserts (claim) and guarded counter UPDATEs, in the per-operation order of §10.2
4. inventory_counts discrepancy resolution UPDATE                            (counts only, same Inventory)
```

Control operations lock **exactly one** Inventory row per transaction. A
"bulk stocktake" is N independent, idempotent `submitCount` calls, never one
multi-Inventory transaction. It therefore cannot form a cycle with the
EXISTING multi-line reserve, commit or release, which lock several Inventory
rows in ascending order.

### 10.2 Exact order per operation

| Operation | Statements, in order |
|---|---|
| reserve (EXISTING) | per line in (inventory id, order item id) order: Inventory `FOR UPDATE` → `INSERT` reservation (claim) → guarded `UPDATE inventories` (reserved) |
| release (EXISTING) | per reservation, ascending: Inventory `FOR UPDATE` → reservation CAS → `UPDATE inventories` (reserved) |
| commit (EXISTING + PROPOSED) | per reservation, ascending: Inventory `FOR UPDATE` → reservation CAS (claim) → guarded `UPDATE inventories` (on_hand and reserved) → locking re-read of `is_baselined` on the already-held row → **`INSERT` sale movement** |
| createInventory | `INSERT inventories` (claim, new row, `is_baselined = true`) → `INSERT inventory_baselines` |
| adopt | Inventory `FOR UPDATE` (reads on_hand, reserved, flag) → `INSERT inventory_baselines` (claim) → flag CAS `UPDATE inventories … WHERE is_baselined = false` |
| receive / writeOff | Inventory `FOR UPDATE` → `INSERT` movement (claim) → guarded `UPDATE inventories` |
| correct | (plain read of the immutable original, before the transaction) → Inventory `FOR UPDATE` → `INSERT` correction (claim) → guarded `UPDATE inventories` |
| submitCount | Inventory `FOR UPDATE` (reads H, R) → `INSERT inventory_counts` (claim) → [applied, variance ≠ 0] guarded `UPDATE inventories` → `INSERT` count_adjustment → [applied] `UPDATE inventory_counts … WHERE status = 'discrepancy_open'` |

### 10.3 Reconciliation with the real FK deadlocks

The EXISTING deadlocks came from: a child INSERT (or FK-index change) taking a
**shared** lock on its `inventories` parent, followed by the same
transaction's request for the parent's **exclusive** lock. With two
transactions each holding S, both waited for X.

In every operation above, **the exclusive parent lock is taken first.** A child
INSERT's FK check then requests a shared lock on a row this transaction already
holds exclusively. That is compatible, so there is no upgrade and no cycle.

This is why "claim INSERT → conditional UPDATE" is written everywhere as
"**parent X lock** → claim INSERT → conditional UPDATE". It never means an
INSERT without the parent lock.

The other FK targets:

| FK target | Why it is safe |
|---|---|
| `inventory_reservations` (sale) | the row is already exclusively locked by this transaction's own CAS UPDATE |
| `inventory_counts` (count_adjustment, `resolved_by_count_id`) | the target is this transaction's own new row |
| `inventory_movements` (correction) | an immutable row that no writer ever locks exclusively |
| `products` (createInventory) | the shared lock is never followed by an exclusive request on `products` in the same transaction; `ProductService` never touches `inventories` |

PostgreSQL uses `FOR KEY SHARE` for FK checks. It conflicts with another
transaction's `FOR UPDATE` on the same row, which again is always taken first by
anyone who writes. **Argued, not proven. Every scenario below must be executed
on real engines (§22.16).**

### 10.4 Pairwise matrix (same Inventory unless stated)

| Pair | Rows locked | Predicate / CAS / unique | Loser semantics | Retry | Deadlock risk |
|---|---|---|---|---|---|
| reserve vs receipt | Inventory X (both) | reserve guard; overflow guard | none; serialized. Reserve after a receipt sees the higher `on_hand` | — | none (one row; multi-line reserve is ascending) |
| reserve vs write-off | Inventory X | both use `on_hand − reserved ≥ q` | the later statement is re-evaluated against the committed winner; it either fits or refuses. `reserved + written_off ≤ on_hand` always | caller may retry | none |
| reserve vs count | Inventory X | count CAS on `on_hand` only; `observed ≥ R` uses the post-reserve R | a reserve first may turn `applied` into `discrepancy_open`; a count first lowers `on_hand`, and reserve re-evaluates against it | — | none |
| commit vs write-off | Inventory X, then reservation (commit) | commit leaves `available` unchanged; write-off guard | serialized; both succeed if their predicates hold | — | none (write-off never locks reservations) |
| commit vs count | Inventory X | the count CAS fails if the commit ran first → `stale` (§7.1 A); a count first keeps `observed ≥ R ≥ q`, so the commit's predicate holds | stale ⇒ re-count | new key | none |
| release vs count / adjustment | Inventory X | release lowers R only | ordering decides applied vs discrepancy; both correct | — | none |
| two receipts, same key | Inventory X (B waits) | `unique(inventory_id, key)` | B's INSERT violates → full rollback → retry → replay of A | bounded (own claim) | none |
| two receipts, different keys | Inventory X | overflow guard | both apply, serialized | — | none |
| two counts, same key | Inventory X | `unique` on counts | replay | bounded | none |
| two counts, different keys, same snapshot | Inventory X | `on_hand = expected` | the second is `stale` if the first changed `on_hand` (§7.1 F) | new key | none |
| two write-offs | Inventory X | `available ≥ q` | the later one refuses if it no longer fits | caller | none |
| two corrections of one movement | Inventory X | deterministic key | the second replays (same fingerprint) or conflicts | — | none |
| create vs create | new row / `products` S | `unique(product_id)` | full rollback → retry → replay | bounded | none |
| adopt vs adopt | Inventory X | `unique(inventory_id)` on baselines | replay or conflict | bounded | none |
| adopt vs commit | Inventory X | flag CAS; the stamp comes from a locking read | serialized. A commit first ⇒ its sale is stamped `false` and its effect is inside `on_hand_at_baseline`; an adopt first ⇒ the sale is stamped `true`. Exact in both orders (§8.4) | MariaDB `1020` via the EXISTING retry | none |
| multi-line commit of Order 1 vs control operation on one of its Inventories | Inventory rows ascending vs a single row | — | serialized on that row | — | none: a single-row locker cannot close a cycle |

### 10.5 Read rule: no decision from a plain read

Every value that decides an outcome or is written into an evidence row comes
from one of two places:

- a **locking read** of the Inventory row this transaction holds (`on_hand`,
  `reserved`, `is_baselined`);
- the **predicate** of a conditional UPDATE.

Plain reads are used only for rows that never change once written (the
original movement of a correction; replay pre-checks, whose miss is caught by
the unique claim).

The engine property relied upon is therefore only this: **a locking read and
a conditional UPDATE see the latest committed row version after the lock is
granted.** It is the same property the EXISTING design rests on:

- MySQL/MariaDB REPEATABLE READ: locking reads are current reads.
- MariaDB `innodb_snapshot_isolation`: fails with `1020` instead, which is
  classified and retried.
- PostgreSQL READ COMMITTED: re-checks after the wait.
- PostgreSQL REPEATABLE READ, non-default: fails with `40001`, closed.

That property is already proven for Inventory on all three engines
(INVENTORY-10, the loser re-read mutation probe, `DATABASE-SUPPORT.md` §6).
**No new read-view premise is introduced.** The previous draft's dependency (a
plain `SUM` inside `adopt()`) is removed by §8.4.

The new operations still need their own real-engine scenarios (§22.16). That
is evidence the implementation must produce, not a premise the design assumes.

### 10.6 Errors

- Deadlock (1213 / 40P01), lock-wait timeout (1205 / 55P03) and serialization
  failure (40001): the transaction has rolled back, so there is no partial
  effect. They surface to the caller and are **not** retried (`CLAUDE.md` §4).
  Repeating the same key is safe.
- The EXISTING MariaDB 1020 retry is extended to the new locking reads, with
  the same narrow classifier.

## 11. Crash safety — PROPOSED

Every operation is **one** database transaction. Crash points:

| # | Point | Receipt / write-off / correction | Count | Commit (+ sale) | Create / adopt |
|---|---|---|---|---|---|
| CP0 | after validation, before the transaction | nothing written; a retry is a first attempt | same | same | same |
| CP1 | after the Inventory lock | rollback; lock released | same | same | same (create has no lock) |
| CP2 | after the claim INSERT | rollback removes the claim | same | CAS rolled back | rollback |
| CP3 | after the counter UPDATE | rollback removes both | same | CAS + counters rolled back | — |
| CP4 | after the second INSERT or UPDATE | — | movement + resolution rolled back | sale INSERT rolled back with the CAS and counters | create: the baseline rolled back with the new Inventory; adopt: the baseline rolled back with the flag CAS |
| CP5 | during COMMIT (unknown outcome) | atomic: all or nothing; a retry with the same key replays or executes once | same | a retry of `commit` loses the CAS if it committed (no-op), or wins if not | a retry replays or creates once |
| CP6 | after COMMIT, before the response | durable; the retry's pre-check finds the row → replay | replay returns the stored status (including `stale`/`discrepancy_open`) | same-state no-op, zero movements | replay |

The two forbidden states cannot be produced by canonical code:

- `on_hand changed, movement absent`;
- `movement exists, on_hand unchanged`.

Both statements are in one transaction, in one writer method. Only raw writes
can produce them, and those are detected by §15.

## 12. Delete and history policy — PROPOSED

| Entity | Policy | Mechanism |
|---|---|---|
| Product | soft delete only (EXISTING CATALOG-08/09); **hard delete refused once an Inventory exists** | EXISTING `inventories.product_id` RESTRICT |
| Inventory | never deleted | EXISTING model guard. PROPOSED: every canonical Inventory has a `genesis` baseline, so the DB refuses its deletion via `inventory_baselines.inventory_id` RESTRICT, plus movements, counts and reservations RESTRICT |
| InventoryMovement | never updated or deleted | model `perform*` guards; no delete code (scan). The self-FK from corrections is RESTRICT. **Raw `DELETE` of an unreferenced movement is not preventable; it is detected** (§15) |
| InventoryCount | append-once + one CAS | guards; RESTRICT from `count_adjustment` movements and `resolved_by_count_id` |
| InventoryBaseline | append-once | guards; unique; RESTRICT |
| InventoryReservation | EXISTING, never deleted | PROPOSED improvement: a `sale` movement's `reservation_id` RESTRICT makes a committed reservation **with evidence** undeletable by the DB. This narrows the EXISTING INVENTORY-18 gap for those rows |
| OrderItem | EXISTING append-once, RESTRICT | unchanged |
| User (actor) | not referenced by FK | deleting a User neither deletes nor mutates stock history (§4.5) |

**Can history disappear by cascade? No.**

- Every FK in the chain (Product ← Inventory ← baseline, movements, counts,
  reservations ← sale movement) is RESTRICT.
- No inventory table has a FK to `users`.
- The EXISTING `stores.user_id` CASCADE stops at `products.store_id` RESTRICT
  before it reaches any stock row.

No GDPR policy is invented.

## 13. Domain boundaries and mutation surface — PROPOSED

| Class | Responsibility | Writes | Callers |
|---|---|---|---|
| `InventoryReservationService` (EXISTING) | holds: reserve / commit / release. Public API unchanged | reservations; `reserved_quantity` (reserve, release); delegates commit's counter + sale evidence to the writer | none in production (BLOCKED, §21) |
| `InventoryControlService` (new) | Inventory lifecycle (`createInventory`, `adopt`) and operator stock events (`receive`, `writeOff`, `correct`) | `inventories` INSERT (creation), `inventory_baselines`; movements via the writer | none in `feat/inventory-control` (no endpoint or UI in scope) |
| `InventoryCountService` (new) | physical-count observations and the discrepancy state machine | `inventory_counts`; `count_adjustment` via the writer | none, and none may be added before O-1/O-8 (STOCK-22) |
| `InventoryMovementWriter` (new, **internal**) | the **only** code that changes `on_hand_quantity` and the **only** inserter of `inventory_movements`. Each method pairs one guarded `on_hand` UPDATE with its one movement, inside the caller's transaction, after the caller's Inventory lock | `inventories.on_hand_quantity` (+ `reserved` for commit only), `inventory_movements` | only the three services above (exact allowlist) |
| `InventoryIntegrityChecker` (EXISTING, extended) | read-only drift detection | nothing | nothing automatic (EXISTING) |

- **Why no god service.** Holds, operator stock events and count observations
  have different identities, state machines and evidence tables. The writer
  exists so that "every `on_hand` change has exactly one movement" is
  **structural**, one method each, rather than a convention repeated in three
  services.
- **Inventory never knows** Stripe, EasyPay, payment methods, Wallet, payout,
  HTTP controllers or frontend state. The EXISTING dependency scan extends to
  every new file.
- **Orders, Payments and a future Fulfilment never write stock.** They may
  only call the service primitives through an allowlisted, deliberately wired
  caller. That is a later decision; the allowlist stays empty in
  `feat/inventory-control`.
- **Primitives for a future Fulfilment:** `commit` (consumption with
  evidence). Future return primitives are pending P8. No picking, packing,
  shipment or tracking concept is introduced.

## 14. Database portability — PROPOSED

| Primitive | MySQL 8.0 | MariaDB 11 | PostgreSQL 16 | What SQLite proves |
|---|---|---|---|---|
| Named CHECKs (sign, correlation, actor, status evidence, baseline kind) | enforced from 8.0.16 | enforced from 10.2.1 | enforced | the EXISTING trigger form (`addChecks` pattern) proves predicate shape only |
| `unique(inventory_id, idempotency_key)`, both NOT NULL | standard | standard | standard | shape; no race |
| Unique-violation classifier | `23000` + `1062` + constraint name | same (distinct engine; verify separately) | `23505` + constraint name | message shape (`UNIQUE constraint failed: …columns`) |
| `FOR UPDATE` by PK | X lock; current read | same, plus the `1020` snapshot conflict (EXISTING retry) | row lock; READ COMMITTED re-check | no-op (single writer) |
| Conditional `UPDATE … WHERE predicate` | current read; re-evaluated | same | EvalPlanQual re-evaluates after the wait | algorithm only |
| Locking read = current read (`is_baselined`, on_hand, reserved; §10.5) | current read (EXISTING premise, proven) | current read, or `1020` → EXISTING retry (proven) | latest version after the wait (proven) | nothing |
| Boolean columns in CHECKs (`after_baseline`, `is_baselined`) | `tinyint(1)`; `TRUE` = 1 | same | native `boolean` | integer; the trigger form must compare to 1/0 explicitly |
| Key collation (§9.2) | case-insensitive default: neutralized by the grammar | PAD SPACE default: neutralized | byte comparison | nothing |
| bigint signed arithmetic, overflow guard | overflow = error 1690: never reached, the predicate refuses first | same | overflow = `22003`: never reached | 64-bit integer |
| `SUM(bigint)` in the checker | DECIMAL result | DECIMAL | numeric | raises on integer overflow |
| Self-FK RESTRICT (`corrects_movement_id`, `resolved_by_count_id`) | yes | yes | yes | `PRAGMA foreign_key_list` |
| Identifier length (≤ 63) | 64 | 64 | 63 (silently truncates) | — ; `DatabaseMigrationPortabilityTest` |

Not used: `INSERT IGNORE`, upserts, `ON CONFLICT`, partial indexes, advisory
locks, `NULLS NOT DISTINCT`, triggers on server engines, and engine-specific
isolation settings. Compilation proves nothing about runtime here
(`CLAUDE.md` §7).

## 15. Integrity and drift detection — PROPOSED

### 15.1 Reconciliation equation

For an Inventory `i` with baseline `b(i)`, and `M⁺(i)` = its movements with
`after_baseline = true`:

```
on_hand(i) = b.on_hand_at_baseline + Σ_{m ∈ M⁺(i)} m.quantity_delta

genesis ⇒ b.on_hand_at_baseline = 0 and every movement of i is in M⁺(i) ⇒ on_hand(i) = Σ_{all m} m.quantity_delta
reserved(i) = Σ quantity of reservations of i with status 'reserved'        (EXISTING)
```

No ordering between rows is used, and no value captured by a plain read is
used. For an Inventory **without** a baseline the equation is **not
evaluated**, and it is reported as `unbaselined`, never as consistent.
Movements with `after_baseline = false` (pre-adoption sales) remain evidence,
checked individually, and are outside the equation by construction.

### 15.2 Findings to add to `InventoryIntegrityChecker`

| Code | Detects |
|---|---|
| `inventory_unbaselined` | legacy Inventory with no evidence start (informational class, not corruption) |
| `on_hand_disagrees_with_evidence` | equation §15.1 fails: a raw `on_hand` edit, or a raw insert or delete of a movement |
| `baseline_invalid` | a genesis baseline with non-zero values (CHECK backstop) |
| `baseline_flag_mismatch` | `is_baselined = true` without exactly one baseline row, or a baseline row with `is_baselined = false` (a raw flag write) |
| `movement_baseline_stamp_invalid` | an `after_baseline = true` movement on an unbaselined Inventory; an `after_baseline = false` movement on a `genesis` Inventory; a non-sale movement with `after_baseline = false` (CHECK backstop) |
| `sale_reservation_not_committed` | a sale movement whose reservation is not `committed` (e.g. a raw status flip) |
| `sale_mismatch` | sale `delta ≠ −reservation.quantity`, or `inventory_id ≠ reservation.inventory_id` |
| `duplicate_sale_evidence` | more than one sale movement per reservation (a raw insert with a forged key) |
| `committed_reservation_without_sale` | **genesis Inventories only**, where every commit post-dates the deployment. For adopted Inventories the equation covers it |
| `correction_invalid` | the original's reason is not correctable, `delta ≠ −original`, a correction of a correction, or more than one correction per original |
| `count_adjustment_invalid` | `delta ≠ observed − on_hand_at_submission`, the count is not `applied`, more than one per count, or an `applied` count with variance ≠ 0 but no adjustment |
| `count_resolution_invalid` | `resolved_by` not an `applied` count of the same Inventory |
| `movement_actor_invalid` | actor type or id inconsistent with the reason (CHECK backstop) |
| *(report section, not a finding)* open discrepancies | `discrepancy_open` counts: operational attention, not corruption |

All EXISTING findings stay. The checker stays read-only, never repairs and
holds no lock.

**Live-database caveat.** The checker reads an Inventory and then its sums in
separate statements. A commit in between can produce a transient false
positive. A finding must be confirmed by an immediate re-check of that
Inventory before it is reported. The EXISTING `counter_disagrees_with_reservations`
has the same exposure today; it is reported here, not fixed (`CLAUDE.md` §11).

### 15.3 What the evidence cannot prove

- **Physical truth.** The equation proves internal consistency from a
  baseline. Only counts observe reality, and only at their moment.
- **Before adoption.** Nothing is known about an adopted Inventory's history
  before its baseline.
- **Coordinated raw tampering.** A raw movement insert plus a matching raw
  `on_hand` edit, or a raw deletion of an unreferenced movement plus a
  compensating `on_hand` edit, keeps the equation true and is **not
  detectable** (§3.3 explains why a chain would not close this). This is the
  same class as the ORDER-ITEMS §4 residual.
- **Point-in-time stock.** `created_at` comes from the application clock, so
  "stock at time T" is an approximation, not a guarantee.

## 16. State machines — PROPOSED

**InventoryReservation:** EXISTING, unchanged (`INVENTORY-RESERVATIONS.md` §6).

**InventoryCount** (`InventoryCountStatus`):

```
insert ──▶ applied                (terminal)
insert ──▶ stale                  (terminal)
insert ──▶ discrepancy_open ──(a later applied count of the same Inventory)──▶ discrepancy_resolved (terminal)
```

| | |
|---|---|
| States | `applied`, `stale`, `discrepancy_open`, `discrepancy_resolved` |
| Valid transitions | the insert-time status (decided under the Inventory lock, §6.7); `discrepancy_open → discrepancy_resolved`, only inside the transaction of an `applied` count on the same Inventory |
| Invalid | any transition out of `applied`, `stale` or `discrepancy_resolved`; `discrepancy_open → applied` or `stale`; resolution by a `stale` or `discrepancy_open` count; resolution by an operator without a re-count; resolution by anything other than `InventoryCountService` |
| Same-state | the resolution UPDATE is a CAS `WHERE status = 'discrepancy_open'`. An already-resolved row is untouched (no-op) |
| Retry | a replay of the same key returns the stored status; it never re-decides. A new observation requires a new key |
| Who | `InventoryCountService` only. Status is never assignable through Eloquent (model guards, EXISTING pattern) |
| Why this is minimal | P1 requires the discrepancy to be preserved and visible until evidence supersedes it. Nothing else has a producer or consumer. There is no `acknowledged`, `dismissed` or `expired` state |

**InventoryMovement, InventoryBaseline:** no states (append-only).
**Inventory:** no status column. The evidence class (genesis / adopted /
unbaselined) is derived from `is_baselined` and the baseline row's `kind`.
The flag's only transition is `false → true`: at creation (genesis) or by
`adopt()`'s CAS. It is never reset.

## 17. Invariant registry (`STOCK-XX`) — all PROPOSED unless stated

A new prefix is used so that nothing proposed can be mistaken for the
EXISTING `INVENTORY-XX`, which stays in `INVENTORY-RESERVATIONS.md`.

| ID | Statement | Owner | Code protection | DB protection | Concurrency | Idempotency | Evidence / test required | Status | Limitations |
|---|---|---|---|---|---|---|---|---|---|
| STOCK-01 | Every change of `on_hand_quantity` is paired, in the same transaction, with exactly one movement whose `quantity_delta` equals the change | `InventoryMovementWriter` | single writer; one UPDATE + one INSERT per method | movement CHECKs; equation (detection) | Inventory X lock | per-reason key | writer unit/feature tests; mutation probe "UPDATE without INSERT"; architecture: only the writer changes `on_hand_quantity` | PROPOSED | raw SQL; coordinated tampering (§15.3) |
| STOCK-02 | Movements, baselines and counts are never updated or deleted by application code; count status changes only by the §16 CAS | models, services | `perform*` guards; no delete code | RESTRICT FKs | — | — | immutability tests per Eloquent style; scans | PROPOSED | raw `DELETE` of an unreferenced movement is detected, not prevented |
| STOCK-03 | A successful control operation has exactly one effect per `(inventory, idempotency key)`; an identical replay returns it; a different payload is refused | services | pre-check + fingerprint | NOT NULL composite unique | unique claim after the lock; full rollback on loss | bounded retry → replay | duplicate / conflict / race tests; real-engine "same key ×3" | PROPOSED | caller-owned RR transaction: fail closed, not converge |
| STOCK-04 | A committed reservation has at most one sale movement, with `delta = −quantity`, on its own Inventory; every commit made after deployment on a genesis Inventory has exactly one | writer via `commit` | CAS winner only | `sale:reservation:<id>` unique; correlation CHECK; RESTRICT | EXISTING CAS + pre-lock | EXISTING same-state no-op | commit / duplicate-commit tests; real-engine commit races extended with movement counts | PROPOSED | pre-deployment commits have no sale evidence |
| STOCK-05 | `receive` never changes `reserved`; `writeOff` and a receipt reversal only consume `available` | writer | fixed statement shapes | EXISTING `reserved ≤ on_hand` CHECK | guarded UPDATE under the lock | — | write-off-exceeds-available tests; real-engine write-off vs reserve | PROPOSED | — |
| STOCK-06 | A count is applied only if `on_hand = expected_on_hand` at application; otherwise it is `stale` and changes nothing | `InventoryCountService` | decision from the locked row | CAS predicate; status-evidence CHECK | X lock | key → stored outcome | P3 scenarios A–F; real-engine count vs commit (both orders) | PROPOSED | `expected` is client-claimed (T-5) |
| STOCK-07 | A count with `observed < reserved` never changes `on_hand` or any reservation; it is kept as `discrepancy_open` until a later applied count | `InventoryCountService` | decision order | status-evidence CHECK; `reserved ≤ on_hand` CHECK | X lock | — | the 10/7/5 test; resolution tests | PROPOSED (mechanism) | new reservations may still consume system-available units (O-1); therefore not activated (STOCK-22) |
| STOCK-08 | `0 ≤ reserved ≤ on_hand` holds after every control operation | all | predicates | EXISTING CHECK (the CHECK itself is EXISTING, INVENTORY-01) | locks | — | CONSERVATION sequence extended with control operations | PROPOSED (for the new operations) | CHECK is a backstop; the predicates are the guarantee |
| STOCK-09 | `on_hand_quantity` never overflows; quantities are strict integers within the stated bounds | services, writer | strict int validation | overflow predicate `on_hand ≤ MAX − q` | guarded UPDATE | — | boundary tests at `MAX`, `MAX − q + 1` | PROPOSED | — |
| STOCK-10 | An Inventory is created only by `InventoryControlService`, at `(0, 0)`, with a genesis baseline in the same transaction; never by Catalog | `InventoryControlService` | single creator | `unique(product_id)`; baseline kind CHECK | unique race → replay | identity replay | creation / race tests; `CatalogDomainBoundaryTest` unchanged | PROPOSED | raw insert produces an unbaselined row (detected) |
| STOCK-11 | An unbaselined Inventory (`is_baselined = false`) is reported as such; control operations refuse it until `adopt()`; adoption changes no quantity and fabricates no movement; every movement's `after_baseline` equals the flag read under its transaction's Inventory lock; no migration backfills evidence | services, writer, checker | the precondition and the stamp both come from the locked row | `unique(inventory_id)` on baselines; flag CAS; stamp CHECK for non-sale reasons | X lock; locking read only (§10.5) — no new engine premise | identity + fingerprint | adoption tests; real-engine adopt vs commit in both orders (§22.16) | PROPOSED | a raw write of the flag or a stamp is detected (`baseline_flag_mismatch`, equation), not prevented |
| STOCK-12 | For every baselined Inventory, equation §15.1 holds | checker (detection) | — | — | — | — | checker tests incl. tamper cases | PROPOSED | §15.3; a live-DB transient needs a re-check |
| STOCK-13 | At most one correction per movement; only `receipt` and `write_off` are correctable; `sale` and `count_adjustment` never are; `delta = −original` | `InventoryControlService` | precondition checks | deterministic key unique; correlation CHECK; self-FK RESTRICT | X lock | replay / conflict | double-correction tests; real-engine duplicate correction | PROPOSED | cross-row delta equality is service- and checker-enforced only |
| STOCK-14 | Stock history is never cascade-deleted or mutated by the deletion of any other entity; no inventory table references `users` | migrations | model guards | all FKs RESTRICT; no actor FK | — | — | FK introspection per engine (`dbForeignKeyInfo`); scans | PROPOSED | — |
| STOCK-15 | Inventory has no Payment/Wallet/provider/HTTP/Order-lifecycle dependency and reads no money; refund ≠ return ≠ restock; nothing outside the allowlist calls the inventory services | domain | dependency scan (EXISTING, extended) | — | — | — | `InventoryBoundaryTest` (extended) | PROPOSED (true today structurally through the EXISTING INVENTORY-13/19 scans) | static scans' blind spots (EXISTING §17) |
| STOCK-16 | Every transaction follows the global lock order of §10.1 | all | fixed statement order | — | the order itself | — | real-engine no-deadlock scenarios; mutation probes removing the pre-lock | PROPOSED | freedom from deadlock is evidence, not proof |
| STOCK-17 | Every manual stock-changing operation carries a Store-owner actor and a non-blank note | services | validation | actor and note CHECKs | — | the note is part of the fingerprint | validation tests | PROPOSED | the note's content is not verified |
| STOCK-18 | A control operation is executed only by the owner of the Store owning the Inventory's Product | services | ownership re-read (domain precondition) | — | — | the actor is part of the fingerprint | actor tests | PROPOSED (the domain check); HTTP authorization: PRODUCT DECISION REQUIRED / future | no RBAC, staff or Admin override |
| STOCK-19 | Idempotency keys compare identically on every supported engine | services | grammar | — | — | — | classifier + grammar tests on real engines | PROPOSED | — |
| STOCK-20 | Restock of physically returned goods | — | — | — | — | — | — | **PRODUCT DECISION REQUIRED** (P8) | — |
| STOCK-21 | Commit (and therefore sale evidence) happens at a defined point of the payment lifecycle | — | — | — | — | — | — | **BLOCKED** (§21) | — |
| STOCK-22 | Physical counting has no production caller until the selling policy for open discrepancies (O-1) is decided and the commit-timing question behind O-8 is resolved; counts are never described as an operational feature before that | architecture boundary | — | — | — | — | `InventoryBoundaryTest`: `InventoryCountService` caller allowlist empty | **PRODUCT DECISION REQUIRED** (activation; O-1) + **BLOCKED** (O-8). The count *mechanism* is PROPOSED under STOCK-06/07 | a static scan, not a runtime gate |

## 18. Threat model — PROPOSED behavior

| # | Threat | Behavior |
|---|---|---|
| T-1 | duplicate request | pre-check replay (§9); zero second effect |
| T-2 | concurrent identical requests | the unique claim after the Inventory lock; the loser rolls back fully, retries, replays |
| T-3 | response lost after commit | CP6: retry → replay of the stored row or outcome |
| T-4 | crash before commit | CP0–CP5: complete rollback; nothing partial exists |
| T-5 | stale client | delta operations are not snapshot-sensitive; counts use the CAS (§7.1). A client lying about `expected` defeats the CAS, but the row records exactly what was claimed |
| T-6 | wrong Product/Inventory correlation | the Inventory is always resolved from the Product; `correct()` checks the original's Inventory; the sale takes `inventory_id` from the reservation; the checker's `sale_mismatch` and EXISTING `inventory_product_mismatch` |
| T-7 | integer overflow | strict-int validation; the `on_hand ≤ MAX − q` predicate; the bigint delta; PHP int arithmetic stays within range |
| T-8 | zero or negative quantity | rejected at validation; `quantity_delta <> 0` and sign CHECKs as backstop |
| T-9 | write-off exceeding available | the guard predicate refuses; nothing recorded; the reserved stock is untouched |
| T-10 | physical count below reservations | `discrepancy_open`; no `on_hand` or reservation change (§7.3) |
| T-11 | duplicate sale commit | EXISTING CAS no-op; zero second movement; the key backstop; a violation is corruption |
| T-12 | repeated correction | deterministic key → replay or conflict; never a second reversal |
| T-13 | Product deletion | soft delete: no stock effect; hard delete refused by the DB once an Inventory exists |
| T-14 | Inventory deletion | model guard; DB refuses via baseline, movements, counts and reservations RESTRICT |
| T-15 | direct or raw DB corruption | CHECKs refuse impossible rows; RESTRICT refuses deleting referenced rows; the checker detects the rest; coordinated tampering is undetectable (§15.3) |
| T-16 | DB deadlock | full rollback; surfaced; not retried; safe to repeat the same key; the lock order is designed to avoid it (§10) |
| T-17 | lock timeout | same as T-16 |
| T-18 | serialization failure (PostgreSQL non-default isolation) | same as T-16; never an incorrect effect |
| T-19 | engine-specific abort (PostgreSQL `25P02` after an error) | the operation never continues in the failed transaction; the retry uses a new one; MariaDB `1020` uses the classified bounded retry |
| T-20 | forged system key | caller keys cannot contain `:` and are prefixed `client:` (§9.2) |
| T-21 | actor not the Store owner | refused before any write (STOCK-18) |
| T-22 | User deletion | no FK: history intact and unmodified; the actor id remains (§4.5) |

## 19. Decision log

| Decision | Alternatives considered | Chosen | Why | Evidence | Consequences | Revisit trigger |
|---|---|---|---|---|---|---|
| Source of truth | A; B (ledger authoritative) | **A** | preserves the proven reserve guard; O(1) | `INVENTORY-RESERVATIONS.md` §7/§11; `DATABASE-SUPPORT.md` §6 | the equation holds from the baseline only | a need for ledger-first semantics (none known) |
| `on_hand_after` / `sequence` | include one or both | **omit both** | the only added property depends on id ordering the DB does not guarantee against the very writers it targets | §3.3 | coordinated tampering stays undetectable | point-in-time or audit-localization requirement |
| Reservation holds as movements | movement per reserve/release | **no** | `on_hand` does not change; reservation rows are the evidence | EXISTING schema | `reserved` evidence stays the reservation table | — |
| Sale evidence | separate sale table; `unique(reservation_id)` | a movement with a deterministic key | one ledger for the equation; no nullable unique | §6.4, §9.1 | commit's proven path gains one INSERT and must be re-proven | — |
| Where the sale INSERT goes | before the CAS; after the counters | CAS → counters → INSERT | the proven prefix unchanged; the CAS is the claim | EXISTING `transition()` | architecture pins change deliberately | real-engine evidence against it |
| Correctable reasons | all; none | `receipt`, `write_off` | a sale is terminal; a count is corrected by a recount | §5 | there is no "undo count" | a product request with a guard |
| Manual adjustment | a generic `adjust(±q)`; `setStock` | none | every intent maps to a guarded operation | §6.9 | the operator picks a semantic operation | an intent not covered |
| Count staleness (P3) | absolute; variance; session token; freeze | `expected_on_hand` CAS | simplest correct; verifies the snapshot; portable | §7.1 | busy Inventories may need re-counts | re-count rate proves impractical (then a token) |
| Count < reserved (P1) | A; B; C; D | **A+B** (fixed by the user) | preserves the CHECK and the evidence | §7.3 | phantom availability until resolved (O-1) | O-1 decision |
| Write-off scope (P2) | allow reserved | `available` only (fixed) | never consume holds silently | §6.5 | a shortage in reserved stock goes through a count | — |
| Actor evidence (P5) | FK RESTRICT; SET NULL; name snapshot | id snapshot, no FK | history independent of the User; no personal-data copy | §4.5 | the id may not resolve after deletion | a privacy policy decision (O-6) |
| Opening stock (P6) | an `opening_balance` reason; stock at creation | 0/0 + receipt or initial stocktake | every increase evidenced | §8.1 | — | — |
| Legacy (P7) | a backfill migration; assume consistent | explicit `adopt()` + baseline row | no invented history; the classes stay distinct | §8.2 | the operator must adopt | — |
| Legacy marker | a baseline row only; a column only | baseline row (evidence) **+** `inventories.is_baselined` (lockable projection) | the writer must learn "baselined?" from the row it already holds locked, never from a plain or gap-locking read of another table | §8.4 | a deliberate `inventories` column-pin change; the flag/row agreement is checked | — |
| Excluding pre-adoption movements | A: `movement_sum_at_baseline` via a plain SUM + top-level `adopt`; C1: commit refuses unbaselined; C2: `last_movement_id` cutoff; C3: `baseline_id` via a locking read of baselines | **B: `after_baseline` stamp from the locked flag** | removes the only new engine read-view premise; compatible with caller and `RefreshDatabase` transactions; C1 redesigns reservations; C2 adds an id-order premise; C3 risks gap-lock deadlocks | §8.4, §10.5 | one stamp column per movement | real-engine evidence that locking reads are not current (would also break EXISTING INVENTORY-10) |
| Count activation (O-1) | B: exclude counts from the first branch; C: activate only counts that cannot produce a discrepancy | **A: implement + prove; no production caller** | the mechanism is independent of the O-1 answer; C contradicts P1; B defers the concurrency proof for no safety gain | §7.5 | STOCK-22; an empty caller allowlist for `InventoryCountService` | the O-1 and O-8 decisions |
| Key scope | global; per Store | per Inventory | local unique index; every operation hits one Inventory | §9.2 | the same key on two Products = two operations | cross-Inventory operations appear |
| Key grammar | free text; normalize | a strict lowercase grammar, rejected otherwise | collation-independent uniqueness | `DATABASE-SUPPORT.md` §9/§15 | clients must lowercase | — |
| Fingerprint | hash of the canonical request | exact stored columns | no serialization format to get wrong | §9.1 | the note is part of the identity | — |
| Refusal memo | store refused attempts | not stored | idempotency covers effects | §6.0.10 | a retry after a refusal re-evaluates | — |
| Service split | one control service | control / count / writer | distinct identities and state machines; structural pairing | §13 | three new classes | — |
| Table names | `stock_*` | `inventory_*` | keeps the EXISTING `stock_\w+` ban | `InventoryBoundaryTest` | — | — |
| Operations on soft-deleted Products | refuse all | refuse only `createInventory` | stock is physical; it must be receivable, writable-off and countable even for a discontinued Product | CATALOG-07 | — | a product rule on discontinued stock |

## 20. Open product decisions

| Open product decision | Why the repository cannot decide it | Options | Consequences | Blocks what? |
|---|---|---|---|---|
| **O-1** Selling while a discrepancy is open | no oversold/backorder policy; P1 fixed only what the count does | (a) accept the risk, with an explicit operational response policy; (b) block `reserve` on an open discrepancy (a deliberate change of the reservation contract + new real-engine proof). Policy D is excluded by the fixed P1 | (a) physical oversell remains possible within the response window; (b) a reservation redesign | **the operational activation of physical counting** (any production caller of `InventoryCountService`, STOCK-22, §7.5). It does **not** block implementing, testing or proving the count mechanism in `feat/inventory-control`. Option (b) would additionally require its own reservation-contract branch |
| **O-2 (P8)** Returns: restock ceiling; mandatory correlation with a committed reservation; non-resellable returns; repeated or partial returns | no return workflow, RMA or partial refunds exist | per sub-question (§6.8) | defines the `restock` reason, its correlation column and its guard | the `restock` reason and any return operation |
| **O-3 (P10)** Digital or unlimited Products | CATALOG-DOMAIN §14.3 is open | every Product needs an Inventory; or an explicit "unlimited" class | inventory control applies only to Products that have an Inventory | a future checkout's reserve step |
| **O-4** How discrepancies are resolved operationally beyond a re-count | depends on releasing reservations | release, cancel or refund flows | — | **BLOCKED** (§21) |
| **O-5 (P9)** A write-off taxonomy (damage/breakage/loss/theft…) | no requirement | new reason values; a classification column | additive either way (§4.6) | nothing |
| **O-6 (P5)** A human-readable actor snapshot (name/email) in history | a privacy/retention policy is not defined | id only (chosen); id + snapshot | personal data in permanent history | nothing |
| **O-7 (P4)** Authorization mechanics: authentication, HTTP policy, Store staff beyond the owner, Admin override | no roles/RBAC; Admin override is a separate feature | — | the domain requires owner = actor until then | the endpoints of a later branch |
| **O-8** Count semantics for committed-but-undispatched units | depends on when commit happens, and on Fulfilment | not designed here: no solution is invented | phantom stock if counted | **BLOCKED BY RESERVATION/PAYMENT LIFECYCLE DECISION and Fulfilment** (§7.4, §21); also gates count activation (STOCK-22) |

## 21. Dependencies on the reservation/payment lifecycle decision

**BLOCKED BY RESERVATION/PAYMENT LIFECYCLE DECISION** (to be resolved in its
own step before Fulfilment; see `INVENTORY-RESERVATIONS.md` §12–§13):

1. **When `commit` (and so the `sale` movement) is called** relative to
   payment, and by which canonical caller. The mechanism (§6.4) is
   implementable; the wiring is not.
2. **Resolving a discrepancy by releasing or cancelling reservations** (O-4):
   `release()` has no production caller, pending the late-payment-after-release
   policy.
3. **Count semantics for committed-but-undispatched units** (§7.4, O-8).
4. **Reservation expiry or cancellation interacting with stock control.** None
   is designed here. Cancellation must use `release()`, never a stock path
   (EXISTING §22).

The *implementation* in `feat/inventory-control` does not depend on a
specific answer to these, provided it wires nothing.

*Activation* does depend on them:

- commit's sale evidence becomes live only when commit gets a caller (item 1);
- physical counting becomes live only after O-1 is decided and item 3 is
  resolved (STOCK-22).

## 22. Implementation blueprint — `feat/inventory-control`

This is a specification for a later branch. **Nothing below exists.**

### 22.1 Scope

- **Implementable now:** §22.2–§22.18.
- **Implementable, but with no production caller (not activated):**
  - every new service (no endpoint or UI is in scope; O-7);
  - specifically `InventoryCountService::submitCount()`, whose activation
    additionally requires O-1 and O-8 (STOCK-22, §7.5);
  - commit's sale evidence, live only when commit gets a caller (§21 item 1).
- **Not implementable until decided:**
  - the `restock` reason and any return operation (O-2);
  - blocking reserve on open discrepancies (O-1);
  - wiring any caller: HTTP endpoints, jobs, payment/checkout integration
    (§21, O-7).
- **Out of scope throughout:** frontend, fulfilment, payment wiring,
  cancellation, Supplier/PurchaseOrder, warehouse/location, SKU/Variant,
  costing/valuation.

### 22.2 Migrations (new only; established migrations untouched, `EstablishedMigrationImmutabilityTest`)

Order matters for FKs. Each migration uses the EXISTING `addChecks` shape: named
`ALTER TABLE … ADD CONSTRAINT … CHECK` on MySQL, MariaDB and PostgreSQL, and
`BEFORE INSERT/UPDATE` triggers on SQLite. Every FK is `restrictOnDelete()`.
There is no `nullOnDelete`, cascade or `softDeletes`.

0. `…_add_is_baselined_to_inventories_table`: `boolean('is_baselined')->default(false)`,
   NOT NULL. Every existing row becomes unbaselined legacy; nothing else is
   written. It is a forward migration: the EXISTING
   `create_inventories_table` stays byte-identical.
1. `…_create_inventory_baselines_table`: §4.4 columns;
   `unique(inventory_id)` named `inventory_baselines_inventory_unique`;
   CHECKs `inventory_baselines_kind_evidence` and `inventory_baselines_actor`.
2. `…_create_inventory_counts_table`: §4.3; `inventory_counts_idempotency_unique`;
   CHECKs `inventory_counts_quantities_valid` (all ≥ 0; reserved ≤ on_hand at
   submission), `inventory_counts_status_evidence` (§4.3) and
   `inventory_counts_actor`; self-FK `resolved_by_count_id`; index
   `(inventory_id, status)`.
3. `…_create_inventory_movements_table`: §4.2;
   `inventory_movements_idempotency_unique`; CHECKs:
   - `inventory_movements_delta_nonzero`;
   - `inventory_movements_reason_sign`:
     `(reason='receipt' AND quantity_delta>0) OR (reason IN ('sale','write_off') AND quantity_delta<0) OR (reason IN ('count_adjustment','correction') AND quantity_delta<>0)`;
   - `inventory_movements_correlation`: exactly the matching correlation
     column is non-NULL for each reason, and all three are NULL for
     `receipt`/`write_off`; this also rejects unknown reasons;
   - `inventory_movements_actor`:
     `(reason='sale' AND actor_type='system' AND actor_user_id IS NULL) OR (reason<>'sale' AND actor_type='store_owner' AND actor_user_id IS NOT NULL)`;
   - `inventory_movements_note_required`:
     `reason NOT IN ('receipt','write_off','correction') OR note IS NOT NULL`;
   - `inventory_movements_baseline_stamp`:
     `reason = 'sale' OR after_baseline = true` (the SQLite trigger compares
     to 1).

   Indexes are on the FK columns (`reservation_id`, `count_id`,
   `corrects_movement_id`).

No change to `inventory_reservations`; the only change to `inventories` is
migration 0. **No data migration or
backfill.** Every identifier must stay ≤ 63 characters
(`DatabaseMigrationPortabilityTest`).

### 22.3 Enums (`app/Domain/Inventory/Enums`)

- `InventoryMovementReason`: `Receipt`, `Sale`, `WriteOff`, `CountAdjustment`,
  `Correction`, with `sign()` and `isCorrectable()`. **No `Restock`.**
- `InventoryCountStatus`: 4 cases + `allowedTransitions()`.
- `InventoryBaselineKind`: `Genesis`, `Adopted`.
- `InventoryActorType`: `StoreOwner`, `System`.

### 22.4 Models

`InventoryMovement`, `InventoryCount`, `InventoryBaseline`: read models. Every
Eloquent write path throws (`performInsert`, `performUpdate`,
`performDeleteOnModel`, `incrementOrDecrement`), as in the EXISTING `Inventory`
model. `$fillable = []`, `UPDATED_AT = null`, no SoftDeletes. Relations are
read-only.

### 22.5 Value objects / DTOs

- `StoreOwnerActor(int $userId)`.
- `MovementResult(InventoryMovement $movement, bool $replayed)`.
- `CountResult(InventoryCount $count, ?InventoryMovement $adjustment, list<int> $resolvedCountIds, bool $replayed)`.

### 22.6 Services and methods

- `InventoryControlService`:
  - `createInventory(Product|int, StoreOwnerActor): Inventory`
  - `adopt(Product|int, StoreOwnerActor, string $note): InventoryBaseline`
  - `receive(Product|int, int $quantity, string $key, StoreOwnerActor, string $note): MovementResult`
  - `writeOff(…same…): MovementResult`
  - `correct(Product|int, int $movementId, StoreOwnerActor, string $note): MovementResult`
- `InventoryCountService::submitCount(Product|int, int $expectedOnHand, int $observedQuantity, string $key, StoreOwnerActor, ?string $note): CountResult`.
- `InventoryMovementWriter` (internal; asserts `DB::transactionLevel() ≥ 1`;
  documented precondition: the caller holds the Inventory X lock):
  - `increase(int $inventoryId, int $q, MovementDraft)`
  - `decreaseAvailable(int $inventoryId, int $q, MovementDraft)`
  - `consumeReservation(InventoryReservation, $now)`
  - `applyCount(int $inventoryId, int $expected, int $observed, int $countId, $now)`

  Each method is one guarded UPDATE plus one INSERT (`consumeReservation`: the
  EXISTING counter statement, then the sale INSERT). Every INSERT's
  `after_baseline` comes from `is_baselined` as returned by a locking read of
  the held Inventory row: the caller's lock statement for control operations,
  or the writer's own `FOR UPDATE` re-read for `consumeReservation`. All SQL uses bound
  parameters.

### 22.7 Change to `InventoryReservationService`

Only `moveCounters()`'s `Committed` branch changes: it delegates to
`InventoryMovementWriter::consumeReservation()`. The release branch, reserve,
lock order, CAS, retry and public API are unchanged. The documented contract
(`INVENTORY-RESERVATIONS.md`) is updated in the same PR.

### 22.8 Exceptions

- New:
  - `InventoryIdempotencyConflictException`;
  - `InventoryNotBaselinedException`;
  - `InventoryQuantityCeilingException`;
  - `InvalidInventoryOperationException` (validation, uncorrectable
    movement, Product/Inventory mismatch, soft-deleted Product on create,
    actor is not the owner).
- Reused: `InsufficientStockException::forWriteOff()`/`forCorrection()`.
- `InventoryIntegrityException` gains `duplicateSaleEvidence()`,
  `countCasFailedUnderLock()` and `baselineFlagCasFailedUnderLock()`.

### 22.9 Idempotency infrastructure

- One narrow classifier per claim constraint (SQLSTATE + driver code +
  constraint/columns), tested against real driver exceptions on all three
  engines (`ExceptionClassificationPortabilityTest`).
- The MariaDB `1020` classifier is shared, not widened. Extracting it into a
  small internal support class is acceptable only with the EXISTING pins
  updated deliberately.

### 22.10 Lock order and transaction boundaries

Exactly §10.1–§10.2. One transaction per operation. The Inventory lock precedes
every write. Every decision or evidence value comes from a locking read or a
conditional UPDATE (§10.5). There is no top-level-only operation. No HTTP or
I/O inside.

### 22.11 Overflow guards

The §6.0 bounds and the `on_hand ≤ MAX − q` predicate. Boundary tests at
`PHP_INT_MAX`, and at `MAX − q` and `MAX − q + 1`.

### 22.12 Integrity checker extension

The §15.2 findings, chunked (EXISTING `chunkById`), read-only. A re-check
before reporting drift. Tests for every finding, including tamper fixtures and
"never repairs".

### 22.13 Deliberate changes to existing contracts (same PR, on purpose)

- **`CLAUDE.md` §5:**
  - replace "`InventoryReservationService` is the only writer" with the
    §13 writer map (`InventoryMovementWriter` is the only `on_hand` writer,
    and the three services own their tables);
  - replace the bullet "Do not add stock movements … incrementally" with a
    pointer to this contract;
  - add the new architecture tests to §12.
- **`tests/Architecture/InventoryBoundaryTest.php`:**
  - expected domain file list (+ new enums, models, services, writer,
    exceptions, DTOs);
  - inventory migrations by name (2 → 6);
  - raw-table allowlist per table (`inventories`: reservation service,
    writer, control service for the creation INSERT;
    `inventory_movements`: writer only; `inventory_counts`: count service;
    `inventory_baselines`: control service);
  - hidden-stock detector gains the new table names;
  - remove `receipt` from the forbidden-concept list, **keep** every other
    entry (`refund`, `setStock`, `adjust`, `location`, `lot`, `batch`,
    `serial`, `supplier`, `warehouse`, `sku`, `variant`, `fulfilment`,
    `cancellation`, `expiry`, …);
  - keep the `stock_\w+` table ban and the Variant/Sku/Warehouse class ban;
  - statement pins updated to the new counts, plus new pins: the writer's
    three predicates, the sale INSERT in `consumeReservation`, the count CAS,
    the discrepancy CAS, the `is_baselined` flag CAS, and the locking read
    that sources `after_baseline`;
  - a new rule: only the writer contains an UPDATE of `on_hand_quantity`;
    only `InventoryControlService` writes `is_baselined`, and only as
    `false → true`;
  - the general caller allowlist **stays empty**;
  - a **dedicated caller scan for `InventoryCountService`**, also empty,
    whose test comment states that adding a caller requires the O-1 decision
    and O-8 (STOCK-22).
- **`tests/Feature/Domain/Inventory/InventorySchemaTest.php`:**
  - the `inventories` pin changes **deliberately** to add `is_baselined`;
  - the `inventory_reservations` pin is unchanged;
  - add exact column lists for the three new tables;
  - extend "starts empty" to them, and "every pre-existing Inventory reads
    `is_baselined = false`";
  - add CHECK refusal tests per constraint.
- **`docs/inventory/INVENTORY-RESERVATIONS.md`:** §3 conservation (sale
  evidence), §10, §16, §21.6 and §22 updated to implemented truth.
- **`tests/Helpers.php`:** `inventorySeed()` keeps producing *unbaselined
  legacy* rows (a legitimate state). Add a canonical-creation helper for new
  tests.
- **`tests/Concurrency/worker.php`:** new actions (`receive`, `writeOff`,
  `count`, `adopt`, `correct`, `create`).

### 22.14 Feature tests (SQLite default suite: algorithm and shape only)

- creation: genesis, idempotent, legacy refusal, soft-deleted refusal, actor
  refusal;
- adoption: values, idempotency, conflict, genesis refusal, works inside a
  caller/`RefreshDatabase` transaction, pre-adoption sales stamped `false` and
  post-adoption sales stamped `true`;
- receipt, write-off and correction: bounds, overflow, available guard,
  replay, conflict, forged key, Product mismatch;
- counts: P3 cases A–F, P1 10/7/5, resolution, zero variance, replay of
  stale/discrepancy;
- commit: sale movement per line, duplicate commit = 0 movements, multi-line
  all-or-nothing including the sale INSERT failure, legacy Inventory;
- CONSERVATION: extend the EXISTING 120-step sequence with control operations,
  checking §2 and §15.1 after every step;
- immutability per Eloquent style for the three new models;
- delete policy via `dbForeignKeyInfo()` + attempted deletes;
- checker findings;
- a SQLite stale-model/competitor simulation, **labelled as simulation**.

### 22.15 Architecture tests

The §22.13 changes. Every new detector gets a positive and a negative
self-test. The scans stay non-vacuous.

### 22.16 Real-engine concurrency scenarios (independent OS processes; MySQL, MariaDB and PostgreSQL, each proven separately; `DATABASE-SUPPORT.md` §6 updated only with executed results)

1. receipt vs reserve (deterministic; B verified blocked) + barrier rounds;
2. write-off vs reserve on the last available unit: exactly one succeeds;
   `reserved + written_off ≤ on_hand`;
3. two write-offs on the last available unit;
4. same-key receipt × 3 workers × N rounds: one movement, one effect;
5. same-key count × 3: one row;
6. count vs commit, both orders: the commit first ⇒ `stale`; the count first ⇒
   the commit still succeeds;
7. count vs reserve: applied vs discrepancy is determined by the order; the
   CHECK is never violated;
8. adopt vs commit, both orders (deterministic, B verified blocked): the sale's
   `after_baseline` stamp matches the serialization order and equation §15.1
   is exact. This is evidence for the new operation, not proof of a new engine
   premise (§10.5);
9. duplicate correction × 3: one reversal;
10. create vs create × 3: one Inventory, one genesis baseline;
11. multi-line commit/release vs control operations on shared Inventories: no
    deadlock;
12. the EXISTING 16 scenarios re-run unchanged, plus assertions: sale
    movements = committed reservations;
13. final audit: zero findings, equation §15.1 holds for every baselined
    Inventory.

The MariaDB lock-wait probe uses the EXISTING `processlist` method.

### 22.17 Migration portability validation

- fresh install on MySQL and MariaDB (baseline + the new migrations) and on
  PostgreSQL (direct replay);
- upgrade/fresh convergence re-diffed for MySQL and MariaDB (DBPORT-02/14);
- FK introspection per engine;
- CHECK enforcement executed by each server engine;
- identifier lengths.

Schema baselines are regenerated only if `DATABASE-SUPPORT.md`'s process
requires it, never with `--prune`.

### 22.18 Mutation probes (each must be caught)

- remove the Inventory pre-lock in a control operation (real-engine deadlock
  or race);
- remove the `available` predicate from write-off;
- remove the overflow predicate;
- remove the count CAS;
- UPDATE `on_hand` without the movement INSERT;
- INSERT the sale before or without the CAS;
- widen the claim classifier;
- allow correcting a `sale`;
- drop `unique(inventory_id, idempotency_key)`;
- source `after_baseline` from a plain read instead of the locking read (the
  adopt-vs-commit scenario must catch it on MySQL/MariaDB REPEATABLE READ);
- let `adopt()` skip the flag CAS.

### 22.19 Definition of done

- Every STOCK-01…19 row moves from PROPOSED to an evidenced status, with
  tests named.
- STOCK-20/21 stay PRODUCT DECISION REQUIRED / BLOCKED.
- STOCK-22 is mechanically pinned (an empty count-caller allowlist), and
  counts are described as "implemented and proven, not activated".
- No caller is wired; frontend is untouched.
- Established migrations are byte-identical.
- All three engines are proven per §22.16–§22.17 before any claim.

## 23. Non-goals (confirmed absent from this design)

Supplier, PurchaseOrder, procurement; warehouse, location, bin, transfers; lot,
batch, serial; SKU, ProductVariant; costing, COGS, valuation, tax, currency on
movements; backorders, negative stock, safety stock; RMA, return authorization,
refund automation; fulfilment (picking, packing, shipping, delivery, tracking,
carriers); cancellation; expiry; payment wiring; any UI, endpoint or frontend
store; RBAC or roles.
