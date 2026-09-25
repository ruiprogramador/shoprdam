# Financial Invariant Registry — shoprdam

Canonical, stable IDs. `LEDGER-01`..`LEDGER-08` and `PAYOUT-01`..`PAYOUT-18`
already existed (formalized in `harden/wallet-ledger-invariants` and
`feat/store-payouts` respectively) and are preserved verbatim here —
nothing renamed. `PAYMENT-XX` and `CROSS-XX` are new, assigned by this
branch after verifying each one against production code.

**Status legend** (never assigned optimistically):
- **ENFORCED** — a lock, CAS, or unique constraint makes violation
  structurally impossible, proven by a test that exercises the real code
  path.
- **PARTIALLY ENFORCED** — true for part of the system, not all of it (the
  gap is named explicitly).
- **DETECTED ONLY** — violation is possible but surfaced after the fact
  (e.g. `wallet:audit`), never prevented.
- **NOT SUPPORTED** — the feature this invariant would describe doesn't
  exist; documented so nobody assumes otherwise.
- **DORMANT** — a status/enum value is defined and seeded but never
  produced by any code path.

## PAYMENT-XX

| ID | Statement | Owner | Code protection | DB protection | Test | Status | Limitations |
|---|---|---|---|---|---|---|---|
| PAYMENT-01 | A Payment is the single financial obligation an Order can have | `Payment` model | `findOrCreatePayment()` insert-and-recover | `payments.order_id` UNIQUE | `PaymentAttemptIdempotencyKeyUniquenessTest` | ENFORCED | — |
| PAYMENT-02 | At most one non-terminal PaymentAttempt exists per Payment at a time | `PaymentService::createDurableAttempt()` | Row lock on `Payment` + `blocksNewAttempt()` check | None (app-level, same as documented in the migration's own comment) | `PaymentServiceGatingTest` | ENFORCED | Not DB-enforced, by design (mirrors Payout's own documented choice) |
| PAYMENT-03 | An attempt failing never marks the Payment aggregate failed (attempt ≠ aggregate) | `PaymentEventProcessor::applyFailed()` passes `null` for Payment status | — | — | Verified by reading `applyFailed()`; no dedicated regression test exists for "PaymentStatus::Failed is never set" | ENFORCED (by omission) | `PaymentStatus::Failed` is DORMANT — see `STATE-MACHINES.md` |
| PAYMENT-04 | A `provider_reference` belongs to exactly one attempt per provider | — | `unique(provider, provider_reference)` on `payment_attempts` | Migration `2026_08_02_130000` | Schema-level, exercised indirectly by `PaymentEventProcessorExactAttemptSettlementTest` | ENFORCED | — |
| PAYMENT-05 | `idempotency_key` is deterministic per attempt and unique per provider | `PaymentService::createDurableAttempt()` (placeholder → forceFill) | `unique(provider, idempotency_key)` | Migration `2026_08_31_090000` | `PaymentAttemptIdempotencyKeyUniquenessTest` | ENFORCED | — |
| PAYMENT-06 | Settlement resolves the exact attempt by `(provider, provider_reference)`, never by `current_payment_attempt_id` | `PaymentEventProcessor::markSettled()` | — | — | `PaymentEventProcessorExactAttemptSettlementTest` | ENFORCED | Same as CROSS-10 |
| PAYMENT-07 | A full refund reverses exactly the original captured amount, never more | `WalletTransactionService::reverse()` | `reverse()` has no amount parameter; `childTransactions()->exists()` guard | `store_wallet_transactions.external_ref_idx` unique | `PaymentLedgerReconciliationTest` | ENFORCED | Same as CROSS-04 |
| PAYMENT-08 | Partial refunds are not applied to the ledger | `PaymentEventProcessor::applyRefunded()` amount comparison | — | — | `PaymentLedgerReconciliationTest` "a partial refund is never applied" | NOT SUPPORTED | Silently ignored (logged), never queued, never partially applied — see `MONEY-FLOWS.md` §D |
| PAYMENT-09 | Duplicate webhook delivery produces zero additional financial effect | `PaymentEventProcessor` catches `TransactionNotPendingException`/`TransactionAlreadyReversedException` | `unique(provider, provider_event_id)` on `payment_provider_events` | Migration `2026_08_02_140000` | `PaymentEventProcessorExactAttemptSettlementTest` | ENFORCED | — |
| PAYMENT-10 | Retry-exhaustion/timeout never marks an attempt `Failed` automatically | `PaymentAttemptRecoveryService::recover()` | Only produces `NeedsAttention`/`RetryPending` on exception | — | Behavior verified by reading every catch branch; covered indirectly by `ReconcileOrphanedPaymentAttemptsTest` | ENFORCED | Same as CROSS-09 |

