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
