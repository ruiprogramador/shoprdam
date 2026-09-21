# Order Items — shoprdam

Canonical description of what exists after `feat/order-items`. Everything here
is evidenced by production code, a database constraint, or a test cited by
name. Where something is **not** implemented or **not** mechanically enforced,
this document says so.

See also: `docs/catalog/CATALOG-DOMAIN.md` (Product), `docs/financial/ORDER-LIFECYCLE.md`
(status machine), `docs/financial/INVARIANTS.md` (`CROSS-XX`).

## 1. The central contract

    Product   = mutable current catalog state
    OrderItem = immutable historical commercial snapshot

After an Order exists, the application can answer *what exactly did this Order
contain?* — which Product, its name and unit price and currency **as agreed at
order creation**, and the quantity — regardless of what later happens to the
Product (rename, reprice, currency change, deactivation, soft deletion).

## 2. Audit — what existed before this branch

| Order producer | Class | Decision |
|---|---|---|
| `App\Console\Commands\CreateTestStripeOrder` | development/test utility (refuses `production`; refuses non-`sk_test_` keys) | **B — kept as an explicit legacy line-less producer.** Adapting it would mean inventing a throwaway *active* Product in a Store's real catalog just to test a payment provider, and would blur "validation tool" into "customer checkout". Pinned by `OrderItemBoundaryTest`. |
| `Database\Factories\OrderFactory` | factory/test only | **B — kept.** Constructs precondition states directly; that is a factory's job, not a production mutation path. |
| Frontend cart/order scaffolding | frontend scaffolding (`cartStore.js`, mock `ProductCard` data) | untouched; non-authoritative, not converted into domain truth. |
| *(none)* | real production business path | **There is still no customer checkout and no production code that creates an Order.** |

Before this branch `Order.amount` was a bare scalar (`decimal(18,2)`) with a
single `store_id` and a single `currency_id` and **no persisted explanation of
where the amount came from**; nothing in the codebase gave it a meaning other
than "the total to charge" (no tax/shipping/discount concept exists anywhere).
`PaymentService` reads exactly `Order.amount`/`Order.currency` (provider
request validation, Wallet `sale` transaction) and never anything else.

## 3. Schema

`order_items` (migration `2026_09_22_090000_create_order_items_table.php`):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `order_id` | FK → `orders.id` | `restrictOnDelete()`, NOT NULL |
| `product_id` | FK → `products.id` | `restrictOnDelete()`, NOT NULL — traceability only |
| `product_name` | string | snapshot, NOT NULL |
| `unit_price_amount` | `decimal(18,2)` | snapshot, NOT NULL; same representation as every other money column |
| `currency_id` | FK → `currencies.id` | `restrictOnDelete()`, NOT NULL; snapshot |
| `quantity` | unsigned integer | NOT NULL, ≥ 1 (§7) |
| `created_at`, `updated_at` | timestamps | |

Indexes: `order_id`, `product_id`. **No** `line_total_amount` (exactly
`unit_price_amount × quantity`, derived by `OrderItem::lineTotal()` with BCMath,
never stored twice), **no** `unique(order_id, product_id)` (duplicates are merged
by the service; a future line concept must not hit a unique-index migration
trap), no SoftDeletes, and none of sku/variant/tax/discount/shipping/status/
inventory/reservation/fulfillment/supplier/image/slug/metadata
(`OrderItemBoundaryTest` scans for them).

**Provenance column on `orders`** (migration
`2026_09_22_100000_add_is_line_backed_to_orders_table.php`): `is_line_backed`
boolean, **NOT NULL DEFAULT false**, not mass-assignable, no index. It records
*how the Order came to exist* (§4), not whether it has lines right now.

**FK direction.** `order_items` is the *child*. `restrictOnDelete()` on
`order_items.order_id` means deleting the **parent Order is refused** while a
line exists; nothing cascades. Read back from the migrated schema by
`OrderItemSchemaDeletePolicyTest` (`PRAGMA foreign_key_list`) and proven by
attempting each delete (Order, Product, Currency, and the Order's Store via
`orders.store_id`). CROSS-14 is untouched (`orders.store_id`,
`payments.order_id` stay RESTRICT — asserted in the same file).

