<?php

namespace App\Domain\Payouts\Enums;

/**
 * One PayoutAttempt's own lifecycle — deliberately mirrors
 * App\Domain\Payments\Enums\PaymentAttemptStatus, including its central,
 * safety-critical distinction between `isTerminal()` (is this attempt done)
 * and `blocksNewAttempt()` (can a *new* attempt be started for this Payout
 * right now).
 *
 * `Failed::blocksNewAttempt()` is `false` — this is what lets
 * App\Domain\Payouts\Services\PayoutService::createDurableAttempt() start a
 * fresh attempt for the same Payout once this one is known, for certain, to
 * have gone nowhere. That makes `Failed` a load-bearing invariant here too:
 * it must mean the remote transfer this specific attempt represents is
 * irreversibly terminal and can never later succeed — never a retryable
 * provider hiccup or a still-unknown outcome. Reached exclusively through
 * App\Domain\Payouts\Services\PayoutEventProcessor::applyFailed(), itself
 * only ever invoked from real evidence (a manual confirmation today, a
 * provider webhook/poll in the future) — never from
 * PayoutAttemptRecoveryService running out of retries, which only ever
 * produces NeedsAttention.
 *
 * Critically — and this is the correction that separates this domain from a
 * naive copy of Payments — `Failed` here does NOT by itself release the
 * Payout's reservation. Releasing the reservation (a `withdrawal_reversal`)
 * is a decision about the *Payout*, not the attempt: see
 * PayoutStatus and PayoutService::abandon(). A Failed attempt just means
 * "try again, or give up on the whole Payout" is now a decision someone
 * (an admin, or a future automatic retry policy) gets to make — it never
 * itself moves money.
 */
enum PayoutAttemptStatus: string
{
    /**
     * Durable pre-provider-call record — written before the provider is
     * ever contacted, in its own fast commit. See
     * App\Domain\Payouts\Services\PayoutService::createDurableAttempt().
     */
    case Pending = 'pending';

    /**
     * The provider returned a reference (claimed via a conditional
     * `UPDATE ... WHERE provider_reference IS NULL`) — awaiting a terminal
     * outcome (manual confirmation, or a future provider webhook/poll) one
     * way or the other. No wallet effect happens at this point — the
     * reservation debit was already posted at Payout creation.
     */
    case Claimed = 'claimed';

    /**
     * Terminal, permanent. The Payout's reservation debit stands as the
     * final financial effect — nothing further is ever posted to the
     * ledger. Reached exclusively via
     * PayoutEventProcessor::applySucceeded().
     */
    case Succeeded = 'succeeded';

    /**
     * Terminally failed at the provider (or definitively rejected by a
     * manual confirmation) — never retried under this same attempt row. A
     * new attempt may follow for the same Payout; see this enum's own
     * docblock and PayoutStatus.
     */
    case Failed = 'failed';

    /** Stuck; requires a human to look at it before anything else happens for this attempt. */
    case NeedsAttention = 'needs_attention';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed => true,
            self::Pending, self::Claimed, self::NeedsAttention => false,
        };
    }

    public function blocksNewAttempt(): bool
    {
        return match ($this) {
            self::Failed => false,
            self::Pending, self::Claimed, self::Succeeded, self::NeedsAttention => true,
        };
    }

    /**
     * Whether a terminal outcome (Succeeded/Failed) may still be applied
     * against an attempt currently in this status — everything non-terminal,
     * including NeedsAttention (a human hasn't resolved it, but real
     * evidence arriving is still meaningful and must still be able to
     * settle it). See PayoutEventProcessor's conditional UPDATEs.
     */
    public function acceptsOutcome(): bool
    {
        return ! $this->isTerminal();
    }
}
