# Canonical State Machines — shoprdam

Every state below is copied from the actual PHP enum backing it. Where a
seeded/defined state is never assigned by any production code path, it is
labeled **DORMANT** — this is not a value judgement, it's a direct
enum-usage grep result, cited so it can be re-verified.

## Payment (`App\Domain\Payments\Enums\PaymentStatus`)

States: `Pending`, `Paid`, `Failed`, `Refunded`. `isTerminal()`: `Pending` →
false; `Paid`/`Failed`/`Refunded` → true (as declared in the enum).

**Reality check:** `PaymentStatus::Failed` is declared and marked terminal,
but grepping `app/` for `PaymentStatus::Failed` returns zero production
assignments. `App\Domain\Payments\Services\PaymentEventProcessor::applyFailed()`
explicitly passes `null` for the Payment-level status when an attempt fails
(see that method's own comment: *"a terminally failed attempt doesn't mean
the Payment is done"*). **`PaymentStatus::Failed` is DORMANT** — reachable
states in practice are only `Pending → Paid` and `Pending → Refunded`; a
Payment whose every attempt has failed simply stays `Pending` forever,
open for a new attempt with a different provider/method.

```
Pending ──(applySucceeded)──► Paid
Pending ──(applyRefunded, full amount)──► Refunded
Pending ──(every attempt Failed)──► Pending   (stays open, never becomes Failed)
```

Owner of every transition: `PaymentEventProcessor` exclusively (via
`markSettled()`).

## PaymentAttempt (`App\Domain\Payments\Enums\PaymentAttemptStatus`)

States: `Pending`, `Claimed`, `Succeeded`, `Failed`, `NeedsAttention`. All
five are actively produced.

`isTerminal()`: `Succeeded`, `Failed` → true; `Pending`, `Claimed`,
`NeedsAttention` → false.
`blocksNewAttempt()`: everything **except** `Failed` blocks a new attempt —
`Failed::blocksNewAttempt()` is `false` by design, and is the single
load-bearing fact that lets the Payment stay retryable (see enum docblock).

```
Pending ──(claimProviderReference succeeds)──► Claimed
Pending ──(recovery: non-retryable / exhausted / age-exceeded)──► NeedsAttention
Claimed ──(provider event: Succeeded)──► Succeeded          [terminal]
Claimed ──(provider event: Failed — provider's own irreversible terminal)──► Failed  [terminal, blocksNewAttempt=false]
NeedsAttention ──(real evidence eventually arrives)──► Succeeded | Failed
```

Owner: `PaymentService` for `Pending → Claimed`;
`PaymentAttemptRecoveryService` for `→ NeedsAttention`;
`PaymentEventProcessor` exclusively for `→ Succeeded`/`→ Failed`. No status
here is ever reachable from retry-exhaustion alone (see `FAILURE-MODEL.md`
§9).

## Payout (`App\Domain\Payouts\Enums\PayoutStatus`)

States: `Reserved`, `Processing`, `Succeeded`, `Cancelled`, `Failed`. All
five are actively produced.

`isTerminal()`: `Succeeded`, `Cancelled`, `Failed` → true; `Reserved`,
`Processing` → false. `Reserved`/`Processing` are the same "reservation
still standing" state from the ledger's point of view — the difference is
only whether a non-terminal `PayoutAttempt` currently exists.

```
Reserved ──(createDurableAttempt)──► Processing
Processing ──(current attempt resolves to Failed, CAS on current_payout_attempt_id)──► Reserved
Processing ──(current attempt Succeeded)──► Succeeded        [terminal, permanent — reservation debit stands]
Reserved/Processing ──(PayoutService::abandon(), only when no unresolved attempt exists)──► Cancelled | Failed  [terminal, exactly one withdrawal_reversal]
```

Owner: `PayoutService::createDurableAttempt()` for `Reserved → Processing`;
`PayoutEventProcessor::applySucceeded()`/`applyFailed()` exclusively for the
attempt-driven transitions; `PayoutService::abandon()` exclusively for the
two terminal-negative transitions.

## PayoutAttempt (`App\Domain\Payouts\Enums\PayoutAttemptStatus`)

States: `Pending`, `Claimed`, `Succeeded`, `Failed`, `NeedsAttention`. All
five are actively produced. Deliberately mirrors `PaymentAttemptStatus`'s
`isTerminal()`/`blocksNewAttempt()` split exactly, including the same
`Failed::blocksNewAttempt() === false` design — see that enum's own
docblock, which states this explicitly as the intentional parallel.

```
Pending ──(finalizeAttempt claims provider_reference)──► Claimed
Pending/Claimed ──(recovery: non-retryable / exhausted / age-exceeded)──► NeedsAttention
Pending/Claimed/NeedsAttention ──(manual confirmation or future webhook: Succeeded)──► Succeeded  [terminal]
Pending/Claimed/NeedsAttention ──(manual confirmation or future webhook: Failed)──► Failed        [terminal, blocksNewAttempt=false]
```

**Critical distinction from Payments:** a `PayoutAttempt` reaching `Failed`
does **not** by itself release the Payout's reservation and does **not**
change `Payout.status` unless that attempt is still the one
`Payout.current_payout_attempt_id` points to (enforced by a single
conditional `UPDATE` in `PayoutEventProcessor::applyFailed()` — see
`INVARIANTS.md` CROSS-08 and `tests/Feature/Domain/Payouts/PayoutEventProcessorTest`'s
"EXACT CURRENT ATTEMPT ISOLATION" test).

## StoreWalletTransaction (`transaction_statuses` table — not a PHP enum)

Seeded slugs: `pending`, `completed`, `failed`, `cancelled`, `reversed`.

**Reality check:** only `pending`, `completed`, `failed` are ever assigned
by `App\Services\Wallet\WalletTransactionService` (`record()`, `confirm()`,
`markFailed()`). Grepping for where `cancelled`/`reversed` would be set
finds nothing — `App\Models\StoreWalletTransaction::isCancelled()`/
`isReversed()` exist but are **DORMANT**: no code path ever makes them
return `true`. A reversed transaction's *original* row is never transitioned
to `reversed` — it stays `completed` forever; the reversal is a distinct new
row (`related_transaction_id` pointing back).

```
(created) ──[status=completed at insert]──► completed   (the common case: most categories go straight here)
(created) ──[status=pending at insert]──► pending ──(confirm())──► completed
                                                 └──(markFailed())──► failed   [terminal, never re-touched]
completed ──[immutable forever — see LEDGER-02]
```

`cancelled`/`reversed` are **DORMANT / SEEDED BUT NOT CURRENTLY PRODUCED**.

## PaymentProviderEvent (`App\Domain\Payments\Enums\ProviderEventStatus`)

States: `Pending`, `Applied`. Both actively produced.

```
(webhook/replay, unresolved locally) ──► pending
pending ──(PaymentEventProcessor::replay() resolves it)──► applied   [terminal — never replayed again]
```

A `pending` row can persist indefinitely if its owning `PaymentAttempt`
never leaves `needs_attention` — the pruning command
(`App\Console\Commands\PrunePaymentProviderEvents`) only ever deletes
`applied` rows past a retention window, and says so in its own docblock.
This table has no Payout-side equivalent — see `FAILURE-MODEL.md` §9 for why
that gap exists and what it means.