## 4. Legacy Orders vs line-backed Orders — persistent provenance

`orders.is_line_backed` is the **stable provenance** of an Order. It is *not*
"has lines right now"; the first version of this branch inferred it from line
existence, which was fail-open (a canonical Order that lost every line to a raw
write would have looked legacy and passed the payment gate). No existing field in
the domain had this meaning (audited: no `pricing_mode`/`order_version`/
equivalent), so the minimum addition is one boolean.

- **Legacy Order** (`is_line_backed = false`) — created before this branch, or
  by the dev tool/factories: has **zero** OrderItems and no claim about its
  contents. Nothing is backfilled: no Product identity, name, quantity or unit
  price exists to infer, and a fake `"Legacy Product" × 1 @ Order.amount` line
  would be invented history. Legacy Orders keep working exactly as before
  (payable, fully mutable fixtures).
- **Line-backed Order** (`is_line_backed = true`) — created by
  `OrderCreationService`: ≥ 1 OrderItem and `Order.amount == Σ line totals`. Set
  in the same INSERT/transaction as the Order and its lines.

**Migration default/backfill decision.** `NOT NULL DEFAULT false`. Every Order
that exists when the migration runs receives `false` (unambiguously legacy) and
is otherwise untouched — proven by `OrderProvenanceTest` ("migration backfill":
`down()`, insert an Order, `up()`, the row is legacy with identical
amount/timestamps and no OrderItem exists). There is no NULL state. The only
writer of `true` is `OrderCreationService` (`Order::forceCreate`, because the
flag is deliberately not fillable); an architecture test pins that it is the
only production file writing it and that the dev tool never mentions it, so
factories and the dev tool stay legacy with no change.

**Immutable.** `Order::performUpdate()` refuses any Eloquent change of
`is_line_backed`, in either direction, for every Order. Only a raw write can
alter it.

**The four states the payment gate distinguishes** (`OrderLineIntegrityChecker`):

| Provenance | Lines | Result |
|---|---|---|
| legacy | 0 | allowed (existing compatibility) |
| line-backed | ≥ 1 | amount and currency validated exactly against the snapshots |
| line-backed | 0 | **corrupt → `OrderLineIntegrityException`**, never treated as legacy |
| legacy | ≥ 1 | contradictory provenance → `OrderLineIntegrityException` (also catches a raw flip of the flag while lines remain) |

**Remaining residual, stated plainly.** The flag lives in the same database as
the lines. A raw write that BOTH flips `is_line_backed` to `false` AND deletes
every line produces exactly a legacy Order, indistinguishable from a genuine
one, and is not detectable by this design. That needs two coordinated raw
writes; each alone is detected (`OrderProvenanceTest`). No DB constraint or
trigger closes it (no permission model; §9, §14).

## 5. Canonical creation — `OrderCreationService`

`App\Domain\Orders\Services\OrderCreationService::create(Store $store, array $lines): Order`

Input: a persisted Store and `[['product' => Product|int, 'quantity' => int], ...]`.
The caller supplies **no** name, unit price, line total, currency or
`Order.amount`; an extra key in a line is rejected rather than ignored, and a
passed `Product` instance contributes only its key — its in-memory attributes
are never read (`OrderCreationServiceTest`).

One `DB::transaction`, no external call, no Payment, no provider, no Wallet:

1. validate/normalize the request without touching the database;
2. read every requested Product in **one** query;
3. per Product: exists → not soft-deleted → `is_active` → same Store → well-formed
   stored name/price;
4. one currency across all Products (else reject; **no conversion**);
5. exact line totals and Order total (BCMath), overflow-checked against
   `decimal(18,2)`; the total must be **strictly greater than 0.00**;
6. insert the Order at the existing initial `pending` state, marked
   `is_line_backed`, then all lines in a single multi-row INSERT.

Anything failing at any step leaves **no Order and no OrderItem**.

- **Empty request → rejected** (ORDER-ITEM-17). Only the *new path* is
  non-empty; no DB rule makes legacy zero-item Orders invalid.
- **Order.currency_id** is derived from the Products, never accepted from the
  caller. **Order.store_id** is the given Store, and every Product must belong to it.
