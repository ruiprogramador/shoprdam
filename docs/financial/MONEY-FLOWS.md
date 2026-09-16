# Money Flows — shoprdam

Reconstructed from production code, not from prior design intent. See
`STATE-MACHINES.md` for the states referenced here and `INVARIANTS.md` for
the invariant IDs cited.

## A. Payment success

```
Provider (Stripe/EasyPay)
  → native webhook event, signature/shape-verified
      (Stripe: Stripe\Webhook::constructEvent + Stripe-Signature header;
       EasyPay: no signature — the controller re-fetches the canonical
       resource from EasyPay's own API by id before trusting anything)
  → provider's own translator → ProviderEventOutcome{type: Succeeded}
  → PaymentEventProcessor::apply() → applySucceeded()
  → finds the exact StoreWalletTransaction by (external_provider, external_reference)
      — never by Payment.current_payment_attempt_id (CROSS-10)
  → WalletTransactionService::confirm() — pending sale → completed, wallet.balance credited
  → markSettled(): Order.order_status_id, Payment.status=Paid,
    PaymentAttempt.status=Succeeded (attempt resolved by the exact
    (provider, provider_reference) it claimed, never "whichever is current")
```

The pending `sale` transaction itself is created earlier, at claim time
(`PaymentService::claimProviderReference()`), not at webhook time — see
§J and `FAILURE-MODEL.md` crash point 5.

## B. Payment failure — attempt vs. aggregate (never collapsed)

**PaymentAttempt failure** — `PaymentEventProcessor::applyFailed()`:
`WalletTransactionService::markFailed()` on the pending `sale` transaction
(never touches `wallet.balance` — a pending transaction never had an
effect to undo), `PaymentAttempt.status = Failed`.

**Payment aggregate status** — deliberately left `null`/untouched by that
same call (see `STATE-MACHINES.md`: `PaymentStatus::Failed` is DORMANT).
The Payment stays `Pending`, `Payment.current_payment_attempt_id` still
points at the failed attempt, but `PaymentAttemptStatus::Failed::blocksNewAttempt()
=== false` lets `PaymentService::createDurableAttempt()` start a fresh
attempt (different provider/method) for the same Payment.

These are never the same concept in this codebase: an attempt failing is a
fact about *that attempt*; nothing here ever infers "the Payment failed"
from it.

## C. Full refund

```
Provider refund event (Stripe charge.refunded; EasyPay: NOT SUPPORTED, see below)
  → ProviderEventOutcome{type: Refunded, refundedAmountMinorUnits}
  → PaymentEventProcessor::applyRefunded()
  → finds the original completed sale transaction by (provider, provider_reference)
  → compares refundedAmountMinorUnits to the original amount (MinorUnits::fromDecimal)
  → if equal: WalletTransactionService::reverse() — new completed
    `customer_refund` transaction, amount = original->amount exactly,
    related_transaction_id = original.id
  → markSettled(): Order status, Payment.status = Refunded
```

`reverse()` structurally cannot exceed the original amount — it has no
parameter to pass a different one; it always uses `$original->amount`
(CROSS-04).

## D. Partial refund

**NOT SUPPORTED.** `PaymentEventProcessor::applyRefunded()` compares the
provider's reported refunded amount to the full original amount; if they
differ, it logs `Log::warning('Ignoring a partial refund: reversal only
happens once the refund is full.')` and returns
`EventApplicationOutcome::Applied` **without ever calling `reverse()`**. The
ledger is never touched — not partially applied, not queued, not retried.
This is the current, real behavior, not a limitation being worked around:
do not read this as "eventually processed."

