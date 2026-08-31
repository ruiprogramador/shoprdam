<?php

namespace App\Domain\Payments\Enums;

/**
 * One PaymentAttempt's own lifecycle — deliberately excludes reconciliation
 * lease/ownership state (see `payment_attempts.locked_until`) and Payment-level
 * state (see PaymentStatus). Those are different concerns with different
 * owners; folding them into one enum was the mistake this domain avoids —
 * see docs/wallet/integrations.md.
 *
 * `isTerminal()` answers "is this attempt done, one way or another" for
 * display/auditing purposes. `blocksNewAttempt()` answers the narrower,
 * safety-critical question App\Domain\Payments\Services\PaymentService::startOrResumeAttempt()
 * actually asks: "can a *new* attempt be started for this Payment right
 * now?" The two are not the same set — NeedsAttention is not terminal (a
 * human hasn't resolved it yet) but it still must block a new attempt: the
 * provider may already have accepted this attempt's payment, and starting
 * a second one before that's confirmed one way or the other risks a
 * double payment.
 */
enum PaymentAttemptStatus: string
{
    /**
     * Durable pre-provider-call record — written before the provider is
     * ever contacted, in its own fast commit, so a crash between that
     * write and the provider call (or the local write that follows it)
     * leaves durable evidence an attempt was in progress. See
     * App\Console\Commands\ReconcileOrphanedPaymentAttempts.
     */
    case Pending = 'pending';

    /**
     * The provider returned a reference and it was validated against this
     * attempt's Payment (amount/currency/correlation id) — a pending
     * Wallet transaction now exists for it. Awaiting the provider's
     * terminal webhook to settle one way or the other.
     */
    case Claimed = 'claimed';

    /** Settled: the Wallet transaction is `completed`, the Payment is `paid`. */
    case Succeeded = 'succeeded';

    /**
     * Terminally failed/canceled/expired at the provider. Never retried
     * automatically; a new attempt may follow.
     *
     * `Failed::blocksNewAttempt()` is `false` — this is the one status that
     * lets `PaymentService::createDurableAttempt()` start a fresh attempt
     * for the same Payment (a different provider/method) while the old one
     * still exists. That makes `Failed` a load-bearing financial invariant,
     * not just a display label: it must mean **the remote payment this
     * attempt represents is irreversibly terminal and can never later
     * settle successfully.** If it meant anything weaker — a retryable
     * provider error, an intermediate/non-final state, a single failed
     * authorization that the provider itself still lets resolve to success
     * — then a new attempt could go on to *also* succeed, producing two
     * live charge paths for the same Payment and a double Wallet credit.
     *
     * Nothing in this domain ever sets `Failed` directly for that reason:
     * it's reached exclusively through
     * `PaymentEventProcessor::applyFailed()`, itself reached only via
     * `ProviderEventType::Failed` — see that enum's own docblock, and
     * "Terminal Failed vs. a retryable attempt-level failure" in
     * docs/wallet/integrations.md. A provider's own translator (e.g.
     * `App\Payments\Stripe\StripeEventTranslator`) is the single place
     * responsible for only ever producing `ProviderEventType::Failed` for a
     * native event that is *itself* irreversibly terminal at that
     * provider — never for a retryable/intermediate/non-final failure
     * signal, which must translate to `Informational` (or another
     * non-terminal outcome) instead.
     */
    case Failed = 'failed';

    /** Stuck; requires a human to look at it before anything else happens for this Payment. */
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
}
