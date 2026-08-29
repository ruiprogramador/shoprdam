<?php

namespace App\Payments\Stripe;

use App\Domain\Payments\Services\PaymentEventProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * Stripe's own webhook endpoint. Verifies the signature and decodes the
 * payload — the only things this controller does with Stripe's SDK —
 * then hands off to StripeEventTranslator to cross the translation
 * boundary into the provider-neutral payments domain, which
 * PaymentEventProcessor applies. A second provider (PayPal, EasyPay, ...)
 * gets its own controller here, doing its own signature verification and
 * decoding: authentication, payload shape, and native vocabulary differ
 * enough between providers that a single generic webhook endpoint would
 * either force them all to look like Stripe or hide those differences
 * behind conditionals — see docs/wallet/integrations.md.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeEventTranslator $translator,
        private readonly PaymentEventProcessor $eventProcessor,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                config('services.stripe.webhook_secret'),
            );
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            report($e);

            return response('Invalid payload or signature.', 400);
        }

        $this->eventProcessor->apply($this->translator->translate($event));

        return response('OK', 200);
    }
}