- **Duplicates.** The same Product supplied more than once is merged into one
  line with the summed quantity, overflow-checked as integers; lines are written
  in ascending Product-id order, so any ordering/splitting of the same request
  yields identical lines (`OrderCreationServiceTest`).
- **Eligibility.** Only *active, not soft-deleted* Products can be newly
  ordered (`is_active` = "may be offered for new commercial activity"). This is
  catalog visibility, **not** stock — no inventory check exists here.
- **Canonical Order total must be > 0.00.** A zero total is refused inside
  `OrderCreationService` (`InvalidOrderCreationException::nonPositiveTotal`)
  *before* anything is persisted — no Order, no OrderItem, no Payment, no
  Wallet transaction, no provider contact. Reason: there is **no free-order
  settlement policy** (`WalletTransactionService::record()` itself rejects a
  non-positive `sale`, and the only other Order creator, the dev tool, rejects
  `<= 0`), so a canonical Order aimed at the payment pipeline that could never
  settle is not created. This is deliberately conservative and can be relaxed
  only by an explicit free-order policy.
- **Product price 0.00 ≠ a supported zero-total Order/payment flow.** The
  Catalog still accepts a `0.00` Product (an independent Catalog decision,
  `CATALOG-DOMAIN.md` §14). The invariant is on the **Order total**, not on each
  Product: a `0.00` Product beside a positive line is fine (total > 0);
  `0.00` alone, or several `0.00` lines, is refused. Legacy Orders are not
  affected (no behavior change to them in this branch).
- **No Store status/eligibility check — a deferred decision.** The repository
  has no domain contract for "a Store may sell / accept orders" (a Store has a
  `store_status_id` and SoftDeletes, but nothing defines which statuses permit
  commercial activity), so none was invented here. Whether/how order creation
  should consult Store status is a **future decision** for whichever branch
  defines that contract; until then `OrderCreationService` validates only that
  the Store is persisted and owns every Product.

## 6. Snapshot semantics and concurrency

`product_id`, `product_name`, `unit_price_amount`, `currency_id` are copied
from the Product at creation; there is no accessor that re-reads live Product
values, and `OrderItem::product()` exists for traceability only (it includes
trashed Products, and returns the *current* Product, deliberately not the
snapshot). Test: Product `A / 10.00 EUR` → renamed `B`, repriced `25.00 USD`,
deactivated, soft-deleted → the OrderItem and Order are unchanged
(`OrderCreationServiceTest`, `OrderItemPaymentIntegrationTest` B/C/D).

**Coherence guarantee, stated precisely.** Every Product's name, price,
currency, store and active flag come from the same row returned by the same
single SELECT (asserted: exactly one query on `products` per creation), so a
snapshot cannot be *torn* — "old price + new currency" — by a concurrent
`ProductService` write, because the database returns one committed version of
a row. **No row lock is used.** A Product edited after that read but before
commit is indistinguishable from the order having been placed just before the
edit; a lock would add contention and is a no-op on SQLite. **Not claimed:**
cross-Product consistency (Products are independent rows), and a true
two-connection race test (this repository has none, as `INVARIANTS.md` already
states — the guarantee rests on the single-statement read, not on a
demonstrated parallel run).

## 7. Quantity

Positive **integer**, `1 … 2 147 483 647` (the portable signed 32-bit ceiling —
PostgreSQL has no unsigned integer). No fractional quantities: nothing in the
repository indicates fractional products, so none was invented. The service
accepts only a true PHP `int` — floats (even `2.0`), numeric strings, bools and
`null` are rejected, never coerced. Merged duplicates that exceed the ceiling
are rejected.

Database enforcement, honestly: Laravel 12 has no CHECK helper and SQLite cannot
add a CHECK after creation, so the migration is driver-specific — a **named
`CHECK (quantity >= 1)` on MySQL/MariaDB/PostgreSQL**, and on **SQLite** (dev and
test engine) a pair of **BEFORE INSERT/UPDATE triggers** that abort a
non-integer or `< 1` quantity. Both reject the same values; **only the SQLite
mechanism is exercised by this repository's tests** (`OrderItemSchemaDeletePolicyTest`).

