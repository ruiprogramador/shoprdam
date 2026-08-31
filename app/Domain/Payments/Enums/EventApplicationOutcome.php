<?php

namespace App\Domain\Payments\Enums;

/**
 * What happened when PaymentEventProcessor::apply() processed one
 * translated provider event — distinct from the HTTP response a provider's
 * webhook controller returns (always 200 once the signature verifies,
 * regardless of this outcome, since anything unresolved is durably queued
 * instead of asking the provider to keep redelivering it).
 */
enum EventApplicationOutcome
{
    /**
     * Settled the attempt/Payment/Order, or determined the event will
     * never need to be looked at again (a duplicate delivery of an
     * already-terminal event, a partial refund permanently below the full
     * amount). If this event came from the provider-events inbox, it's
     * marked `applied` and never replayed again.
     */
    case Applied;

    /**
     * Could not be resolved yet — no local attempt claims this provider
     * reference, or (a refund) one does but its Wallet transaction isn't
     * `completed` yet. Persisted to `payment_provider_events` (or left
     * there, if this call came from a replay) for
     * PaymentEventProcessor::replayUnmatchedEvents() to retry once the
     * precondition is met.
     */
    case Unresolved;

    /** A translated event type this processor doesn't act on. */
    case Ignored;
}
