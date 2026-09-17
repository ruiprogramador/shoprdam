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
  effect (`record()`/`confirm()`/`markFailed()`/`reverse()` calls) —
  enforced for the admin recovery surface by
  `tests/Architecture/PaymentRecoveryNoDirectWalletMutationTest`.
- **`App\Domain\Payouts\Services\PayoutEventProcessor`** is the equivalent
  for Payouts, with one deliberate asymmetry from Payments: it never calls
  `WalletTransactionService::reverse()` itself — only
  **`App\Domain\Payouts\Services\PayoutService::abandon()`** does, and
  `tests/Architecture/PayoutRecoveryNoDirectWalletMutationTest` asserts this
  is the *only* call site of `->reverse(` anywhere in `App\Domain\Payouts`.
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