## 8. Money

No float. `unit_price_amount` and `Order.amount` are `decimal(18,2)`; line total
is `bcmul(unit_price, quantity, 2)`, the Order total a `bcadd` of those, both
exact and compared against the column ceiling (`9999999999999999.99`) before
persisting. The Product's stored price is read from its **raw** column value
(never the `decimal:2` cast, which would silently round `10.005`) and held to the
same strict grammar `ProductService` enforces on write (`^\d+(\.\d{1,2})?$`);
a malformed/negative/over-precise stored value is **rejected, not repaired**.
SQLite hands numeric values back as native floats, which are converted with
`var_export` (shortest round-trip text) before that check; amounts of roughly
10^15 and above are therefore rejected on SQLite (fail closed), a limit of that
engine's REAL storage, not of MySQL/PostgreSQL.

## 9. Immutability and delete policy

OrderItems are **append-once**: the model refuses every Eloquent write.
`performInsert()`, `performUpdate()` and `performDeleteOnModel()` all throw,
and `$fillable` is empty. They sit on the `perform*` methods, not model events,
for the same reason `Order` and `Product` do — quiet saves and `withoutEvents()`
suppress events but still reach them. Covered by `OrderItemImmutabilityTest`:
`update`, `forceFill`+`save`, attribute+`save`, `updateQuietly`, `saveQuietly`,
`withoutEvents`, mixed update, `touch`, `associate`+`save`, `create`,
`forceCreate`, `$order->items()->create/save`, `delete`, `deleteQuietly`,
`forceDelete`, `destroy`. Because the model refuses instance inserts, **the
service writes lines with one `DB::table('order_items')->insert()`**, and there
is deliberately **no `OrderItemFactory`**. There is no `addLine`/`removeLine`/
`changeQuantity`, and no SoftDeletes — a historical line either exists or it is
a bug.

A **line-backed Order's** `store_id`, `currency_id` and `amount` are also frozen
against Eloquent writes (`Order::performUpdate()`), keyed on the **persistent
`is_line_backed` flag as read from the persisted row** (`getOriginal`, falling
back to a database read if the instance was loaded without the column) — *not*
on whether lines currently exist. The guard therefore does **not** vanish if
the lines are raw-deleted (`OrderProvenanceTest`). Legacy Orders are unaffected.

**What cannot be mechanically prevented:** `DB::table('order_items')`,
`OrderItem::query()->update()`, raw SQL, a migration, or `psql`/tinker. Without
database permissions or triggers this is unpreventable in application code. It
is covered statically (`OrderItemBoundaryTest`: only `OrderCreationService` may
touch the table; no `OrderItem::`/`->items()->` write anywhere) and, for
financial effect, by the drift check in §10. No DB trigger enforces
immutability (a SQLite trigger would not be portable to the supported MySQL/
PostgreSQL, and no DB permission model exists here).

## 10. Payment boundary and fail-closed drift check

    Product ──snapshot once──▶ OrderItem ──exact Σ──▶ Order.amount ──▶ Payment ──▶ Wallet

`PaymentService` still uses **`Order.amount` and `Order.currency`** for the
provider request validation and the Wallet `sale` — **settlement behavior is
unchanged.** It never reads a Product (architecture-scanned: no Product/
`price_amount`/OrderItem reference in Payments, Wallet or Payouts code).

The one addition is a read-only gate, `OrderLineIntegrityChecker::assertConsistent()`,
called at the top of `createDurableAttempt()` (before any Payment/attempt row
exists) and at the start of `claimProviderReference()` (the point that creates
the remote payment and pending Wallet sale, so an attempt row that predates a
drift cannot reach a provider — this also covers reconciliation/recovery
`finalizeAttempt()`). It re-reads the Order's committed provenance/amount/
currency and its lines and applies the four-state table in §4: for a
line-backed Order with lines it requires `Σ lineTotal == Order.amount` (BCMath)
and every line currency == Order currency. Any failure throws
`OrderLineIntegrityException`: **detected, never repaired**, no Payment/
attempt/provider call/Wallet row created. A **legacy** Order with no lines
passes untouched, so legacy Orders never become unpayable; a **line-backed Order
with no lines** is corrupt and refused. Provenance comes from
`orders.is_line_backed`, never from `items()->exists()` (architecture-pinned).
Recovery paths already catch `Throwable`, so a drifted attempt is recorded as a
recovery failure rather than crashing them.

