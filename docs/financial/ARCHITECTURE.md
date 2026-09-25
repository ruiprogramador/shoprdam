# Financial Architecture — shoprdam

This is the canonical, verifiable description of the financial architecture
that exists in `main` today (as of `harden/financial-architecture-contract`,
built on `feat/store-payouts` and `harden/wallet-ledger-invariants`). It
describes the system that **exists**, not the system anyone would prefer to
exist. Every claim here is either evidenced by production code, a database
constraint, or a test cited by name — see `INVARIANTS.md` for the
traceability matrix. Where reality and a plausible-sounding guarantee
diverge, this document says so explicitly rather than rounding up.

See also: `INVARIANTS.md`, `STATE-MACHINES.md`, `MONEY-FLOWS.md`,
`FAILURE-MODEL.md`.

## 1. The three domains

| Domain | Root namespace | Aggregate | Attempt/execution unit | Ledger effect |
|---|---|---|---|---|
| Payments | `App\Domain\Payments` | `Payment` (one per `Order`) | `PaymentAttempt` | credit (`sale`), reversed by `customer_refund` |
| Payouts | `App\Domain\Payouts` | `Payout` (one per withdrawal request) | `PayoutAttempt` | debit (`withdrawal`), reversed by `withdrawal_reversal` |
| Wallet | `App\Services\Wallet`, `App\Models\StoreWallet*` | `StoreWallet` | `StoreWalletTransaction` (the ledger row itself) | is the ledger |

