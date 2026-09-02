<?php

namespace App\Payments\EasyPay;

use App\Domain\Payments\Services\PaymentEventProcessor;
use App\Http\Controllers\Controller;
use App\Payments\EasyPay\Exceptions\EasyPayConnectionException;
use App\Payments\EasyPay\Exceptions\EasyPayRequestException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * EasyPay's own webhook endpoint. EasyPay publishes no signature/HMAC
 * verification for notifications (unlike Stripe's Stripe-Signature header)
 * — its documented security model is instead "call back the API using the
 * notification's own `id` and trust only that response"
 * (docs.easypay.pt/docs/guides/webhooks). This controller does exactly
 * that: the incoming payload is used only to read `id`/`type` (never
 * `status` — that's the whole point), then EasyPayClient re-fetches the
 * canonical resource, which is what actually gets translated. An attacker
 * posting a forged body can only ever cause a real, current lookup of
 * whatever id they name — never inject a fabricated status, since nothing
 * past this point ever reads the request body again.
 *
 * Same shape as StripeWebhookController otherwise: verify → parse →
 * translator → PaymentEventProcessor, never touching the Wallet directly.
 */
class EasyPayWebhookController extends Controller
{
    /**
     * The only EasyPay notification `type` this integration acts on —
     * confirmed against docs.easypay.pt's own example payload for a
     * completed `type: sale` single payment (the only creation shape
     * EasyPayPaymentProvider ever sends). Deliberately an explicit
     * allow-list, not a substring/heuristic match: EasyPay also sends
     * notifications for refunds, chargebacks, voids, and subscription/
     * frequent-payment events this integration does not support (see
     * EasyPayEventTranslator's docblock) — any of those must fail closed
     * (ignored, no API callback, no financial side effect) rather than be
     * guessed at from the type string's shape.
     */
    private const SUPPORTED_TYPES = ['capture'];

    private EasyPayClient $client;

    public function __construct(
        private readonly EasyPayEventTranslator $translator,
        private readonly PaymentEventProcessor $eventProcessor,
    ) {
        // Built directly from config, same as EasyPayPaymentProvider — its
        // constructor takes plain config values, not resolvable classes, so
        // the container can't autowire it without a dedicated binding this
        // one extra caller doesn't justify (see PaymentServiceProvider's own
        // docblock: the only wiring that belongs there is per-provider
        // driver registration).
        $this->client = new EasyPayClient(
            config('services.easypay.base_url'),
            config('services.easypay.account_id'),
            config('services.easypay.api_key'),
        );
    }

    public function __invoke(Request $request): Response
    {
        $id = $request->input('id');
        $type = $request->input('type');

        if (! is_string($id) || $id === '' || ! is_string($type) || $type === '') {
            return response('Invalid payload.', 400);
        }

        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            Log::info("Ignoring unsupported EasyPay notification type: {$type}");

            return response('OK', 200);
        }

        try {
            $resource = $this->client->retrieveSinglePayment($id);
        } catch (EasyPayRequestException|EasyPayConnectionException $e) {
            report($e);

            return response('Could not verify notification against the EasyPay API.', 400);
        }

        $notification = new EasyPayNotification($id, $type, $resource);

        $this->eventProcessor->apply($this->translator->translate($notification));

        return response('OK', 200);
    }
}
