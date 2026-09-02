<?php

namespace App\Payments\EasyPay;

use App\Domain\Payments\Contracts\ProviderEventTranslator;
use App\Domain\Payments\DTOs\ProviderEventOutcome;
use App\Domain\Payments\Enums\ProviderEventType;
use App\Domain\Payments\MinorUnits;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Translates a verified EasyPayNotification into the payments domain's
 * provider-neutral vocabulary — the only class (alongside
 * EasyPayPaymentProvider) allowed to know EasyPay's own status vocabulary
 * and resource shapes. See App\Domain\Payments\Services\PaymentEventProcessor
 * and docs/wallet/integrations.md.
 *
 * EasyPay's single-payment status values (docs.easypay.pt): `success`,
 * `pending`, `waiting`, `delayed`, `failed`, `refunded`. Unlike Stripe's
 * PaymentIntent — where the *same* id can be retried with another payment
 * method after `payment_intent.payment_failed`, which is why that event is
 * deliberately Informational, not Failed — EasyPay's `POST /2.0/single`
 * (`type: sale`) mints a brand-new payment id per call, and its docs
 * describe no mechanism to resume a failed one under the same id. A
 * `failed` single payment is therefore this id's own terminal, irreversible
 * outcome: this domain's retry story is "PaymentService starts a genuinely
 * new attempt/id", exactly what Failed::blocksNewAttempt() === false exists
 * to allow — never "the same EasyPay payment resolves later". Do not copy
 * Stripe's payment_intent.payment_failed => Informational mapping here; the
 * two providers' lifecycles are genuinely different, not just named
 * differently. `pending`/`waiting`/`delayed` are EasyPay's own non-terminal,
 * still-resolving states (e.g. MB WAY awaiting the customer's in-app
 * approval, Multibanco awaiting the bank transfer) and stay Informational
 * for the same reason Stripe's in-flight states do.
 */
class EasyPayEventTranslator implements ProviderEventTranslator
{
    public function translate(mixed $nativeEvent): ProviderEventOutcome
    {
        if (! $nativeEvent instanceof EasyPayNotification) {
            throw new LogicException('EasyPayEventTranslator can only translate EasyPayNotification instances.');
        }

        return str_contains($nativeEvent->type, 'refund')
            ? $this->translateRefund($nativeEvent)
            : $this->translatePayment($nativeEvent);
    }

    public function reconstructFromReplayPayload(array $payload): mixed
    {
        return new EasyPayNotification(
            notificationId: $payload['notification_id'],
            type: $payload['type'],
            resource: $payload['resource'],
        );
    }

    private function translatePayment(EasyPayNotification $notification): ProviderEventOutcome
    {
        $payment = $notification->resource;
        $status = $payment['status'] ?? null;

        return match ($status) {
            'success' => new ProviderEventOutcome(
                provider: 'easypay',
                eventId: $notification->notificationId,
                eventType: $notification->type,
                type: ProviderEventType::Succeeded,
                providerReference: (string) $payment['id'],
                replayPayload: $this->minimalPayload($notification),
            ),
            'failed' => new ProviderEventOutcome(
                provider: 'easypay',
                eventId: $notification->notificationId,
                eventType: $notification->type,
                type: ProviderEventType::Failed,
                providerReference: (string) $payment['id'],
                failureReason: $payment['messages'][0] ?? null,
                replayPayload: $this->minimalPayload($notification),
            ),
            'pending', 'waiting', 'delayed' => tap(
                new ProviderEventOutcome(
                    provider: 'easypay',
                    eventId: $notification->notificationId,
                    eventType: $notification->type,
                    type: ProviderEventType::Informational,
                    providerReference: (string) $payment['id'],
                ),
                fn () => Log::info("EasyPay payment {$payment['id']} still resolving (status: {$status}).")
            ),
            // A payment resource reporting 'refunded' directly (rather than
            // via its own dedicated refund-type notification) is left
            // Informational and logged rather than guessed at — the
            // dedicated refund path (translateRefund()) is what carries a
            // verified refunded amount; inventing one here risks reversing
            // the wrong amount.
            default => tap(
                new ProviderEventOutcome(
                    provider: 'easypay',
                    eventId: $notification->notificationId,
                    eventType: $notification->type,
                    type: ProviderEventType::Unrecognized,
                    providerReference: isset($payment['id']) ? (string) $payment['id'] : null,
                ),
                fn () => Log::info("Unhandled EasyPay payment status: {$status}")
            ),
        };
    }

    /**
     * `$notification->resource` here is the verified refund object EasyPay's
     * `GET /2.0/refund/{id}` returns — see EasyPayClient::retrieveRefund()
     * for the documented-shape assumption this relies on.
     * `PaymentEventProcessor::applyRefunded()` already refuses to reverse
     * anything less than the full original amount, so a partial refund is
     * safely ignored generically — this translator doesn't need to special-case it.
     */
    private function translateRefund(EasyPayNotification $notification): ProviderEventOutcome
    {
        $refund = $notification->resource;

        if ($refund['status'] !== 'success' || ! isset($refund['payment_id'])) {
            return new ProviderEventOutcome(
                provider: 'easypay',
                eventId: $notification->notificationId,
                eventType: $notification->type,
                type: ProviderEventType::Informational,
                providerReference: isset($refund['payment_id']) ? (string) $refund['payment_id'] : null,
            );
        }

        return new ProviderEventOutcome(
            provider: 'easypay',
            eventId: $notification->notificationId,
            eventType: $notification->type,
            type: ProviderEventType::Refunded,
            providerReference: (string) $refund['payment_id'],
            reversalReference: (string) $refund['id'],
            refundedAmountMinorUnits: MinorUnits::fromDecimal((string) $refund['value']),
            replayPayload: $this->minimalPayload($notification),
        );
    }

    /**
     * Allow-listed, secret-free reconstruction stored in
     * `payment_provider_events.payload` — mirrors
     * StripeEventTranslator::minimalPaymentIntentPayload(). Nothing beyond
     * what translate() itself reads belongs here.
     */
    private function minimalPayload(EasyPayNotification $notification): array
    {
        $resource = $notification->resource;

        return [
            'notification_id' => $notification->notificationId,
            'type' => $notification->type,
            'resource' => [
                'id' => $resource['id'] ?? null,
                'status' => $resource['status'] ?? null,
                'value' => $resource['value'] ?? null,
                'currency' => $resource['currency'] ?? null,
                'key' => $resource['key'] ?? null,
                'payment_id' => $resource['payment_id'] ?? null,
                'messages' => $resource['messages'] ?? null,
            ],
        ];
    }
}