Payments and Payouts are structurally parallel (aggregate → attempt →
provider), deliberately never share a model (`PaymentAttempt` and
`PayoutAttempt` are distinct tables and classes — Payouts never reuses
Payments' attempt machinery), and both terminate in exactly one place: the
Wallet ledger.

## 2. Canonical writers (see `INVARIANTS.md` §Traceability for the full matrix)

- **The only class that ever creates a `StoreWalletTransaction` row or
  mutates `store_wallets.balance` in production code is
  `App\Services\Wallet\WalletTransactionService`.** Proven, not assumed —
  `tests/Architecture/WalletLedgerSingleWriterTest` scans the entire `app/`
  tree by exact canonical path (not by class basename) for every
  Eloquent/`DB::` write primitive against either table and asserts zero
  matches outside `WalletTransactionService.php` and `WalletService.php`
  (the latter only ever creates a wallet at a `0.00` opening balance, never
  touches the ledger — see that test's second assertion).
- **`App\Domain\Payments\Services\PaymentEventProcessor`** is the only class
  allowed to translate a provider-neutral payment outcome into a Wallet
  effect: `confirm()` (settlement), `markFailed()` (attempt failure) and
  `reverse()` (full refund) on the pending/completed `sale` — enforced for
  the admin recovery surface by
  `tests/Architecture/PaymentRecoveryNoDirectWalletMutationTest`. The pending
  `sale` itself is created earlier, at claim time, by
  **`App\Domain\Payments\Services\PaymentService::claimProviderReference()`**
  through `record()` (see `MONEY-FLOWS.md` §A/§J).
- **Payouts have no event-driven Wallet effect.**
  `App\Domain\Payouts\Services\PayoutEventProcessor` never calls the Wallet:
  the reservation debit posted at request time *is* the settlement
  (`MONEY-FLOWS.md` §F). Both Payout Wallet effects live in
  **`App\Domain\Payouts\Services\PayoutService`**: `request()` posts the
  `withdrawal` through `record()`, and `abandon()` posts the single
  `withdrawal_reversal` through `reverse()` —
  `tests/Architecture/PayoutRecoveryNoDirectWalletMutationTest` asserts this
  is the *only* call site of `->reverse(` anywhere in `App\Domain\Payouts`.
- These three classes — `PaymentService`, `PaymentEventProcessor`,
  `PayoutService` — are the only production callers of
  `WalletTransactionService` at all, asserted as an exact set by
  `tests/Architecture/WalletLedgerCanonicalCallerTest`.
- Nothing in `app/Http/Controllers/**`, `app/Console/Commands/**`, or any
  provider adapter (`App\Payments\*`, `App\Payouts\*`) ever constructs a
  Wallet effect directly. Every mutating admin action
  (`PaymentRecoveryController`, `PayoutRecoveryController`) delegates to one
  of the two Event Processors above, or to the same recovery service the
  scheduled reconciliation command already trusts.

## 3. Provider abstraction

Both domains follow the same shape, kept deliberately thin:

```
provider-native event/response
        ↓  (provider's own translator/adapter)
generic domain outcome (ProviderEventOutcome / PayoutProviderOutcome)
        ↓
canonical Event Processor
        ↓
Wallet effect (WalletTransactionService)
```

- Payments: `App\Domain\Payments\Contracts\PaymentProviderContract` +
  `App\Domain\Payments\PaymentProviderManager` (a `Manager` with no default
  driver — every caller resolves by the attempt's own `provider` column).
  Two adapters today: `App\Payments\Stripe\StripePaymentProvider`,
  `App\Payments\EasyPay\EasyPayPaymentProvider`. Two webhook events
  translators: `StripeEventTranslator`, `EasyPayEventTranslator`, registered
  in `config/payments.php`.
- Payouts: `App\Domain\Payouts\Contracts\PayoutProviderContract` +
  `App\Domain\Payouts\PayoutProviderManager`, same no-default-driver shape.
  One adapter today: `App\Payouts\Manual\ManualPayoutProvider` (no HTTP —
  an operator executes the SEPA transfer outside the application and
  reports it through an audited admin action). Registered in
  `config/payouts.php`. `tests/Architecture/PayoutsDomainBoundaryTest`
  proves `App\Domain\Payouts` never imports a concrete adapter namespace;
  `tests/Architecture/PaymentsDomainBoundaryTest` proves the Payments
  domain never imports the Stripe SDK directly.
- **Method vs. provider (Payments only):** a customer-facing "method"
  (`card`, `mbway`, `multibanco`, see `config('payments.methods')`) is not
  a provider. `mbway` and `multibanco` both resolve to the `easypay`
  provider — `App\Domain\Payments\PaymentMethodCatalog` is the only place
  that maps one to the other; `App\Http\Controllers\Vendor\OrderPaymentController`
  and `PaymentService` only ever see the resolved provider name, never
  re-derive it from the method.

## 4. Recovery and reconciliation ownership

Both domains share one design: the same algorithm a scheduled command runs
automatically is the *only* algorithm a human-triggered admin retry is
allowed to run — never a separately-implemented "admin version".

| | Payments | Payouts |
|---|---|---|
| Per-attempt algorithm | `App\Domain\Payments\Services\PaymentAttemptRecoveryService` | `App\Domain\Payouts\Services\PayoutAttemptRecoveryService` |
| Scheduled command | `App\Console\Commands\ReconcileOrphanedPaymentAttempts` (`app:reconcile-orphaned-payment-attempts`, every 5 min) | `App\Console\Commands\ReconcileOrphanedPayoutAttempts` (`app:reconcile-orphaned-payout-attempts`, every 5 min) |
| Admin controller | `App\Http\Controllers\Admin\PaymentRecoveryController` | `App\Http\Controllers\Admin\PayoutRecoveryController` |
| Admin policy | `App\Policies\PaymentRecoveryPolicy` | `App\Policies\PayoutRecoveryPolicy` |
| Audit trail | `payment_recovery_actions` | `payout_recovery_actions` |
| Read-only health snapshot | `php artisan payments:health` (`PaymentsHealthCheck`) | none — `php artisan wallet:audit` covers the ledger-balance angle only, not payout-attempt staleness |

Both schedules use `->withoutOverlapping()->onOneServer()` — an operational
safeguard against two scheduler ticks racing, **not** the mechanism that
makes concurrent recovery safe (see `FAILURE-MODEL.md` §10 for why: the real
protection is the lease/CAS inside each recovery service, which is what
still holds even if two workers *did* run at once).

## 5. Observability

- `php artisan payments:health` — read-only, reports stuck attempts/events
  against configurable thresholds (`config('payments.health')`), never
  mutates anything (`App\Domain\Payments\Services\PaymentsHealthCheck` is
  SELECT-only by its own docblock contract).
- `php artisan wallet:audit` — read-only, reconciles every `StoreWallet`'s
  stored `balance` against its own ledger
  (`App\Services\Wallet\WalletLedgerAuditor`). Never writes; see
  `INVARIANTS.md` LEDGER-07 and `tests/Architecture/WalletLedgerReadOnlyAuditTest`.
- No equivalent payout-attempt health command exists — a documented gap,
  not a hidden one (see `FAILURE-MODEL.md` and Known Non-Guarantees).

## 6. Admin boundary

Exactly three admin controllers touch financial/identity state:
`PaymentRecoveryController`, `PayoutRecoveryController`, `KycController`
(the last only reviews identity verification, never moves money — see
`MONEY-FLOWS.md` §E for why KYC is a payout *precondition* in intent but has
no code-level linkage to `PayoutService` today). No `Admin\PaymentController`,
`Admin\PayoutController`, or `Admin\WalletController` exists — an admin
cannot mark anything `succeeded` directly, cannot edit a balance, and cannot
create a `StoreWalletTransaction`; every mutating admin action is a call
into a canonical domain service, proven by the two
`*NoDirectWalletMutationTest` architecture tests.

## 7. Financial history's append-only guarantee is now uniform (closed by `harden/financial-history-cascade-protection`)

**This architecture's append-only guarantee for financial history now holds
identically across Payouts and Payments/Wallet — it was not always true,
and this section says so plainly rather than rewriting history.** The
Payouts domain enforced it at the database level from its first migration
(`restrictOnDelete()` on every FK in `payouts`/`payout_attempts`/
`payout_recovery_actions`, verified by `tests/Feature/Domain/Payouts/PayoutSchemaDeletePolicyTest`
against the real `PRAGMA foreign_key_list` output). **The Payments and
Wallet domains did not**, until this branch: `payments.order_id`,
`payment_attempts.payment_id`, `orders.store_id`, `store_wallets.store_id`,
and `store_wallet_transactions.store_wallet_id` were all
`cascadeOnDelete()`, and a real, unguarded production code path — a
vendor's own "delete my account" action
(`App\Http\Controllers\User\ProfileController::destroy()`) — performed a
hard `User::delete()` that cascaded through `stores.user_id
cascadeOnDelete()` all the way down to permanently destroying that store's
entire Wallet ledger. See `FAILURE-MODEL.md` §"Critical finding" (now
marked **RESOLVED**, with the original reproduction preserved) and
`INVARIANTS.md` CROSS-14 (now **ENFORCED**) for the full evidence.

This branch closed the gap with two layers:

- **Database:** `database/migrations/2026_09_16_180000_restrict_financial_history_cascades.php`
  changes all six FKs above, plus `stores.user_id`, to `restrictOnDelete()`
  — the database itself now refuses to delete a User/Store/Wallet/Order/
  Payment while any financial-history child still references it, the same
  way Payouts' own schema already did. Verified against the real migrated
  schema by `tests/Feature/Domain/Payments/PaymentsSchemaDeletePolicyTest`,
  and proven safe to run against a database already holding real financial
  history by `tests/Feature/Domain/Payments/FinancialHistoryCascadeMigrationSafetyTest`.
- **Application:** `ProfileController::destroy()` now refuses to delete a
  User who owns any Store — trashed or not — *before* calling
  `$user->delete()`, turning what used to be either silent data loss or an
  unhandled `QueryException` into a normal, controlled validation-error
  response. Verified across a plain customer, an empty Store, a
  soft-deleted-only Store, and Stores with Wallet, Payment, Payout, and
  mixed financial history by `tests/Feature/ProfileAccountDeletionFinancialHistoryTest`.

**What this deliberately does not do:** offer any self-service path for a
vendor with financial history to actually close their account —
`ProfileController::destroy()` blocks unconditionally and says "contact
support." Anonymizing or soft-deleting a vendor's identity while preserving
their ledger remains unimplemented; see `INVARIANTS.md`'s "Known
Non-Guarantees" section. And rolling the migration's `down()` back
genuinely reopens the original vulnerability for any deletion performed
afterward — `down()` is schema-only and never touches existing rows, but it
is not an operationally safe state to run in; see `FAILURE-MODEL.md`'s
"Resolution" section for both of these caveats stated in full.

## 8. Keeping this contract from going stale

Documentation that describes a system it no longer matches is worse than no
documentation — it actively misleads. A PR that does any of the following
**must** evaluate, and update where applicable, `docs/financial/*` +
`INVARIANTS.md`'s registry + the corresponding tests, in the same PR:

- adds a new financial state/status
- adds a new payment or payout provider
- adds a production producer for a currently-dormant Wallet transaction
  category
- changes settlement, refund, or payout semantics
- changes idempotency/reference rules
- changes a financial foreign key's delete policy
- changes recovery/reconciliation behavior
- introduces a new economic effect (a new kind of credit/debit)

This is a review-time discipline, not something grepped for automatically —
see Phase 10/12 of this branch's own design record for why no attempt was
made to detect "is this change financial" semantically:
`tests/Architecture/FinancialContractRegistryTest` only guards the
narrower, mechanical failure mode of an ID or a known-limitation entry
silently disappearing from the registry — it cannot and does not verify
that a new financial behavior was documented at all. That remains a human
(or reviewing agent) judgment call at PR time.

## 9. Provider reconciliation — Phase 1 (`feat/financial-reconciliation`)

Full design record: `docs/financial/RECONCILIATION.md`. Full invariant list:
`INVARIANTS.md`'s `RECON-XX` section. Summarized here per this document's
own §8 discipline (this branch changes recovery/reconciliation-adjacent
observability).

Phase 1 answers a question nothing above this section answers: does a
`PaymentAttempt`'s local belief about its own outcome still agree with the
provider's own canonical record of it, independent of whether a webhook
ever arrived to tell us? `App\Console\Commands\ReconcilePaymentsAgainstProvider`
(scheduled every 5 minutes, `withoutOverlapping()->onOneServer()`) is
**strictly detection + persistence + observability** — it never mutates a
Wallet, a `Payment`, a `PaymentAttempt`, an `Order`, or a
`PaymentProviderEvent`, and never calls
`PaymentAttemptRecoveryService::recover()`,
`PaymentService::finalizeAttempt()`, or `PaymentEventProcessor::apply()` —
enforced mechanically by `tests/Architecture/ReconciliationNoFinancialMutationTest`,
the same "plain source scan" pattern as `PaymentRecoveryNoDirectWalletMutationTest`.

Shape: `App\Domain\Payments\Services\ProviderReconciler` reads a
`PaymentAttempt` with a known `provider_reference`, calls the resolved
provider's existing `SupportsCanonicalRetrieval::retrieveByReference()`
(already implemented by both `StripePaymentProvider` and
`EasyPayPaymentProvider`), classifies the result via the pure
`App\Domain\Payments\Services\ReconciliationClassifier`, and persists a
bounded episode via `App\Domain\Payments\Services\ReconciliationFindingRepository`
to `payment_reconciliation_findings`. Zero categories are automatically
actionable — proven, not assumed, by execution trace against
`PaymentAttemptRecoveryService`/`PaymentService`/`PaymentEventProcessor`
(see `RECONCILIATION.md` §3), which is why this feature adds an
observability surface, not a new settlement path, and why the "recovery and
reconciliation ownership" table in §4 above is unchanged by it — Phase 1
reconciliation owns none of that table's cells.

One narrow, genuinely new piece of provider code exists:
`App\Domain\Payments\Contracts\SupportsConfirmedResourceAbsence::isConfirmedAbsent()`,
an optional capability (mirroring `SupportsCanonicalRetrieval`'s own
optionality) that `StripePaymentProvider` implements via its own
`getHttpStatus() === 404` check. This exists because a post-implementation
evidence-correctness audit found that classifying a `RemoteMissing` finding
from `FailureClass::NonRetryable` alone — a bucket that also covers
authentication/permission/malformed-request failures — was not evidenced;
see `RECONCILIATION.md` §13/§21 (`RECON-20`) for the full finding.
`EasyPayPaymentProvider` deliberately does not implement this capability —
no verified evidence distinguishes EasyPay's "not found" response from any
other definitive rejection, so EasyPay retrieval failures can never produce
a `RemoteMissing` finding in Phase 1 (see `INVARIANTS.md`'s Known
Non-Guarantees).

Out of scope for Phase 1, explicitly: Payout reconciliation (no remote
system exists behind `ManualPayoutProvider` to reconcile against); Direction
2 (discovering a provider-side payment with no local record at all — needs
a `SupportsPeriodicExport`-shaped capability not implemented for either
provider); any automatic corrective action for any finding category,
including the one category (`RemoteSucceededNoSettlementPath`) that
`RECONCILIATION.md` §3 shows has no existing canonical settlement path at
all — see `INVARIANTS.md`'s Known Non-Guarantees for that gap stated in
full.

## 10. Order lifecycle (`feat/order-lifecycle`)

Full record: `ORDER-LIFECYCLE.md`; invariants: `INVARIANTS.md`'s `ORDER-XX`.

An Order's status now changes through exactly one boundary,
`App\Domain\Orders\Services\OrderLifecycleService` (`markPaid`,
`markPaymentFailed`, `markRefunded` — no generic setter). The direction of
authority is **canonical financial settlement → allowed Order transition**:
`PaymentEventProcessor::markSettled()` — the only caller — invokes it after the
Wallet has settled, inside the same transaction, and each transition is
authorized by the Order's own `sale` Wallet transaction (re-read from the
database). The Orders domain only *reads* Wallet rows as evidence; it has no
dependency on the Payments domain and never writes Wallet, Payment, or provider
state. This adds an authorization/consistency layer, not a settlement path: the
"Canonical writers" of §2 are unchanged.

Concurrency is a row lock plus compare-and-set on the fresh state. Settlement is
atomic: if the Order transition (or anything after it) fails, the Wallet
mutation rolls back too — the only policy that keeps a corrupt-Order settlement
repairable, since the Wallet verbs are one-shot (`ORDER-LIFECYCLE.md` §5).
`OrderTransitioned` is emitted after the outermost commit and is at-most-once,
not durable delivery; a throwing synchronous listener fails *after* the commit.
Enforced by `OrderLifecycleBoundaryTest` (static scans of production code with
self-tests) and a `performUpdate()` runtime guard on `App\Models\Order` that
quiet saves cannot bypass. Explicitly not built: fulfillment states,
cancellation, refund initiation, any Order UI, any operator repair tool.