EasyPay's own translator maps every refund-shaped event type to
`Unrecognized` (its `SUPPORTED_TYPES` allow-list on the webhook controller
doesn't even forward refund notifications) — EasyPay refunds are **NOT
SUPPORTED** at all, full or partial, through this codebase today.

## E. Payout request

```
Store (via PayoutService::request(), no HTTP controller wired to it yet — see §16 of the audit)
  → resolves the wallet by (store, currency) explicitly — never ambiguous
  → one DB transaction: insertOrIgnore(payouts) + WalletTransactionService::record('withdrawal', completed)
  → Payout row + its debit either both exist or neither does
```

The debit is posted **immediately, as `completed`** — this is an accounting
reservation, not proof any transfer has executed (CROSS-05). KYC identity
verification (`App\Http\Controllers\Admin\KycController`) is a plausible
real-world precondition for allowing payouts but has **no code-level
linkage** to `PayoutService` — nothing in `PayoutService::request()` checks
KYC status. This is worth noting as a gap, not asserted as protected.

## F. Payout success — proof of no second debit

```
Operator confirms manually (or a future automatic provider's webhook)
  → PayoutProviderOutcome{type: Succeeded, externalTransferReference}
  → PayoutEventProcessor::applySucceeded()
  → CAS: PayoutAttempt status → Succeeded (only if still non-terminal), external_transfer_reference set once
  → CAS: Payout status → Succeeded
  → NO WalletTransactionService call anywhere in this path
```

The reservation debit posted at request time **is** the final financial
effect, permanently. `tests/Feature/Domain/Payouts/PayoutEventProcessorTest`
proves this directly (`it settles a succeeded outcome without posting a
second wallet transaction`) — CROSS-06.

## G. PayoutAttempt failure vs. Payout terminal failure

**PayoutAttempt Failed** (`PayoutEventProcessor::applyFailed()`): the
specific attempt is marked `Failed`; **the reservation is never released**,
**`Payout.status` is never moved to a terminal state** by this call. If —
and only if — that attempt is *still* the one `Payout.current_payout_attempt_id`
points to, `Payout.status` moves `Processing → Reserved` (still non-terminal,
still holding the reservation, now eligible for a new attempt).

**Payout terminal Failed/Cancelled** is reached **exclusively** through
`PayoutService::abandon()` — an explicit decision about the aggregate,
never a side effect of one attempt's outcome. See `STATE-MACHINES.md` and
CROSS-08.

## H. Payout terminal abandon/cancel

```
PayoutService::abandon($payout, Cancelled|Failed, $reason)
  → lockForUpdate() on the Payout row
  → if already terminal: no-op (idempotent)
  → if current attempt still Pending/Claimed/NeedsAttention: throws
    PayoutHasUnresolvedAttemptException — refuses to release funds while
    the provider's real outcome is unknown
  → otherwise: WalletTransactionService::reverse('withdrawal_reversal') —
    exactly once, protected by reverse()'s own childTransactions() guard
  → Payout.status = Cancelled | Failed
```

Exactly one `withdrawal_reversal` per Payout, ever — CROSS-07, proven by
`tests/Feature/Domain/Payouts/PayoutAttemptLifecycleTest::'never reverses
the same payout twice'`.

## I. Payout timeout / unknown outcome

**Explicit rule, verified in code:** an HTTP exception or timeout from a
provider call is never treated as proof of financial failure, and never
triggers a reversal. `App\Domain\Payouts\Services\PayoutAttemptRecoveryService::recover()`
only ever produces `RecoveryOutcome::NeedsAttention` or `RetryPending` on
exception — it never calls `PayoutEventProcessor::applyFailed()` and never
calls `abandon()`. The only path to `PayoutAttemptStatus::Failed` is real
evidence (a manual confirmation or, in the future, an authenticated provider
webhook/poll) arriving through `PayoutEventProcessor`. CROSS-09.

## J. Recovery / reconciliation ownership

Both domains share the exact shape (see `ARCHITECTURE.md` §4):

```
durable pre-provider-call row (created before any HTTP call)
  → provider call (idempotent under the row's own deterministic idempotency_key)
  → claim via conditional UPDATE ... WHERE provider_reference IS NULL (CAS, never a plain save)
  → [Payments only] pending Wallet transaction posted at claim time
  → terminal outcome via the domain's own Event Processor
```

Lease acquisition (`locked_until`), `recovery_attempts`, and
`last_attempted_at` are managed identically in
`PaymentAttemptRecoveryService`/`PayoutAttemptRecoveryService` — a single
conditional `UPDATE ... WHERE status = 'pending' AND (locked_until IS NULL
OR locked_until <= now())`, never a plain `->update()`. See
`FAILURE-MODEL.md` for the full crash-point walkthrough.