`PaymentService` takes the checker as an optional 5th constructor argument with
a default, because existing tests construct it with four arguments.

Not covered: webhook settlement of an *already-claimed* attempt
(`PaymentEventProcessor`) is unchanged and does not re-verify lines — it already
validates provider amount/currency against the Order at claim time.

## 11. Refunds and inventory

Partial refunds remain unsupported and nothing here adds per-line refunds,
refund quantities or allocation: OrderItems are historical evidence only, and
full-refund semantics stay aggregate Order/Payment/Wallet behavior.
`OrderItem.quantity` is what a future `feat/inventory-reservations` can reserve;
no stock, reservation, expiry, release or committed quantity exists, and nothing
is released on `PaymentAttempt` failure (a future inventory policy).

## 12. Architecture enforcement

- **Runtime:** `OrderItem` `performInsert/performUpdate/performDeleteOnModel`;
  `Order::performUpdate()` aggregate guard for line-backed Orders keyed on the
  persistent `is_line_backed` flag, and immutability of that flag (§4, §9).
- **Database:** three RESTRICT FKs; NOT NULL columns; quantity guard (§7);
  `orders.is_line_backed` NOT NULL DEFAULT false (§4). No DB rule ties the flag
  to the lines or the amount to a positive value.
- **Financial gate:** `OrderLineIntegrityChecker` in `PaymentService` (§10).
- **Static — `tests/Architecture/OrderItemBoundaryTest`:** production roots,
  comments stripped, exact allowlists, synthetic self-tests for every detector,
  and a non-vacuous "real files found" test. It proves, in today's tree: only
  `OrderCreationService` touches `order_items`; no Eloquent/relation/`OrderItem::`
  write anywhere; only the service and the legacy tool create Orders; the tool
  neither uses the service nor invents OrderItems; settlement code never reads
  Product/OrderItem; only the creation service (and its exception's message
  text) knows the Catalog within the Orders domain; excluded fields/concepts are
  absent; no OrderItem→Wallet/Payment dependency; the migration has no
  CASCADE/SoftDeletes/`unique(...)`; **only `Order`, `OrderCreationService` and
  `OrderLineIntegrityChecker` mention `is_line_backed`** (so the dev tool and
  every other producer cannot set or read it); the creation service is the sole
  writer of `'is_line_backed' => true`; the checker and the Order guard never
  infer provenance from `items()->exists()`; the flag is not `$fillable`; the
  migration is `NOT NULL DEFAULT false`.
- `OrderLifecycleBoundaryTest` was **tightened, not loosened**: the "exactly one
  write in the Orders domain" pin became an exact per-file list (lifecycle
  `->update(`; creation service `->insert(`, `::forceCreate(`, `DB::table(`) with
  content assertions (including `'is_line_backed' => true`), and the
  `pending`-only pin now covers both creators.
- **Blind spots:** runtime-assembled table/class names, raw SQL built from
  parts, dynamic dispatch, out-of-repo access (tinker/psql). Regex is not a
  database guarantee.

## 13. Invariants

