<?php

namespace App\Payments\EasyPay;

use App\Domain\Payments\Contracts\ProviderEventTranslator;
use App\Domain\Payments\DTOs\ProviderEventOutcome;
use App\Domain\Payments\Enums\ProviderEventType;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Translates a verified EasyPayNotification into the payments domain's
 * provider-neutral vocabulary — the only class (alongside
 * EasyPayPaymentProvider) allowed to know EasyPay's own status vocabulary
 * and resource shapes. See App\Domain\Payments\Services\PaymentEventProcessor
 * and docs/wallet/integrations.md.
 *
 * Refunds are deliberately NOT handled here. EasyPay's own docs don't
 * publish a refund resource/notification shape (confirmed absent from
 * docs.easypay.pt at the time of writing), and a Wallet reversal must never
 * be triggered from a guessed field layout — see
 * EasyPayWebhookController::SUPPORTED_TYPES, which never routes a refund
 * notification here in the first place. Add refund support in a dedicated
 * follow-up once the real resource shape has been verified against
 * EasyPay's sandbox.
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

        $payment = $nativeEvent->resource;
        $status = $payment['status'] ?? null;

        return match ($status) {
            'success' => new ProviderEventOutcome(
                provider: 'easypay',
                eventId: $nativeEvent->notificationId,
                eventType: $nativeEvent->type,
                type: ProviderEventType::Succeeded,
                providerReference: (string) $payment['id'],
                replayPayload: $this->minimalPayload($nativeEvent),
            ),
            'failed' => new ProviderEventOutcome(
                provider: 'easypay',
                eventId: $nativeEvent->notificationId,
                eventType: $nativeEvent->type,
                type: ProviderEventType::Failed,
                providerReference: (string) $payment['id'],
                failureReason: $payment['messages'][0] ?? null,
                replayPayload: $this->minimalPayload($nativeEvent),
            ),
            'pending', 'waiting', 'delayed' => tap(
                new ProviderEventOutcome(
                    provider: 'easypay',
                    eventId: $nativeEvent->notificationId,
                    eventType: $nativeEvent->type,
                    type: ProviderEventType::Informational,
                    providerReference: (string) $payment['id'],
                ),
                fn () => Log::info("EasyPay payment {$payment['id']} still resolving (status: {$status}).")
            ),
            // Includes 'refunded' — refunds are not supported by this
            // integration (see class docblock); a payment resource
            // reporting that status is logged and otherwise ignored, never
            // guessed at as a reversal.
            default => tap(
                new ProviderEventOutcome(
                    provider: 'easypay',
                    eventId: $nativeEvent->notificationId,
                    eventType: $nativeEvent->type,
                    type: ProviderEventType::Unrecognized,
                    providerReference: isset($payment['id']) ? (string) $payment['id'] : null,
                ),
                fn () => Log::info("Unhandled EasyPay payment status: {$status}")
            ),
        };
    }

    public function reconstructFromReplayPayload(array $payload): mixed
    {
        return new EasyPayNotification(
            notificationId: $payload['notification_id'],
            type: $payload['type'],
            resource: $payload['resource'],
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
                'messages' => $resource['messages'] ?? null,
            ],
        ];
    }
}
