# shoprdam — engineering contract

Permanent, project-wide rules for every change to this repository, whether made
by a person or by an agent. This file holds **rules, not state**: which database
engines are supported, server versions, test counts and per-branch results live
in the documents listed in §1, never here.

The repository is the source of truth. If this file, a domain document and the
code disagree, do not silently pick one: report the discrepancy (file:line and
why) and resolve it deliberately.

## 1. Sources of truth

| Topic | Authoritative document |
|---|---|
| Financial architecture, canonical writers, provider abstraction, doc-maintenance duty (§8) | `docs/financial/ARCHITECTURE.md` |
| Invariant registry (`LEDGER-`, `PAYOUT-`, `PAYMENT-`, `CROSS-`, `RECON-`, `ORDER-`) and known non-guarantees | `docs/financial/INVARIANTS.md` |
| Financial state machines, money flows, crash and timeout model | `docs/financial/STATE-MACHINES.md`, `docs/financial/MONEY-FLOWS.md`, `docs/financial/FAILURE-MODEL.md` |
| Provider reconciliation | `docs/financial/RECONCILIATION.md` |
| Order lifecycle, order items | `docs/financial/ORDER-LIFECYCLE.md`, `docs/orders/ORDER-ITEMS.md` |
| Catalog | `docs/catalog/CATALOG-DOMAIN.md` |
| Inventory reservations (`INVENTORY-`) | `docs/inventory/INVENTORY-RESERVATIONS.md` |
| Wallet operations, payment-provider integration | `docs/wallet/` |
| Database engines: support status, real-engine evidence, schema baselines (`DBPORT-`) | `docs/architecture/DATABASE-SUPPORT.md` |

- `docs/architecture/DATABASE-SUPPORT.md` is authoritative for which engines are
  supported and what has been proven on each. An engine statement in a domain
  document never overrides it.
- `README.md` is a product overview, not evidence that a capability is
  implemented.
- State machines, invariant tables and engine matrices belong in those
  documents. Do not copy them here.

## 2. Correctness hierarchy

```
financial correctness > crash safety > idempotency > concurrency safety > clean domain boundaries > extensibility > naming/aesthetics
```

A more elegant change never weakens a property ranked above the one it
improves. When two properties conflict, the higher one wins, and the trade-off
is stated explicitly in the PR — never resolved silently.

## 3. Money and the Wallet ledger

- **Never use float for money.** Amounts are exact decimal strings (`decimal:2`
  casts); decimal arithmetic and comparison use bcmath (`bcadd`, `bcsub`,
  `bcmul`, `bccomp`), never `==`/`===` on decimal strings; provider minor units
  are integers derived only through `App\Domain\Payments\MinorUnits`. No float
  casts, float types, float literals or `round()`/`number_format()` on money.
- **One writer.** Only `App\Services\Wallet\WalletTransactionService` creates
  ledger rows or changes `store_wallets.balance`.
  `App\Services\Wallet\WalletService` only creates a wallet, at a `0.00` opening
  balance.
- **Canonical callers only.** The Wallet writer is called only by the canonical
  financial services: `PaymentService` (the pending `sale` at claim time),
  `PaymentEventProcessor` (confirm, mark failed, full-refund reversal) and
  `PayoutService` (the `withdrawal` at request time; the single
  `withdrawal_reversal`, in `abandon()`). Controllers, admin actions, console
  commands, jobs, listeners, provider adapters, reconciliation and the
  Orders/Catalog/Inventory domains never call it, never write ledger tables or
  balances, and never fabricate a settled or terminal financial state. Admin
  recovery runs the same recovery service the scheduler runs, never a separate
  "admin version".
- **Append-only history.** A terminal ledger row is never edited; a reversal is
  a new row linked to its original and never exceeds it. Financial history is
  never cascade-deleted, soft-deleted as a substitute, or truncated by
  application code. Ledger drift is detected (`php artisan wallet:audit`), never
  auto-corrected.
- **Durable idempotency.** Financial idempotency rests on durable identities
  enforced by unique constraints (provider + reference, provider + idempotency
  key, provider + event id, store + idempotency key) and on compare-and-set
  updates — never on an in-memory flag or a read-then-write pre-check alone. A
  duplicate delivery or retry is a no-op because the database says so.