| ID | Statement | Status | Protection | Test | Remaining gap |
|---|---|---|---|---|---|
| ORDER-ITEM-01 | A persisted OrderItem belongs to exactly one Order | **ENFORCED** | `order_id` NOT NULL + FK RESTRICT | `OrderItemSchemaDeletePolicyTest` | — |
| ORDER-ITEM-02 | …references exactly one Product | **ENFORCED** | `product_id` NOT NULL + FK RESTRICT; duplicates merged by service | same; `OrderCreationServiceTest` (duplicates) | a future line concept may allow repeats (deliberately no unique index) |
| ORDER-ITEM-03 | Snapshot fields are immutable after persistence | **PARTIALLY ENFORCED** | model `perform*` guards; empty `$fillable` | `OrderItemImmutabilityTest` | no DB trigger/permission: raw SQL / `DB::table` / `OrderItem::query()->update()` bypass; static scan only |
| ORDER-ITEM-04 | quantity is a positive integer | **ENFORCED** | service (`int`, 1…2³¹−1); DB CHECK (MySQL/PG) / triggers (SQLite) | `OrderCreationServiceTest`, `OrderItemSchemaDeletePolicyTest` | CHECK path not run by this repo's tests (SQLite only) |
| ORDER-ITEM-05 | unit_price_amount is exact decimal money; no float is authoritative | **PARTIALLY ENFORCED** | `decimal(18,2)`, BCMath, raw-value grammar check on the Product's stored price | `OrderCreationServiceTest` (exact totals, malformed stored price) | SQLite does not enforce decimal scale in raw inserts; ≥ ~10^15 rejected on SQLite (§8) |
| ORDER-ITEM-06 | Every line in a line-backed Order uses Order.currency_id | **PARTIALLY ENFORCED** | derived + mixed refused at creation; Order currency frozen vs Eloquent (persistent flag); drift check compares currencies | `OrderCreationServiceTest`, `OrderItemImmutabilityTest`, `OrderItemPaymentIntegrationTest` (F) | no DB composite constraint; raw tampering detected only when payment starts |
| ORDER-ITEM-07 | Each line's Product belongs to Order.store_id at snapshot time | **ENFORCED** (at creation) | service store check; `Order.store_id` frozen vs Eloquent | `OrderCreationServiceTest` | `order_items` has no store column, so it cannot be re-verified from lines afterwards |
| ORDER-ITEM-08 | Order.amount of a line-backed Order = exact Σ line snapshots | **PARTIALLY ENFORCED** | derived at creation; Eloquent-frozen by persistent provenance; fail-closed gate before Payment/claim (also for a line-backed Order whose lines vanished) | `OrderCreationServiceTest`, `OrderItemImmutabilityTest`, `OrderItemPaymentIntegrationTest` (A, F), `OrderProvenanceTest` | no DB constraint; raw drift is *detected at payment time*, not prevented; a raw flip of `is_line_backed` **plus** deletion of all lines is indistinguishable from legacy (§4) |
| ORDER-ITEM-09 | Product changes after snapshot cannot alter historical OrderItem values | **ENFORCED** | copied columns; no live accessor | `OrderCreationServiceTest` (freeze), `OrderItemPaymentIntegrationTest` (B, C, D) | — |
| ORDER-ITEM-10 | Order + its complete line set are created atomically | **ENFORCED** | one `DB::transaction`, one multi-row insert | `OrderCreationServiceTest` (rollback cases) | a DB *commit* failure is not simulated |
| ORDER-ITEM-11 | Existing legacy Orders are not given fabricated OrderItems, and stay identified as legacy | **ENFORCED** | migration creates an empty table, no backfill; `is_line_backed` NOT NULL DEFAULT false gives every pre-existing/factory/dev-tool Order legacy provenance | `OrderProvenanceTest` (backfill, defaults), `OrderCreationServiceTest`, `OrderItemPaymentIntegrationTest` (G) | a raw write can still set the flag on a legacy row (see ORDER-ITEM-19) |
| ORDER-ITEM-12 | OrderItem history cannot be cascade-deleted through Order/Product/Currency | **ENFORCED** | three RESTRICT FKs | `OrderItemSchemaDeletePolicyTest`, `OrderItemBoundaryTest` | — |
| ORDER-ITEM-13 | Product hard delete is DB-blocked once an OrderItem references it | **ENFORCED** (for referenced Products) | `order_items.product_id` RESTRICT | `OrderItemSchemaDeletePolicyTest` | a Product with no OrderItem has no such backstop (CATALOG-09 stays PARTIALLY ENFORCED) |
| ORDER-ITEM-14 | Payment never derives a historical amount from current Product state | **ENFORCED** | `PaymentService` reads only Order; settlement code has no Product/OrderItem reference | `OrderItemBoundaryTest`, `OrderItemPaymentIntegrationTest` (B) | text scan blind spots (§12) |
| ORDER-ITEM-15 | Catalog/Product mutation creates no retroactive OrderItem mutation | **ENFORCED** | `ProductService` untouched, no Orders dependency; snapshot columns | `OrderCreationServiceTest`, `CatalogDomainBoundaryTest` | — |
| ORDER-ITEM-16 | No inventory semantics are implemented | **ENFORCED** | scan of OrderItem files/migration for excluded concepts | `OrderItemBoundaryTest` | — |
| ORDER-ITEM-17 | New canonical Orders cannot be empty | **ENFORCED** (service) | `OrderCreationService` rejects `[]` | `OrderCreationServiceTest` | deliberately **no** DB rule: legacy zero-item Orders must stay valid. A line-backed Order with zero lines can still be produced by a raw delete — it is then *detected* (ORDER-ITEM-19), not prevented |
| ORDER-ITEM-18 | Production code cannot append/remove/reprice lines outside the canonical creation boundary | **PARTIALLY ENFORCED** | model guards refuse every Eloquent path; static scan for builder/raw writes | `OrderItemImmutabilityTest`, `OrderItemBoundaryTest` | raw SQL / `DB::table` outside the repo's scanned code is not preventable |
| ORDER-ITEM-19 | Line-backed provenance is persistent and never inferred from current line existence; a line-backed Order with zero lines is corrupt, not legacy | **PARTIALLY ENFORCED** | `orders.is_line_backed` NOT NULL DEFAULT false; set atomically with the lines; immutable via Eloquent; not fillable; checker + Order guard key on the flag, never `items()->exists()` (architecture-pinned) | `OrderProvenanceTest`, `OrderItemBoundaryTest` | raw SQL can flip the flag; flag flip **plus** deleting all lines is indistinguishable from legacy; line-backed + zero lines is **detected at payment time** (payment/claim), not prevented, and never flagged if the Order is never paid |
| ORDER-ITEM-20 | A canonical line-backed Order's total is strictly > 0.00 (Product price 0.00 ≠ supported zero-total Order flow) | **ENFORCED** (service) | `OrderCreationService` rejects a total ≤ 0.00 before persisting anything | `OrderCreationServiceTest` (zero-total refused atomically; zero-priced Product + positive line allowed) | no DB constraint: raw SQL can create a zero-amount Order; legacy Orders are unchanged and may hold any amount |

