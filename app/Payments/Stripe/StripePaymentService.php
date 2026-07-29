<?php

namespace App\Payments\Stripe;

use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\Order;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class StripePaymentService
{
    private StripeClient $client;

    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletTransactionService $walletTransactionService,
    ) {
        $this->client = new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Create a Stripe PaymentIntent for an order and record the matching
     * pending wallet transaction up front, referenced by the PaymentIntent id.
     * The webhook only ever needs to confirm/fail/reverse that reference —
     * it never records a transaction from scratch.
     */
    public function createPaymentIntentForOrder(Order $order): PaymentIntent
    {
        $currencyCode = $order->currency->code;

        $paymentIntent = $this->client->paymentIntents->create([
            'amount' => (int) bcmul($order->amount, '100', 0),
            'currency' => strtolower($currencyCode),
            'metadata' => [
                'order_id' => (string) $order->id,
            ],
        ]);

        $wallet = $this->walletService->getOrCreateWallet($order->store, $currencyCode);

        $this->walletTransactionService->record(
            wallet: $wallet,
            categorySlug: 'sale',
            amount: $order->amount,
            reference: new WalletTransactionReference('stripe', $paymentIntent->id),
            options: [
                'status' => 'pending',
                'referenceable' => $order,
                'source' => TransactionSource::Api,
                'description' => "Order #{$order->id}",
            ],
        );

        return $paymentIntent;
    }
}