- **Unknown is not failure.** An HTTP exception or timeout is never proof of
  financial failure and never triggers a reversal or a terminal failed state. An
  unknown remote outcome stays non-terminal and reconcilable (fail closed) until
  real provider evidence arrives through the canonical event processor.
- **Exact matching.** Settlement resolves the exact attempt by provider +
  provider reference, never by "the current attempt", and checks amount,
  currency and correlation against stored values before any write, failing
  closed on a mismatch. Reconciliation observes and records; it never corrects.
- **No invented finance.** Do not add ledger categories, statuses, partial
  refunds, disputes, chargebacks or any other economic effect to complete a
  feature. A seeded category or status that no code produces is not a
  supported feature. Any change of financial behavior — a new state, provider,
  category producer or economic effect; settlement, refund, payout,
  idempotency, delete-policy or recovery semantics; a new Wallet caller —
  updates `docs/financial/*`, the invariant registry and the tests in the same
  PR (`docs/financial/ARCHITECTURE.md` §8).

## 4. Transactions, locking and external providers

- Never hold a database transaction or row lock across HTTP/provider I/O. Where
  the flow requires it, persist the durable local state first (e.g. the pending
  attempt with its deterministic idempotency key) in a short transaction, call
  the provider outside any transaction, then claim the result with a
  compare-and-set `UPDATE` in a new short transaction.
- Close races with the mechanism the domain already uses for that state: a row
  lock (`lockForUpdate()`) with the decision taken on freshly re-read state; a
  conditional `UPDATE … WHERE <expected state>`; a unique constraint as the
  idempotency backstop; a savepoint around an insert that may lose a unique
  race; a deterministic lock order where several rows are locked. Never replace
  one of them with an application-level pre-check.
- Never catch `QueryException` (or a broader type around database work)
  generically and keep using the same transaction. An insert that may lose a
  race runs in its own `DB::transaction()` — a real savepoint when nested — so
  PostgreSQL's aborted-transaction state is rolled back before any recovery
  query; the `catch` then recovers only the specific, classified case and
  rethrows everything else.
- Database-error classifiers are narrow and semantic: SQLSTATE plus driver code
  and/or the specific constraint or column, per engine (MySQL/MariaDB `23000`
  also covers foreign-key and NOT NULL violations). Never widen a classifier or
  match a generic vendor message to make a test pass. Test a classifier against
  real driver exceptions
  (`tests/Feature/Domain/Payments/ExceptionClassificationPortabilityTest.php`).
- Deadlocks, lock-wait timeouts and serialization failures are not retried
  generically. A retry exists only where a specific engine behavior was proven
  to need it, and is then narrowly classified and bounded.

## 5. Inventory

Mechanism, state machine and invariant registry:
`docs/inventory/INVENTORY-RESERVATIONS.md`.

- **Never oversell.** Availability is decided by the single atomic conditional
  `UPDATE` in `InventoryReservationService` — never by reading a quantity and
  then writing — with the database CHECK constraints as a backstop.
- **Exactly one stock effect.** `reserve` is idempotent through the unique
  reservation identity; `commit` and `release` through the reservation's
  compare-and-set on its status. Only the winner moves the counters, in the
  same transaction.
- `reserved_quantity` always equals the sum of live reservations and moves only
  together with a reservation transition. Drift is detected
  (`InventoryIntegrityChecker`), never repaired automatically.
- `InventoryReservationService` is the only writer. Wiring any production caller
  (checkout, payment, expiry, cancellation, operator action) is a product
  decision: it must first resolve the open questions the Inventory document
  records, and must change the caller allowlist in
  `tests/Architecture/InventoryBoundaryTest.php` on purpose.
- Never bypass or weaken the locks, CAS, constraints or lock order to simplify a
  test or to gain portability. SQLite results say nothing about Inventory
  concurrency on a server engine (§7).
- Do not add stock movements, receipts, adjustments, breakage, physical counts
  or stock-control policies incrementally. They belong to a separate, future
  inventory-control design.

## 6. Migrations and schema history

**Established historical migrations are immutable.**

- Never edit, rename or delete an established migration — not even
  cosmetically — to fix or evolve the schema. Evolve it with a new forward
  migration. A historical fresh-install defect is handled with the per-engine
  schema baseline (`php artisan schema:dump`, never `--prune`), as
  `docs/architecture/DATABASE-SUPPORT.md` documents.
