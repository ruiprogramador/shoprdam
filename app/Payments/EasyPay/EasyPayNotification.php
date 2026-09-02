<?php

namespace App\Payments\EasyPay;

/**
 * The "native event" EasyPayEventTranslator translates — EasyPay's
 * counterpart to a `Stripe\Event`. Deliberately not just the raw webhook
 * body: EasyPay publishes no signature/HMAC verification (see
 * docs.easypay.pt/docs/guides/webhooks); the documented, only way to trust a
 * notification is to call back the API using its `id` and treat *that*
 * response as authoritative. `$notificationId`/`$type` come from the
 * untrusted webhook payload (used only to decide what got notified and
 * which endpoint to verify against); `$resource` is always the verified,
 * trusted response EasyPayClient returned for that callback — translate()
 * never reads a field from the original webhook body itself.
 */
final readonly class EasyPayNotification
{
    public function __construct(
        public string $notificationId,
        public string $type,
        public array $resource,
    ) {}
}
