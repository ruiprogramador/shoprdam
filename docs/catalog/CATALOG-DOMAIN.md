# Catalog Domain — shoprdam

Canonical description of what exists after `feat/catalog-domain`. Everything
here is either evidenced by production code, a database constraint, or a test
cited by name. Where something is **not** implemented, this document says so
and says why, rather than describing an aspiration as fact.

See also: `docs/financial/INVARIANTS.md` (`CROSS-XX`), `docs/financial/ARCHITECTURE.md`.

## 1. Scope

This branch establishes the minimum real, persisted answer to "what is a
sellable thing in shoprdam?" — a single `Product` model, owned by exactly one
`Store`, with one exact price in one explicit currency and a visibility flag.
It does **not** implement `OrderItem`, inventory/stock, product variants, SKUs,
categories, images, discounts, ratings, or any catalog frontend — none of
those has repository evidence requiring it yet, and inventing them here would
be guessing at product decisions this branch was not asked to make.

## 2. Audit — what existed before this branch

Verified by reading `resources/js/Components/**/ProductCard.vue`,
`ProductColumns.vue`, `CartDropdown.vue`, `cartStore.js` and grepping `app/`,
`database/` and `routes/`.

**No `Product`, `Vendor`, `OrderItem`, `Category`, `Sku`, or `Variant` model,
migration, table, or factory existed anywhere in the backend.** Every
"product" the frontend renders (`ProductCard.vue`'s `vendor`, `price`,
`oldPrice`, `rating`, `sku` props; `cartStore.js`'s cart-line shape) is
**hardcoded mock data or scaffolding props with no backend model behind
them** — confirmed non-authoritative by the branch's own prior discovery
audit. `Order.amount` is a single scalar with no line items backing it.

**What already existed and this branch builds on directly:** `Store` (owned by
exactly one `User`, `SoftDeletes`, its own `store_status_id` FK
`restrictOnDelete()`); `Currency` (`nnjeim/world`, no soft deletes, no active
flag, `precision` defaulting to 2); `StoreWallet.unique(store_id, currency_id)`
— proof a single Store can hold balances in more than one currency
simultaneously, which is why a Product's currency is never derived from "the
Store's currency" (no such single value exists) and must be its own explicit
column.

## 3. What a Product is (and is not)

**Ownership:** `User` → owns/manages → `Store` → sells → `Product`. A `Product`
belongs to exactly one `Store` (`products.store_id`, `restrictOnDelete()`).
There is **no `Vendor` model** — "vendor" in the old frontend scaffolding is
not authoritative — and **no `Product.user_id`**: a Product's owner is reached
only by traversing `Product → Store → User`.

**Not modeled in this branch, deliberately:** `Supplier` (a different, future
concept); `ProductVariant`/SKU/options; a slug (no public storefront route
exists yet to need one — `stores.slug` is the one precedent, and it is
store-scoped identity, not evidence a Product needs its own); categories;
images/media; discount/`old_price`; ratings/reviews; wishlist/compare; cart;
checkout; a catalog frontend; `OrderItem`; inventory/stock/reservations;
fulfillment/cancellation semantics.

## 4. Schema

`products` (migration `2026_09_21_090000_create_products_table.php`):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `store_id` | FK → `stores.id` | `restrictOnDelete()`; immutable after creation (§6) |
| `name` | string | required |
| `price_amount` | `decimal(18,2)` | commercial intent only (§5) |
| `currency_id` | FK → `currencies.id` | `restrictOnDelete()`; explicit, never inherited |
| `is_active` | boolean, default `true` | catalog **visibility**, never inventory availability (§7) |
| `created_at`, `updated_at` | timestamps | |
| `deleted_at` | nullable timestamp | `SoftDeletes`, mirrors `stores`' own precedent |

Index: `(store_id, is_active)` — the natural shape for a Store's own catalog
listing, mirroring `orders`' `(store_id, order_status_id)` index for the same
reason.

No other column exists. Adding one later (a slug, a SKU, a category) is an
additive migration, not a rework of this one.

## 5. Money and currency correctness

`price_amount` is `decimal(18,2)`, identical precision/scale to
`orders.amount`, `payouts.amount` and `store_wallet_transactions.amount` — the
one canonical money representation this codebase uses everywhere, never a
float. There is no dedicated Money value-object anywhere in this codebase;
validation happens inline, mirroring `App\Console\Commands\CreateTestStripeOrder`'s
own precedent exactly (`^\d+(?:\.\d{1,2})?$`, `bcadd` to normalize) —
`App\Domain\Catalog\Services\ProductService::normalizePrice()`.

**A Product's price is commercial intent, never proof of financial
settlement.** `ProductService` never creates or mutates a `StoreWallet`,
`StoreWalletTransaction`, `Payment`, or calls `WalletTransactionService`/
`PaymentService` — enforced by `CatalogDomainBoundaryTest`'s dependency scan
and exercised directly in `ProductServiceTest`. A future `OrderItem` is
expected to **snapshot** the commercial values (name, price, currency) at the
moment of sale rather than re-reading a Product that may have since changed —
this branch does not implement that snapshot, only states the expectation for
`feat/order-items` to honor.

**Currency is always explicit, never derived.** `StoreWallet`'s own
`unique(store_id, currency_id)` proves a Store can operate more than one
currency at once, so there is no single "the Store's currency" to inherit
from. `price_amount` and `currency_id` change **together, atomically**, via
one `ProductService::changePrice()` method — there is no separate
`changeCurrency()`, because a price amount has no meaning without its
currency; allowing them to move independently would let a caller silently
change what "10.00" means without an explicit decision that the price itself
changed.

## 6. Ownership immutability

`Product.store_id` is set once, at creation, and can never change afterward —
there is no product-transfer operation. Enforced at
`App\Domain\Catalog\Models\Product::performUpdate()`, not the `updating`
model event, for the same reason `App\Models\Order` guards its own status
column that way: `saveQuietly()`, `updateQuietly()` and
`Product::withoutEvents(...)` all suppress events but still route through
`performUpdate()`. Every Eloquent style is tested in `ProductServiceTest`
(`update`, `forceFill`, `fill`, attribute assignment, `saveQuietly`,
`updateQuietly`, `withoutEvents`, `associate`+`save`). A mixed update (e.g.
`store_id` plus `name` in one call) throws before **any** column is written.

## 7. Visibility vs. non-existent inventory

`is_active` answers exactly one question: "may this Product be offered for
new commercial activity?" It is **catalog visibility**, never inventory
availability — this branch defines no stock/quantity/reservation concept at
all. `activate()`/`deactivate()` on `ProductService` are idempotent no-ops
when already in the target state.

`HasActiveScope` (`App\Traits\HasActiveScope`) is reused as-is: a pure
Eloquent **local** scope (`scopeActive()`), confirmed by reading the trait and
grepping the whole app for `addGlobalScope`/`ScopedBy` (zero results anywhere).
`Product::find()`, `Product::all()`, and every ordinary query still return
inactive Products by default — only an explicit `Product::active()` call
filters (`ProductServiceTest`). Catalog visibility is therefore never mistaken
for object existence.

## 8. Deletion

**Soft delete only, by convention and by two enforcement layers that are each
honestly narrower than "Product cannot be hard-deleted."** Normal production
Product hard deletion is prohibited: `Product::forceDelete()` and
`forceDeleteQuietly()` both throw `LogicException` at the **instance level**,
and `CatalogDomainBoundaryTest` statically rejects every **known**
query-builder-level pattern that would hard-delete or mass-delete a Product
in production code today — `Product::query()->...->forceDelete()`,
`Product::withTrashed()->...->forceDelete()`, `Product::where(...)->delete()`,
`DB::table('products')->delete()`/`->truncate()`, and raw
`DELETE FROM products`/`TRUNCATE products` SQL. As of this writing that scan
finds zero occurrences of any of those shapes.

**What this is not:** a database-level constraint. There is still no foreign
key into `products` to provide a CROSS-14-style backstop the way a
`restrictOnDelete()` child row protects `stores`/`currencies` today (§4) —
that only arrives once `feat/order-items` adds `order_items.product_id` with
its own `restrictOnDelete()`. Until then, "Product cannot be hard-deleted" is
true only in the sense that **no code path in the current tree attempts
it, and the two guards above would catch most ways someone might try** — not
in the sense that the database itself refuses the operation. A raw SQL
string built at runtime, an unusual whitespace/quoting style the text scan
does not anticipate, or `psql`/`tinker` run directly against the database
are all outside what either guard can see. See CATALOG-09 (§12) for the
precise, intentionally unglamorous status this earns.

## 9. The canonical mutation boundary

`App\Domain\Catalog\Services\ProductService` is the only production path that
constructs or mutates a Product's business state: `create()`, `rename()`,
`changePrice()`, `activate()`, `deactivate()`, `delete()`. There is
deliberately no generic `update(array $anything)` — each method is a named
operation with its own narrow contract, mirroring
`App\Domain\Orders\Services\OrderLifecycleService`'s own choice for the same
reason: a generic setter would make every rule above merely advisory.

**Mutable / immutable matrix:**

| Field | Mutable after creation? | How |
|---|:-:|---|
| `store_id` | **No** | never — runtime guard (§6) |
| `name` | Yes | `rename()` |
| `price_amount` | Yes | `changePrice()` (with `currency_id`, together) |
| `currency_id` | Yes | `changePrice()` (with `price_amount`, together) |
| `is_active` | Yes | `activate()` / `deactivate()` |
| `deleted_at` | Yes (one-way in practice) | `delete()`; no restore path exists in this branch |

## 10. Concurrency

Product is **not** a financial aggregate. There is no row lock, no
compare-and-set, no version column. Two concurrent `rename()` or
`changePrice()` calls on the same Product are ordinary last-write-wins —
whichever `UPDATE` commits last wins, and neither caller is told the other one
happened. This is a deliberate, stated non-guarantee: unlike an Order's
status, no impossible state or financial effect is reachable from two
administrative writes racing on a Product's name or price. `activate()`/
`deactivate()` re-check the current value only to keep their own idempotency
semantics honest, never as a concurrency guarantee.

## 11. Architecture enforcement

- **Runtime — `Product::performUpdate()`.** Blocks every Eloquent route to
  changing `store_id` on an existing row (§6).
- **Runtime — `Product::forceDelete()` / `forceDeleteQuietly()`.** Always
  throw (§8).
- **Static — `tests/Architecture/CatalogDomainBoundaryTest`.** Comment-stripped
  scans over production code (`app/`, `routes/`, `bootstrap/`, `config/`,
  `database/seeders/`) proving: only `ProductService` constructs, mass-assigns
  or persists a Product; the Catalog domain has no Payments/Payouts/Orders/
  Wallet dependency; the Catalog domain's only write-shaped calls live in
  `ProductService.php`; no forbidden column/concept (SKU, stock, variant,
  slug, category, vendor/supplier, discount, rating, wishlist, ...) has been
  introduced; no `products` foreign key uses `cascadeOnDelete()`; and no known
  query-builder mass-`forceDelete()`/`delete()` off any `Product::` chain, nor
  a raw `DB::table('products')` delete/truncate or `DELETE FROM products`/
  `TRUNCATE products` SQL statement, exists anywhere in production code
  (CATALOG-09 — deliberately the narrow claim "none exists today," never the
  stronger "none could ever exist"). Every detector is a pure function with a
  synthetic-violation self-test covering each named pattern, and a
  "finds real files" sanity check guards against a vacuously-passing scan.
- **Schema — `tests/Feature/Domain/Catalog/ProductSchemaDeletePolicyTest`.**
  Reads the *actual* SQLite constraint via `PRAGMA foreign_key_list`
  (immune to which PHP migration API produced it), and proves by direct
  deletion attempt that a Store or Currency with a Product cannot be deleted.
- **What none of this proves.** A column or table name assembled at runtime,
  or raw SQL built from parts, evades a text scan. `Product::query()->...->
  forceDelete()` is caught only by the static scan, not by any runtime guard.
  Ad-hoc code run outside the repository (tinker/psql against production) is
  beyond any test's reach.

## 12. Invariants

| ID | Statement | Status |
|---|---|---|
| CATALOG-01 | A Product belongs to exactly one Store | **ENFORCED** — required FK, `restrictOnDelete()` |
| CATALOG-02 | `store_id` is immutable after creation | **ENFORCED** — `performUpdate()` guard, every Eloquent style (`ProductServiceTest`) |
| CATALOG-03 | No `Vendor` model or `Product.user_id`/`vendor_id` exists | **ENFORCED** — `CatalogDomainBoundaryTest` forbidden-field scan |
| CATALOG-04 | Price and currency are always changed together, never independently | **ENFORCED** — `changePrice()` is the only mutator for either |
| CATALOG-05 | Product price is commercial intent, never proof of settlement | **ENFORCED** — no Wallet/Payment dependency (architecture scan + `ProductServiceTest`) |
| CATALOG-06 | ProductService never mutates Wallet/Payment state | **ENFORCED** — architecture scan + `ProductServiceTest` |
| CATALOG-07 | `is_active` is catalog visibility, never inventory availability, and never hides rows from ordinary queries | **ENFORCED** — `HasActiveScope` is local-only; `ProductServiceTest` |
| CATALOG-08 | Deletion is soft by default | **ENFORCED** — `SoftDeletes`, `forceDelete()`/`forceDeleteQuietly()` throw |
| CATALOG-09 | Normal production Product hard deletion is prohibited: instance-level `forceDelete` APIs are blocked at runtime; known production query-builder force-delete/mass-delete patterns are rejected by architecture tests; database-level historical protection does not exist yet because no `OrderItem` references Product | **PARTIALLY ENFORCED** — runtime + static layers cover every *known* production path (§8); no database constraint exists, so this is not "hard deletion is impossible," only "no code path in the current tree does it, and most ways someone might try are caught" |
| CATALOG-10 | No FK from `products` ever cascades | **ENFORCED** — `ProductSchemaDeletePolicyTest`, `CatalogDomainBoundaryTest` |
| CATALOG-11 | Every business mutation goes through `ProductService` | **ENFORCED** — architecture scan (`CatalogDomainBoundaryTest`) |
| CATALOG-12 | Creation is atomic: ownership, price, currency and visibility all land in one write | **ENFORCED** — single `Product::create()` call, wrapped in `DB::transaction()` |
| CATALOG-13 | No `OrderItem`/inventory concept is introduced by this branch | **ENFORCED** — forbidden-field scan; not implemented by design (§1) |
| CATALOG-14 | Concurrent administrative writes cannot corrupt a Product into an impossible state | **N/A — not a guarantee this branch makes** (§10); ordinary last-write-wins is accepted because no impossible state or financial effect exists at this layer |

## 13. Future contracts (not implemented here)

**`OrderItem` (for `feat/order-items`):** expected to hold a foreign key to
`products.id` (`restrictOnDelete()`, per CROSS-14 discipline) and to
**snapshot** `name`, `price_amount` and `currency_id` at sale time — never to
re-read a live Product for historical amounts, since a Product's price can
change after the sale (§5).

**Inventory identity (for `feat/inventory-reservations`):** whatever holds
stock/reservation state is expected to reference `products.id` the same way.
Digital/unlimited-product semantics (does every Product need a stock row, or
can some be inherently unlimited?) remain **unresolved** until that branch's
own design — this branch neither assumes nor forecloses either answer.

**Product → Variant migration risk:** if a future product decision requires
variants/options, `products` becomes the "parent" concept and a new
`product_variants` table would hold the sellable identity instead — a
migration that changes what `order_items.product_id` (once it exists) points
to. This branch does not attempt to pre-shape the schema for that outcome
(e.g. no mandatory default variant), since inventing it now would be
speculation with no product decision behind it.

## 14. Decisions left open (deliberately not invented)

1. **Whether a zero-priced (`"0.00"`) Product is valid/sellable.**
   `ProductService::normalizePrice()` **accepts** zero — there is no
   repository evidence either forbidding or requiring free products, and
   inventing a prohibition would be exactly as much a guess as accepting it.
   This is the narrowest honest rule: reject only what is unambiguous
   (malformed input, negative amounts).
2. **Public storefront terminology** ("seller", "vendor", "shop") — no
   catalog frontend exists yet; nothing here creates a `Vendor` abstraction.
3. **Digital/unlimited-product semantics** — deferred to inventory design
   (§13).
4. **Whether `Product` will ever need variants/options** — deferred; §13
   states the migration risk rather than pre-solving it.
5. **A restore path for a soft-deleted Product** — `ProductService` has no
   `restore()` method in this branch; nothing needed one yet.