## PAYOUT-XX (preserved verbatim from `feat/store-payouts`)

| ID | Statement | Status | Notes |
|---|---|---|---|
| PAYOUT-01 | A payout cannot be created/executed if available balance is less than the requested amount | ENFORCED | `WalletTransactionService::calculateNewBalance()` under `lockForUpdate()` |
| PAYOUT-02 | Two concurrent payouts can never reserve/spend more than the available balance | ENFORCED (lock) / DETECTED ONLY for true parallelism | Same wallet-row lock as PAYOUT-01; see `INVARIANTS.md` Concurrency note below — sequential simulation only, no real parallel test |
| PAYOUT-03 | Balance reserved for a payout stops being available for new operations | ENFORCED | Debit is posted `completed` immediately — reduces the real `wallet.balance`, not a separate reserved figure |
| PAYOUT-04 | A succeeded payout produces exactly one permanent financial debit | ENFORCED | `PayoutEventProcessor::applySucceeded()` never calls the Wallet |
| PAYOUT-05 | A failed/cancelled payout releases the reservation exactly once | ENFORCED | `PayoutService::abandon()`, `reverse()`'s own guard |
| PAYOUT-06 | Repeating the same command/request never creates a second financial effect | ENFORCED | `unique(store_id, idempotency_key)` + `insertOrIgnore` |
| PAYOUT-07 | Duplicate provider callbacks are idempotent | ENFORCED | CAS on `PayoutAttempt.status` |
| PAYOUT-08 | A `provider_reference` belongs to exactly one attempt within its provider | ENFORCED | `unique(provider, provider_reference)` |
| PAYOUT-09 | Settlement identifies the exact attempt by `provider + provider_reference`, never just `payout_id` | ENFORCED | `PayoutEventProcessor::findAttempt()` |
| PAYOUT-10 | Provider timeout does not mean failed | ENFORCED | See `FAILURE-MODEL.md` Timeout semantics |
| PAYOUT-11 | If the provider executes but the response is lost, durable local state allows discovery/recovery | ENFORCED | Durable pre-call `PayoutAttempt` row + deterministic idempotency key |
| PAYOUT-12 | An already-succeeded payout can never be resent as a new transfer | ENFORCED | `PayoutStatus::isTerminal()` blocks `createDurableAttempt()` |
| PAYOUT-13 | Payout currency is explicit and matches the Wallet | ENFORCED | `Payout.currency_id` snapshot, resolved from the wallet at request time |
| PAYOUT-14 | Never use float for money | ENFORCED | bcmath end to end, verified against Laravel's own `decimal` cast source (`BigDecimal::of((string) $value)`); no float in money-bearing code, `tests/Architecture/MoneyNoFloatTest` |
| PAYOUT-15 | A crash at any point never permits double debit nor an unreconcilable state | ENFORCED | See `FAILURE-MODEL.md` crash matrix |
| PAYOUT-16 | Controllers/admin/webhooks never alter the Wallet balance directly | ENFORCED | `PayoutRecoveryNoDirectWalletMutationTest` |
| PAYOUT-17 | There is a single canonical payout settlement path | ENFORCED | Same architecture test, second assertion (`->reverse(` scan) |
| PAYOUT-18 | Payout/attempt financial history is not deleted by accidental cascade | ENFORCED | `restrictOnDelete()` on every payout-related FK, verified against real `PRAGMA foreign_key_list` output by `PayoutSchemaDeletePolicyTest` — the same pattern is now also proven on the Payments/Wallet side, see CROSS-14 |

## LEDGER-XX (preserved verbatim from `harden/wallet-ledger-invariants`)

| ID | Statement | Status | Notes |
|---|---|---|---|
| LEDGER-01 | Same financial event applied twice → one ledger effect | ENFORCED | Reference-keyed idempotency, per domain |
| LEDGER-02 | A ledger row is immutable once terminal (`completed`/`failed`) | ENFORCED (app-level only) | No DB trigger — guard clauses in `WalletTransactionService` are the only protection |
| LEDGER-03 | `wallet.balance == Σcompleted credits − Σcompleted debits`, entire history, no time window | ENFORCED + DETECTED | `WalletLedgerAuditor`; premise (zero opening balance) proven by `WalletServiceOpeningBalanceTest` |
| LEDGER-04 | A reversal never exceeds the original captured amount | ENFORCED | `reverse()` has no amount parameter |
| LEDGER-05 | A payout never debits more than available | ENFORCED | Same lock as PAYOUT-01 |
| LEDGER-06 | Currency A never affects currency B's balance | ENFORCED | Explicit `StoreWallet` parameter, `unique(store_id, currency_id)` |
| LEDGER-07 | Drift is detectable, never auto-corrected | DETECTED ONLY (by design) | `wallet:audit` — no `--fix` exists or is planned |
| LEDGER-08 | Per-transaction historical `balance_after` reconstructable by id/created_at order | **NOT SUPPORTED** | Audited and deliberately not implemented — pending→completed promotion can complete out of `id` order; no `completed_at` column exists to reconstruct true order safely |

