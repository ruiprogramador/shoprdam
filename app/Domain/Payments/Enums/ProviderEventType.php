<?php

namespace App\Domain\Payments\Enums;

/**
 * The generic outcome a provider's native webhook event translates to — see
 * App\Domain\Payments\Contracts\ProviderEventTranslator and
 * App\Domain\Payments\DTOs\ProviderEventOutcome. This is the vocabulary
 * PaymentEventProcessor actually understands; translating a provider's own
 * event types (Stripe's `payment_intent.succeeded`, `charge.refunded`, ...)
 * into this small set is each provider adapter's job.
 */
enum ProviderEventType
{
    case Succeeded;

    /**
     * The remote payment this event references is irreversibly terminal
     * and can never later settle successfully — never a retryable,
     * intermediate, or otherwise non-final failure signal. This is a hard
     * requirement, not a naming preference:
     * `PaymentEventProcessor::applyFailed()` settles this straight to
     * `PaymentAttemptStatus::Failed`, and `Failed::blocksNewAttempt()` is
     * `false` — the one status that lets a new PaymentAttempt be started
     * for the same Payment while this one still exists (see that case's
     * own docblock). Translating a merely-retryable/intermediate failure
     * as `Failed` would let a second attempt start while the first
     * provider payment could *still* go on to succeed — two live charge
     * paths for one Payment, and a double Wallet credit if both do.
     *
     * A provider's own translator (e.g.
     * `App\Payments\Stripe\StripeEventTranslator`) owns this distinction
     * for its own native vocabulary — e.g. Stripe's
     * `payment_intent.payment_failed` (a single failed *attempt* within a
     * PaymentIntent that the customer can still retry, which can still end
     * in `payment_intent.succeeded`) is deliberately `Informational`, not
     * `Failed`; only `payment_intent.canceled` (Stripe's own irreversible
     * terminal state for a PaymentIntent) maps here. See "Terminal Failed
     * vs. a retryable attempt-level failure" in docs/wallet/integrations.md.
     */
    case Failed;
    case Refunded;

    /**
     * A non-terminal, informational-only signal (e.g. Stripe's
     * `payment_intent.payment_failed` — a single failed attempt within a
     * PaymentIntent that can still later succeed). Never mutates state,
     * never queued for replay.
     */
    case Informational;

    /**
     * A native event type this provider's translator doesn't recognize at
     * all (Stripe sends whatever the webhook endpoint is configured for,
     * which may be broader than the four types this domain acts on).
     * Logged and otherwise ignored — never queued for replay.
     */
    case Unrecognized;
}