## 14. Known limitations

1. Immutability and aggregate integrity are runtime/static guarantees, **not**
   database guarantees (no triggers/permissions); §9.
2. Provenance is a persistent flag in the same database as the lines. Raw
   deletion of all lines is now **detected** (line-backed + zero lines → fail
   closed at payment), and a raw flag flip with lines remaining is detected
   too — but a raw flip **plus** deletion of every line is indistinguishable
   from a legacy Order (§4). A corrupted Order that is never paid is never
   flagged.
3. The drift check runs at payment start/claim only — it detects, it does not
   prevent, and a drifted Order that is never paid is never flagged. There is no
   standalone audit command.
4. Quantity CHECK on MySQL/PostgreSQL is untested here (SQLite only).
5. No true two-connection concurrency test; the snapshot guarantee is the
   single-statement read (§6).
6. A DB commit failure is not simulated; rollback is by `DB::transaction`.
7. SQLite float storage rejects prices ≥ ~10^15 (fail closed) (§8).
8. Zero-priced Products remain valid in the Catalog, but a zero-**total**
   canonical Order is refused; a free-order settlement policy is an open product
   decision. Store status/eligibility is not consulted at order creation — no
   domain contract defines it (§5, deferred decision).
9. `OrderCreationService` has **no production caller** — there is still no
   checkout. The dev tool and factories still build legacy line-less Orders, so
   "no new arbitrary line-less Orders in production" holds because no production
   path creates Orders at all, not because line-less creation is impossible.
10. `orders.amount`/`currency_id`/`store_id` of a *legacy* Order remain freely
    mutable through Eloquent (fixtures depend on it), and a legacy Order can
    carry any amount, including 0.00 — the > 0.00 rule applies to the canonical
    path only.
11. If variants/SKUs are ever introduced, what `order_items.product_id` points to
    changes (`CATALOG-DOMAIN.md` §13).