- Fresh install and upgrade must converge to the same intended schema on every
  supported engine.
- Never infer global deployment state from the local database. When in doubt
  whether a migration has already run anywhere outside this machine, treat it
  as established.
- `tests/Architecture/EstablishedMigrationImmutabilityTest.php` enforces this
  against `tests/Architecture/fixtures/established-migrations.json`. Its
  boundary is "merged to `main`": the manifest only gains entries for
  migrations already merged to `main`, generated from the committed tree, and
  only when the user asks. Never regenerate it to bless an edit of an existing
  entry; superseding an established migration is a deliberate, visible decision
  by the user, never a side effect.
- New migrations follow the existing policies: a foreign key into financial or
  inventory history never cascades deletes into it (`restrictOnDelete()`);
  identifiers fit every supported engine's limit
  (`tests/Architecture/DatabaseMigrationPortabilityTest.php`); a
  correctness-critical constraint that differs per engine has an explicit,
  documented per-engine form.

## 7. Database portability

**Database-agnostic in the domain, explicitly multi-database in the
infrastructure.**

- Domain code expresses invariants through mechanisms with equivalent semantics
  on every supported engine (constraints, CAS, row locks, savepoints).
  Engine-specific behavior lives in narrow, documented, tested infrastructure:
  error classifiers, bounded retries, schema baselines, per-engine constraint
  forms.
- A driver Laravel can configure (`config/database.php`) is not an engine
  shoprdam supports. Never claim support, or change an engine's status, without
  the evidence `docs/architecture/DATABASE-SUPPORT.md` requires. Use its
  terminology (CONFIGURABLE … SUPPORTED) and keep the support matrix there only.
- SQL compilation proves SQL shape, not runtime behavior. SQLite proves
  algorithm and state-machine shape, not the locking, isolation, abort or
  concurrency semantics of MySQL, MariaDB or PostgreSQL. One engine's result is
  never inferred from another's — MySQL and MariaDB are distinct engines.
- A correctness-critical engine-specific dependency (isolation level, lock or
  abort behavior, error codes, CHECK enforcement, identifier limits) is named
  explicitly and tested against the real engine.
- Never weaken a financial, historical or Inventory invariant for portability.

## 8. Tests

- Never weaken an invariant, loosen an assertion, widen a classifier or touch an
  established migration to make a test pass. A failing invariant test is a
  finding, not an obstacle.
- The default suites (`phpunit.xml`) run on in-memory SQLite: fast feedback,
  not proof of engine-dependent semantics. Engine-dependent behavior needs real
  engines: the real-engine CI jobs in `.github/workflows/`, and the opt-in
  `tests/Concurrency` suites, which run only against a disposable local
  `*_concurrency_test` database.
- A test that claims real concurrency uses genuinely independent connections /
  OS processes (the `tests/Concurrency` harness), never sequential calls on one
  connection. A sequential "competitor already committed" simulation is labelled
  as a simulation.
- A test that provokes an expected constraint failure and keeps using the
  connection wraps only the failing operation in its own savepoint
  (`expectDatabaseRefusal()` in `tests/Helpers.php`), so it holds under
  PostgreSQL's transaction-abort semantics.
- Architecture tests (`tests/Architecture`) are static source and filesystem
  scans, bootstrapped without a database (`tests/Pest.php`). Keep them
  independent of migrations, seeds and database state, and keep their shape:
  comments stripped, exact path allowlists, non-vacuous scans, a self-test for
  every detector.
- When correctness-critical code changes, test its relevant boundaries: crash
  points, idempotent replay, duplicate delivery, retry, stale state or a lost
  CAS, and races.
- Commands: `php artisan test` · `vendor/bin/pest --testsuite=Architecture` ·
  `vendor/bin/pint --test <changed paths>` (style check only; CI also runs
  Pint).

## 9. Domain discipline

- Do not invent domain concepts, states, categories or flows to "complete" a
  feature. If something a task needs does not exist, state the gap; if closing
  it is a product or financial decision, leave it open and say so.