## CROSS-XX (new — cross-domain, verified before formalizing)

| ID | Statement | Owner | Status | Evidence |
|---|---|---|---|---|
| CROSS-01 | A succeeded Payment produces exactly one credit effect in the Wallet | `PaymentEventProcessor` | ENFORCED | `PaymentLedgerReconciliationTest` |
| CROSS-02 | Reprocessing the same financial success never duplicates the credit | `PaymentEventProcessor` (CAS via `confirm()`'s pending-only guard) | ENFORCED | `PaymentLedgerReconciliationTest` "settling the same succeeded event twice" |
| CROSS-03 | An unconfirmed Payment/attempt never produces an undue completed credit | `WalletTransactionService::record()` (`pending` status never touches balance) | ENFORCED | `WalletLedgerInvariantsTest` "pending transaction's balance_after is only a snapshot" |
| CROSS-04 | A valid full refund produces exactly one reversal, never a debit exceeding the original capture | `WalletTransactionService::reverse()` | ENFORCED | `PaymentLedgerReconciliationTest` "LEDGER-04: a full refund reconciles exactly" |
| CROSS-05 | A payout request/reservation produces exactly one economic withdrawal | `PayoutService::request()` | ENFORCED | `PayoutLedgerReconciliationTest` |
| CROSS-06 | A succeeded payout keeps the original withdrawal and never creates a second debit | `PayoutEventProcessor::applySucceeded()` | ENFORCED | `PayoutEventProcessorTest` |
| CROSS-07 | A terminally cancelled/abandoned payout produces exactly one withdrawal_reversal | `PayoutService::abandon()` | ENFORCED | `PayoutAttemptLifecycleTest` "never reverses the same payout twice" |
| CROSS-08 | A `PayoutAttempt` Failed, by itself, does not release funds while the Payout remains payable/retryable | `PayoutEventProcessor::applyFailed()` | ENFORCED | `PayoutEventProcessorTest` "fails the attempt without reversing the reservation" |
| CROSS-09 | Provider timeout/unknown outcome is never automatically treated as proof of financial failure | `PaymentAttemptRecoveryService`, `PayoutAttemptRecoveryService` | ENFORCED | See `FAILURE-MODEL.md` Timeout semantics |
| CROSS-10 | Provider/reference settlement reaches the exact historical attempt, never just "current attempt" | `PaymentEventProcessor::markSettled()`, `PayoutEventProcessor::findAttempt()` | ENFORCED | `PaymentEventProcessorExactAttemptSettlementTest`, `PayoutEventProcessorTest` "EXACT CURRENT ATTEMPT ISOLATION" |
| CROSS-11 | Currency A can never mutate a Wallet of currency B | `WalletTransactionService::record()` (explicit `StoreWallet` param) | ENFORCED | `WalletLedgerAuditorTest` "LEDGER-06: currency isolation" |
| CROSS-12 | Controllers/admin/webhooks cannot write `wallet.balance` directly or fabricate an economic effect outside the canonical domain path | `WalletTransactionService` sole-writer design | ENFORCED | `WalletLedgerSingleWriterTest`, `WalletLedgerCanonicalCallerTest`, `PaymentRecoveryNoDirectWalletMutationTest`, `PayoutRecoveryNoDirectWalletMutationTest` |
| CROSS-13 | A terminal succeeded cannot be resent/re-executed to duplicate an economic effect | `PaymentAttemptStatus`/`PayoutStatus` `isTerminal()` gates | ENFORCED | `PaymentServiceGatingTest`, `PayoutAttemptLifecycleTest` "refuses to create a new attempt for an already-terminal payout" |
| CROSS-14 | Relevant financial history is append-only and cannot disappear via operational cascade | Payouts: `PayoutService`/migrations. Payments/Wallet: `database/migrations/2026_09_16_180000_restrict_financial_history_cascades.php` (DB) + `App\Http\Controllers\User\ProfileController::destroy()` (app) | **ENFORCED** | DB-level: all six Payments/Wallet FKs (`payment_attempts.payment_id`, `payments.order_id`, `store_wallet_transactions.store_wallet_id`, `orders.store_id`, `store_wallets.store_id`, `stores.user_id`) are `restrictOnDelete()`, verified against real `PRAGMA foreign_key_list` output by `PaymentsSchemaDeletePolicyTest` (mirrors Payouts' own `PayoutSchemaDeletePolicyTest`). App-level: `ProfileController::destroy()` refuses to hard-delete a User who owns any Store — trashed or not — before any mutation, never surfacing the constraint as an unhandled `QueryException`; see `ProfileAccountDeletionFinancialHistoryTest` (scenarios A1–A7: no Store, empty Store, soft-deleted Store, Wallet/ledger history, Payment history, Payout history, and a mixed case). Migration safety (existing rows survive `down()`+`up()`, the live FK policy genuinely flips both ways) is proven — without touching a real database — by `FinancialHistoryCascadeMigrationSafetyTest`. Regression coverage against a future migration/model silently reopening this: `FinancialHistoryDeletePolicyTest`. See `FAILURE-MODEL.md`'s "Critical finding" for the original reproduction and its resolution, including what re-running the migration's `down()` would reopen. |

## RECON-XX (new — Phase 1 of `feat/financial-reconciliation`, verified against production code and tests before formalizing)

Full design record, taxonomy, and the complete Phase-1 invariant list
(including entries not yet promoted here) live in
`docs/financial/RECONCILIATION.md` — that document is the source of truth
for reconciliation design decisions; this table only ever mirrors an
invariant *after* it has a passing, named test proving it, per this
registry's own status-legend discipline ("never assigned optimistically").
Several invariants `RECONCILIATION.md` §21 lists are deliberately **not**
duplicated here yet (RECON-03, 07, 08, 10, 11, 17, 19) because no dedicated
test proves them independently of the ones below — true "by construction"
is not sufficient for this registry, the same standard every other row
here is held to.

| ID | Statement | Owner | Status | Test |
|---|---|---|---|---|
| RECON-01 | Reconciliation (Phase 1: provider retrieval only) never creates a financial settlement path of any kind | `App\Domain\Payments\Services\ProviderReconciler` (never calls `PaymentEventProcessor`/`PaymentAttemptRecoveryService`/`PaymentService::finalizeAttempt()`) | ENFORCED | `tests/Architecture/ReconciliationNoFinancialMutationTest` |
| RECON-02 | Reconciliation persistence is purely observational and cannot itself produce a financial effect | `App\Domain\Payments\Services\ReconciliationFindingRepository` | ENFORCED | `tests/Architecture/ReconciliationNoFinancialMutationTest`, `ProviderReconcilerTest` |
| RECON-04 | Amount/currency/correlation mismatches discovered by reconciliation are never auto-corrected in either direction | `App\Domain\Payments\Services\ReconciliationClassifier` | ENFORCED | `ReconciliationClassifierTest` |
| RECON-05 | A reconciliation retrieval failure — retryable (timeout/5xx/connection failure), or non-retryable without a provider-confirmed absence signal (auth/permission/malformed-request/ambiguous SDK failure) — is never classified as a financial mismatch, is never persisted as a finding, and never touches an already-open finding for that identity | `App\Domain\Payments\Services\ProviderReconciler` | ENFORCED | `ProviderReconcilerTest` items [B]–[I] |
| RECON-06 | A remote state corresponding to an unsupported local operation (a refund-shaped or unrecognized provider status) always fails closed to a non-actionable finding | `App\Domain\Payments\Services\ReconciliationClassifier` | ENFORCED | `ReconciliationClassifierTest` |
| RECON-09 | At most one open reconciliation episode exists per `(provider, provider_reference)` identity at a time | `payment_reconciliation_findings.active_identity` (nullable-unique, application-managed CAS) | ENFORCED | `ReconciliationFindingLifecycleTest`, `ReconciliationFindingSchemaDeletePolicyTest` |
| RECON-12 | Repeated observation of the same open reconciliation episode never creates a second row, regardless of scheduler frequency | `App\Domain\Payments\Services\ReconciliationFindingRepository` | ENFORCED | `ReconciliationFindingLifecycleTest` |
| RECON-13 | Reconciliation may mark a finding actionable only when the codebase already contains a canonical, idempotent operation *proven by execution trace* to converge from that exact state — Phase 1 satisfies this for zero categories | `App\Domain\Payments\Services\ReconciliationClassifier` (no `actionable` concept exists in Phase 1 at all) | ENFORCED | `ReconciliationClassifierTest`, `ReconciliationExecutionTraceTest` |
| RECON-14 | A reconciliation observation is never financial correction | `App\Domain\Payments\Services\ReconciliationFindingRepository` | ENFORCED | `tests/Architecture/ReconciliationNoFinancialMutationTest` |
| RECON-15 | Acknowledging a reconciliation finding is never financial correction — independent of `status`/`resolved_at` | `App\Domain\Payments\Models\ReconciliationFinding` (`acknowledged_at`/`acknowledged_by` columns) | ENFORCED | `ReconciliationFindingLifecycleTest` |
| RECON-16 | Provider unavailability (retryable or not) during reconciliation is never treated as payment failure | `App\Domain\Payments\Services\ProviderReconciler` | ENFORCED | `ProviderReconcilerTest` items [B]–[H] |
| RECON-18 | Reconciliation never constructs a synthetic `ProviderEventOutcome` or `PaymentProviderEvent` row to simulate a webhook | `App\Domain\Payments\Services\ProviderReconciler` | ENFORCED | `tests/Architecture/ReconciliationNoFinancialMutationTest`, `ProviderReconcilerTest` |
| RECON-20 | `RemoteMissing` may be emitted only when the resolved provider both implements `App\Domain\Payments\Contracts\SupportsConfirmedResourceAbsence` and confirms absence for that exact exception — never inferred from a generic non-retryable failure classification alone | `App\Payments\Stripe\StripePaymentProvider::isConfirmedAbsent()` (implements it, via `getHttpStatus() === 404`); `App\Payments\EasyPay\EasyPayPaymentProvider` (deliberately does not implement it — no verified evidence for EasyPay's absence semantics) | ENFORCED | `ProviderReconcilerTest` item [A] (Stripe 404) and items [E]/[F]/[G]/[H] (every other non-retryable failure, both providers, including an EasyPay 404-shaped response, → `RetrievalFailed`, never `RemoteMissing`) |

## ORDER-XX (new — `feat/order-lifecycle`, verified against production code and tests before formalizing)

Full audit, transition matrix, and the decisions deliberately left open live in
`docs/financial/ORDER-LIFECYCLE.md`. Only invariants with a passing, named test
are mirrored here. `ORDER-07`/`ORDER-08` (cancellation) are listed as NOT
SUPPORTED because no cancellation exists; `ORDER-09` (whether a refund should
replace the lifecycle state) is an **open product decision** and is not asserted.

| ID | Statement | Owner | Status | Test |
|---|---|---|---|---|
| ORDER-01 | Every status change of an existing Order goes through one canonical boundary | `App\Domain\Orders\Services\OrderLifecycleService` (+ `App\Models\Order::performUpdate()`, a runtime guard that rejects an Eloquent status change) | ENFORCED | `OrderLifecycleServiceTest` (every Eloquent style: `update`, `forceFill`, `fill`, `saveQuietly`, `updateQuietly`, `withoutEvents`, `associate`, `push`), `OrderLifecycleBoundaryTest` (static scans of `app/`, `routes/`, `bootstrap/`, `config/`, `database/seeders/`, with self-tests). **Limits:** a status column/table name assembled at runtime or raw SQL built from parts evades a text scan; no runtime guard sees a write that never touches a model instance; Order *creation* is unconstrained (no production creator exists); code run outside the repository (tinker/`psql`) is beyond any test |
| ORDER-02 | Only explicitly allowed Order transitions occur (`pending→paid`, `pending→failed`, `failed→paid`, `paid→refunded`) | `OrderLifecycleState` matrix + `OrderLifecycleService` | ENFORCED | `OrderLifecycleStateTest` (all 16 pairs), `OrderLifecycleServiceTest` |
| ORDER-03 | A terminal Order state (`refunded`) never returns to an active one | `OrderLifecycleState::isTerminal()` | ENFORCED | `OrderLifecycleServiceTest` "refunded is terminal" |
| ORDER-04 | Payment and Order remain separate state machines — the Orders domain has no Payments-domain dependency | `App\Domain\Orders\*` | ENFORCED | `OrderLifecycleBoundaryTest` |
| ORDER-05 | An Order becomes `paid` only from a settled `sale` Wallet transaction, via the canonical successful-payment path | `OrderLifecycleService` evidence checks (morph-aware ownership — never the raw `referenceable_type` string); only `PaymentEventProcessor` may call it | ENFORCED | `OrderLifecycleServiceTest` (evidence rejections, morph-map + legacy-row regression), `OrderPaymentIntegrationTest`, `OrderLifecycleBoundaryTest` |
| ORDER-06 | A PaymentAttempt failure never terminalizes a payable Order (`failed` is non-terminal; a later attempt still pays it) | `OrderLifecycleState::Failed` | ENFORCED | `OrderPaymentIntegrationTest`, `CrossProviderFailoverTest` |
| ORDER-07 | Cancellation obeys explicit state/financial rules | — | **NOT SUPPORTED** | No `cancelled` state or producer exists |
| ORDER-08 | Cancellation never fabricates or implies a refund | — | **NOT SUPPORTED** | No cancellation exists |
| ORDER-10 | A repeated transition is an explicit no-op (no timestamp rewrite, no event) or an explicit error — never applied twice | `OrderLifecycleService` | ENFORCED | `OrderLifecycleServiceTest` |
| ORDER-11 | Concurrent transitions cannot produce an impossible state (row lock + compare-and-set, decided from fresh state) | `OrderLifecycleService` | ENFORCED (lock/CAS) / true parallelism **unproven** | `OrderLifecycleServiceTest` (stale instance, CAS conflict) — sequential simulation only, see the Concurrency note |
| ORDER-12 | Order lifecycle code never mutates Wallet state, creates a Payment, or fabricates a provider event | `App\Domain\Orders\*` | ENFORCED | `OrderLifecycleBoundaryTest`, `OrderLifecycleServiceTest` |

## Concurrency note (applies to every ENFORCED invariant above that cites a lock/CAS)

Every "ENFORCED" status backed by a lock or CAS has been verified as a real
`SELECT ... FOR UPDATE` or conditional `UPDATE` in the source, and exercised
by a **sequential simulation** on SQLite (pre-inserting the "other worker
already committed" state, then calling the real code path) — the same
documented pattern used throughout this codebase since
`PaymentAttemptIdempotencyKeyUniquenessTest`. **No test of any invariant in
this registry (financial or `ORDER-XX`) runs two genuinely concurrent
database transactions against MySQL, MariaDB or PostgreSQL.** This is real
coverage of the locking *primitive* the guarantee depends on, not proof
against every possible interleaving under real concurrent load. Stated once
here rather than repeated on every row. (The Inventory domain, outside this
registry, does have its own real multi-process/multi-connection proof —
`tests/Concurrency`, see `docs/inventory/INVENTORY-RESERVATIONS.md` and
`docs/architecture/DATABASE-SUPPORT.md` §6.)

## Known Non-Guarantees / Not Supported / Dormant

Explicit, so a future developer or agent never reads "an enum case or
column exists" as "this is a supported feature":

- **Partial refunds are not supported** (PAYMENT-08). Silently ignored, not
  queued, not rejected with an error.
- **`reserved_balance` does not exist.** A payout reservation *is* an
  immediate completed debit — see PAYOUT-03/LEDGER-03's own rationale for
  why this was a deliberate choice, not an oversight.
- **`transaction_statuses.cancelled`/`reversed` are DORMANT.** Seeded,
  never assigned. `StoreWalletTransaction::isCancelled()`/`isReversed()`
  always return `false` in production.
- **`PaymentStatus::Failed` is DORMANT.** See `STATE-MACHINES.md`.
- **`balance_after` cannot be used to reconstruct historical per-transaction
  balances reliably by `id`/`created_at` order** (LEDGER-08).
- **No DB trigger enforces `StoreWalletTransaction` immutability** — only
  application guard clauses (LEDGER-02). A direct `UPDATE` against the table
  bypassing `WalletTransactionService` would not be stopped by the schema.
- **No true parallel (PostgreSQL/MySQL multi-connection) concurrency tests
  exist for any invariant in this registry** — see the Concurrency note
  above. Only the Inventory domain has one (`tests/Concurrency`), and it
  proves Inventory invariants only.
- **No automatic/bank payout provider exists.** `ManualPayoutProvider` is
  the only registered driver; every payout requires a human to execute the
  transfer and confirm it.
- **Eleven of fifteen seeded `transaction_categories` have no production
  producer**: `commission`, `commission_refund`, `chargeback`,
  `chargeback_reversal`, `refund_reversal`, `manual_credit`, `manual_debit`,
  `bonus`, `penalty`, `subscription_fee`, `subscription_refund`. Only
  `sale`, `customer_refund`, `withdrawal`, `withdrawal_reversal` are
  actually written by any code path.
- **Disputes do not exist as a feature.** `chargeback`/`chargeback_reversal`
  are seeded categories only; there is no controller, service, listener, or
  webhook handler that processes a provider dispute event of any kind.
- **EasyPay refunds are not supported at all** (full or partial) — its
  translator maps every refund-shaped event to `Unrecognized`, and its
  webhook controller's `SUPPORTED_TYPES` allow-list never forwards one.
- **Currency `precision` (for zero-decimal currencies like JPY) is ignored
  everywhere in the financial domain.** `MinorUnits::fromDecimal()` and
  every Wallet decimal column hardcode 2 decimal places; the codebase only
  actually operates in 2-decimal currencies today.
- **No payout-attempt health/observability command exists** — only
  `wallet:audit` (balance reconciliation) and `payments:health` (Payments
  only).
- **A `Claimed` `PaymentAttempt` for which the provider reports `succeeded`
  but no webhook was ever delivered has no canonical settlement path at
  all** (discovered and proven by execution trace during
  `feat/financial-reconciliation` — see `docs/financial/RECONCILIATION.md`
  §3.A/§3.B). Neither `PaymentAttemptRecoveryService::recover()` nor
  `PaymentService::finalizeAttempt()` confirms the pending Wallet
  transaction or settles the attempt without a real, delivered (or
  previously stored) provider event — the only code path into
  `PaymentEventProcessor::applySucceeded()`. Phase 1 of reconciliation
  surfaces this state as the `RemoteSucceededNoSettlementPath` finding
  category and leaves it open indefinitely; it does not, and by design
  (`RECON-01`/`RECON-13`) cannot, invent a corrective action for it. Closing
  this gap — a genuine "settle from a polled canonical result, not just a
  webhook" capability — is unimplemented and would be its own separate,
  financially load-bearing design decision.
- **Provider reconciliation (Phase 1) covers Payments only, and only
  Direction 1 (local attempt → provider, via `SupportsCanonicalRetrieval`).**
  Discovering a provider-side payment with no local record at all
  (Direction 2) requires a `SupportsPeriodicExport`-shaped capability not
  implemented for either provider yet — Stripe's API could support it,
  EasyPay's confirmed capability is unresearched. Payout reconciliation
  does not exist at all: `ManualPayoutProvider` has no remote system to
  reconcile against. See `docs/financial/RECONCILIATION.md` §1/§7/§9.2.
- **EasyPay retrieval failures can never produce a `RemoteMissing`
  reconciliation finding, even a response shaped like "not found."**
  `App\Payments\EasyPay\EasyPayPaymentProvider` deliberately does not
  implement `App\Domain\Payments\Contracts\SupportsConfirmedResourceAbsence`
  — `EasyPayRequestException`'s own docblock buckets 400/403/404/422
  together as equally "will fail identically on every retry," with no
  verified evidence in this codebase distinguishing "doesn't exist" from
  any other definitive rejection. Every EasyPay retrieval failure is
  reported as the operational `RetrievalFailed` outcome instead — a
  documented Phase-1 detection gap for EasyPay specifically (Stripe is
  unaffected: its 404 is a genuine, precise signal — `RECON-20`), not a
  bug. See `docs/financial/RECONCILIATION.md` §13.
- **Orders have no fulfillment states and no cancellation.** Only
  `pending`/`paid`/`failed`/`refunded` exist; `accepted/preparing/ready/
  completed/cancelled` are undefined because the product has no buyer, order
  items, or fulfillment actor, and no canonical refund-initiation path a
  cancellation of a paid Order could use (`ORDER-07`/`ORDER-08`). See
  `docs/financial/ORDER-LIFECYCLE.md` §3/§11.
- **Order `failed` is non-terminal and means only "the latest payment attempt
  failed".** A Payment whose attempts all failed stays payable, so a later
  successful attempt moves `failed → paid`. It is a legacy slug name, not a
  verdict on the Order (`ORDER-06`).
- **An Order whose state contradicts the financial fact being settled blocks
  settlement — deliberately.** If `PaymentEventProcessor` reaches a transition
  the Order matrix forbids (only possible from corrupt/inconsistent data, never
  from any code path), the exception rolls the Wallet mutation back too and the
  webhook errors and is retried. Rollback is the only policy that keeps the state
  repairable: `confirm()`/`markFailed()`/`reverse()` are one-shot, so committing
  the Wallet while skipping the Order would leave the Order, Payment and attempt
  permanently stuck. For a stored/replayed event, settlement resumes
  automatically once the Order is repaired; for a *live* webhook nothing local is
  stored, so recovery depends on provider redelivery, after which the attempt
  stays `Claimed` and reconciliation flags it (`RemoteSucceededNoSettlementPath`).
  There is no operator repair tool. Run the read-only pre-deploy consistency
  check in `docs/financial/ORDER-LIFECYCLE.md` §13 before rollout. Same
  fail-closed precedent as `PaymentAttemptNotFoundException`.
- **`OrderTransitioned` is at-most-once, not durable delivery.** It is emitted
  after the outermost commit; a crash between the commit and the callback loses
  it (no outbox). A *synchronous* listener that throws does so after the durable
  commit — the exception reaches the caller but nothing is rolled back.
  Listeners must be idempotent, should be queued, and must never be relied on to
  make a transition happen.
- **Order creation is unconstrained and historical `paid_at`/`refunded_at` are
  `NULL`.** No production code creates Orders yet, so "orders start `pending`"
  is unenforced; rows that transitioned before `feat/order-lifecycle` have no
  recorded transition time (deriving one from `updated_at` would fabricate it).
- **A vendor who owns a Store has no self-service path to close their
  account at all, ever** (CROSS-14's fix). `ProfileController::destroy()`
  unconditionally blocks and points them at "contact support" — there is no
  anonymize/soft-delete-while-preserving-the-ledger flow, no support-side
  tool to resolve it, and no time bound. This is the deliberate, narrower
  trade this branch made (block, never silently lose history) — see
  `FAILURE-MODEL.md`'s "Critical finding" for why the alternative
  (`stores.user_id`'s DB constraint alone, with no app-level check) would
  instead have surfaced as an unhandled 500.
- **Rolling back the CROSS-14 hardening migration
  (`2026_09_16_180000_restrict_financial_history_cascades.php`) genuinely
  reopens the original vulnerability** for any deletion performed after the
  rollback — `down()` is schema-only (existing rows are never touched) but
  is not itself a safe operational state to run in.
  `FinancialHistoryCascadeMigrationSafetyTest` proves the round-trip is
  data-safe; it is not a claim that `down()` is a state you should ever
  deploy.

## Traceability — how to answer "where is this protected?"

For every invariant above, "Code protection" and "Test" columns name the
exact class/test to open. Architecture-level (structural) protections are
listed once here rather than repeated per row:

| Structural boundary | Architecture test |
|---|---|
| Wallet single writer | `tests/Architecture/WalletLedgerSingleWriterTest` |
| Only the canonical financial services (`PaymentService`, `PaymentEventProcessor`, `PayoutService`) call the Wallet writer | `tests/Architecture/WalletLedgerCanonicalCallerTest` |
| No float in money-bearing code | `tests/Architecture/MoneyNoFloatTest` |
| Wallet opening balance always zero | `tests/Feature/Store/WalletServiceOpeningBalanceTest` (feature, not architecture — DB-backed) |
| `wallet:audit`/`WalletLedgerAuditor` are read-only | `tests/Architecture/WalletLedgerReadOnlyAuditTest` |
| No direct Wallet mutation from Payments admin/recovery | `tests/Architecture/PaymentRecoveryNoDirectWalletMutationTest` |
| No direct Wallet mutation from Payouts admin/recovery + single reversal call site | `tests/Architecture/PayoutRecoveryNoDirectWalletMutationTest` |
| Payments domain never imports the Stripe SDK directly | `tests/Architecture/PaymentsDomainBoundaryTest` |
| Payouts domain never imports a concrete provider adapter | `tests/Architecture/PayoutsDomainBoundaryTest` |
| Payout financial FKs restrict on delete, fail-closed on missing policy | `tests/Architecture/PayoutFinancialHistoryAppendOnlyTest` |
| Payout financial FKs restrict on delete, verified against the real schema | `tests/Feature/Domain/Payouts/PayoutSchemaDeletePolicyTest` |
| Payments/Wallet financial FKs restrict on delete, verified against the real schema | `tests/Feature/Domain/Payments/PaymentsSchemaDeletePolicyTest` |
| Payments/Wallet financial FKs resist a future cascade regression / SoftDeletes substitute / direct truncate | `tests/Architecture/FinancialHistoryDeletePolicyTest` |
| The CROSS-14 hardening migration is safe against a database already holding real financial history | `tests/Feature/Domain/Payments/FinancialHistoryCascadeMigrationSafetyTest` |
| `ProfileController::destroy()` blocks account deletion for any Store owner before any mutation | `tests/Feature/ProfileAccountDeletionFinancialHistoryTest` |
| Only the canonical lifecycle service writes an Order's status; only `PaymentEventProcessor` calls a transition; the Orders domain has no Payments/Wallet-write dependency | `tests/Architecture/OrderLifecycleBoundaryTest` |
| This registry itself stays complete | `tests/Architecture/FinancialContractRegistryTest` |
