<?php

namespace App\Payments\Stripe;

use App\Domain\Payments\Contracts\ProviderEventTranslator;
use App\Domain\Payments\DTOs\ProviderEventOutcome;
use App\Domain\Payments\Enums\ProviderEventType;
use Illuminate\Support\Facades\Log;
use LogicException;
use Stripe\Event;

/**
 * Translates a `Stripe\Event` into the payments domain's provider-neutral
 * vocabulary — the only class (alongside StripePaymentProvider) allowed to
 * know Stripe's own event types and object shapes. See
 * App\Domain\Payments\Services\PaymentEventProcessor and
 * docs/wallet/integrations.md.
 *
 * An earlier version of this integration made the mistake of trying to
 * detect "the PaymentIntent is really done" by checking `status ===
 * 'canceled'` on the PaymentIntent embedded in a `payment_intent.payment_failed`
 * event. That never actually happens in practice — a failed attempt's
 * status is `requires_payment_method`, not `canceled` — so that check was
 * silently dead code. `payment_intent.canceled` is what carries that
 * signal for real; `payment_intent.payment_failed` stays purely
 * Informational here.
 */
class StripeEventTranslator implements ProviderEventTranslator
{
    public function translate(mixed $nativeEvent): ProviderEventOutcome
    {
        if (! $nativeEvent instanceof Event) {
            throw new LogicException('StripeEventTranslator can only translate Stripe\Event instances.');
        }

        return match ($nativeEvent->type) {
            'payment_intent.succeeded' => $this->translatePaymentIntentSucceeded($nativeEvent),
            'payment_intent.payment_failed' => $this->translatePaymentIntentPaymentFailed($nativeEvent),
            'payment_intent.canceled' => $this->translatePaymentIntentCanceled($nativeEvent),
            'charge.refunded' => $this->translateChargeRefunded($nativeEvent),
            default => new ProviderEventOutcome(
                provider: 'stripe',
                eventId: $nativeEvent->id,
                eventType: $nativeEvent->type,
                type: ProviderEventType::Unrecognized,
                providerReference: null,
            ),
        };
    }

    public function reconstructFromReplayPayload(array $payload): mixed
    {
        return Event::constructFrom($payload);
    }

    private function translatePaymentIntentSucceeded(Event $event): ProviderEventOutcome
    {
        $paymentIntent = $event->data->object;

        return new ProviderEventOutcome(
            provider: 'stripe',
            eventId: $event->id,
            eventType: $event->type,
            type: ProviderEventType::Succeeded,
            providerReference: $paymentIntent->id,
            replayPayload: $this->minimalPaymentIntentPayload($event, $paymentIntent),
        );
    }

    /**
     * A failed payment *attempt* does not kill the PaymentIntent: Stripe
     * lets the customer retry with a different payment method on the same
     * PaymentIntent id, which can still end in `payment_intent.succeeded`.
     * Purely informational — never mutates state, never queued for replay.
     */
    private function translatePaymentIntentPaymentFailed(Event $event): ProviderEventOutcome
    {
        $paymentIntent = $event->data->object;

        Log::info(
            "Payment attempt failed for Stripe PaymentIntent {$paymentIntent->id} ".
            "(status: {$paymentIntent->status}): ".
            ($paymentIntent->last_payment_error?->message ?? 'no error message provided').
            '. The attempt is left in place in case of a retry.'
        );

        return new ProviderEventOutcome(
            provider: 'stripe',
            eventId: $event->id,
            eventType: $event->type,
            type: ProviderEventType::Informational,
            providerReference: $paymentIntent->id,
        );
    }

    private function translatePaymentIntentCanceled(Event $event): ProviderEventOutcome
    {
        $paymentIntent = $event->data->object;

        return new ProviderEventOutcome(
            provider: 'stripe',
            eventId: $event->id,
            eventType: $event->type,
            type: ProviderEventType::Failed,
            providerReference: $paymentIntent->id,
            failureReason: $paymentIntent->last_payment_error?->message,
            replayPayload: $this->minimalPaymentIntentPayload($event, $paymentIntent),
        );
    }

    private function translateChargeRefunded(Event $event): ProviderEventOutcome
    {
        $charge = $event->data->object;

        if (! is_string($charge->payment_intent) || $charge->payment_intent === '') {
            Log::warning("Ignoring charge.refunded for Stripe Charge {$charge->id}: no PaymentIntent reference.");

            // Nothing to key a replay on, and nothing this event could
            // ever resolve — permanently done, not stored.
            return new ProviderEventOutcome(
                provider: 'stripe',
                eventId: $event->id,
                eventType: $event->type,
                type: ProviderEventType::Informational,
                providerReference: null,
            );
        }

        return new ProviderEventOutcome(
            provider: 'stripe',
            eventId: $event->id,
            eventType: $event->type,
            type: ProviderEventType::Refunded,
            providerReference: $charge->payment_intent,
            reversalReference: $charge->id,
            refundedAmountMinorUnits: (int) $charge->amount_refunded,
            replayPayload: [
                'id' => $event->id,
                'object' => 'event',
                'type' => $event->type,
                'data' => ['object' => [
                    'id' => $charge->id,
                    'object' => 'charge',
                    'payment_intent' => $charge->payment_intent,
                    'amount_refunded' => $charge->amount_refunded,
                    'refunded' => $charge->refunded,
                ]],
            ],
        );
    }

    /**
     * Builds the minimal event structure replay needs to reconstruct a
     * `Stripe\Event` (via `Event::constructFrom()` in
     * reconstructFromReplayPayload()) that translate() can process
     * identically to a live one — allow-listing only the fields the
     * translations above actually read. Deliberately excludes everything
     * else Stripe sends, in particular a PaymentIntent's `client_secret`:
     * this is what's persisted to the database, so nothing sensitive or
     * unnecessary belongs in it (see the `payment_provider_events` migration).
     */
    private function minimalPaymentIntentPayload(Event $event, object $paymentIntent): array
    {
        return [
            'id' => $event->id,
            'object' => 'event',
            'type' => $event->type,
            'data' => ['object' => [
                'id' => $paymentIntent->id,
                'object' => 'payment_intent',
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'status' => $paymentIntent->status,
                'metadata' => $paymentIntent->metadata?->toArray() ?? [],
                'last_payment_error' => $paymentIntent->last_payment_error?->toArray(),
            ]],
        ];
    }
}