- An enum case, a seeded status or category, a column, frontend mock data or a
  README bullet is not evidence that a capability exists. Verify it in code and
  in the domain document's known non-guarantees before relying on it — e.g.
  partial refunds, disputes and chargebacks, cancellation, fulfilment, stock
  movements, automatic payouts.
- Provider ≠ payment method. A method maps to its provider only through
  `App\Domain\Payments\PaymentMethodCatalog`. The Payments and Payouts domains
  reach providers only through their contracts and managers, never through a
  concrete adapter or a provider SDK.
- Before adding a way to mutate some state, find its canonical writer or path
  and use it; never add a second one. Respect aggregate and service boundaries
  (e.g. an attempt failing never fails its Payment or Payout; the Orders domain
  only reads Wallet evidence).

## 10. Git — responsibility boundary

- Allowed: read-only inspection — `git status`, `git diff`, `git log`,
  `git show`, `git blame`, `git ls-files` and similar.
- Never run `git add`, `git commit`, `git commit --amend` or `git push`, nor any
  equivalent that writes the index, creates a commit or publishes one —
  including `git add --intent-to-add` (not even as a workaround for diff
  tooling), `git rm`, `git mv`, `git stash`, `git apply --index` or `--cached`,
  and `git update-index`.
- Never rewrite or move history: no `rebase`, `reset`, `cherry-pick`, `merge`,
  `revert` or force operations.
- Staging, committing and pushing are exclusively the user's responsibility.
- If a check would need staged content, use a read-only alternative instead.
  `git diff --check` does not see untracked files; for a new file use
  `git diff --no-index --check /dev/null <file>`.

## 11. Scope discipline

- Do not fix unrelated problems found during a branch; report them (file:line
  and why).
- Report a concrete blocker before increasing scope. If a branch's declared
  scope turns out to need production changes it did not anticipate, stop and
  present the blocker first.
- Do not mix future product decisions into current technical hardening.
- Do not add abstractions "for the future" without a demonstrated current need.
- Prefer the smallest change that fully preserves every existing invariant.

## 12. What is enforced mechanically

| Rule | Architecture test |
|---|---|
| Only `WalletTransactionService` writes the ledger or balances | `tests/Architecture/WalletLedgerSingleWriterTest.php` |
| Only the canonical financial services call the Wallet writer | `tests/Architecture/WalletLedgerCanonicalCallerTest.php` |
| No float in money-bearing code | `tests/Architecture/MoneyNoFloatTest.php` |
| Admin recovery never mutates the Wallet or fabricates terminal states | `tests/Architecture/PaymentRecoveryNoDirectWalletMutationTest.php`, `tests/Architecture/PayoutRecoveryNoDirectWalletMutationTest.php` |
| Reconciliation never mutates financial state | `tests/Architecture/ReconciliationNoFinancialMutationTest.php` |
| `wallet:audit` is read-only | `tests/Architecture/WalletLedgerReadOnlyAuditTest.php` |
| Financial history is never cascade-deleted | `tests/Architecture/FinancialHistoryDeletePolicyTest.php`, `tests/Architecture/PayoutFinancialHistoryAppendOnlyTest.php` |
| Domains never import provider SDKs or adapters | `tests/Architecture/PaymentsDomainBoundaryTest.php`, `tests/Architecture/PayoutsDomainBoundaryTest.php` |
| Order, OrderItem, Catalog and Inventory boundaries | `tests/Architecture/OrderLifecycleBoundaryTest.php`, `tests/Architecture/OrderItemBoundaryTest.php`, `tests/Architecture/CatalogDomainBoundaryTest.php`, `tests/Architecture/InventoryBoundaryTest.php` |
| Established migrations are immutable | `tests/Architecture/EstablishedMigrationImmutabilityTest.php` |
| Migration identifiers fit engine limits | `tests/Architecture/DatabaseMigrationPortabilityTest.php` |
| The invariant registry keeps its IDs and known limitations | `tests/Architecture/FinancialContractRegistryTest.php` |
| This file's references exist and its core rules stay present | `tests/Architecture/EngineeringContractTest.php` |

Everything else here — the correctness hierarchy, no I/O inside transactions,
narrow classifiers, real-engine evidence, test discipline, Git and scope — is
enforced by review. Static scans are a floor, not a proof: a name assembled at
runtime, raw SQL built from parts or direct database access evades them.
