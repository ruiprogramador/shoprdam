<?php

use App\Domain\Wallet\WalletTransactionReference;
use App\Models\Store;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Testing\TestResponse;

function recordTransaction(
    string $category,
    string $amount,
    ?WalletTransactionReference $reference = null,
    array $options = []
): StoreWalletTransaction {
    return test()->service->record(test()->wallet, $category, $amount, $reference, $options);
}

function recordPendingTransaction(string $category, string $amount, array $options = []): StoreWalletTransaction
{
    return recordTransaction($category, $amount, options: ['status' => 'pending', ...$options]);
}

function walletService(): WalletTransactionService
{
    return app(WalletTransactionService::class);
}

/**
 * @return array{0: Store, 1: StoreWallet}
 */
function createStoreWithWallet(): array
{
    $store = Store::factory()->create();

    return [$store, $store->wallets()->first()];
}

function expectWalletUnchanged(StoreWallet $before): void
{
    $after = $before->fresh();

    expect($after->balance)
        ->toBe($before->balance)
        ->and($after->last_transaction_at)
        ->toEqual($before->last_transaction_at);
}

/**
 * Post a raw, Stripe-signed webhook payload to the stripe.webhook route,
 * the same way Stripe's servers would (no session, no CSRF token, a
 * Stripe-Signature header computed from the shared webhook secret).
 */
function postStripeWebhook(array $event, ?string $secret = null): TestResponse
{
    $secret ??= config('services.stripe.webhook_secret');
    $payload = json_encode($event);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return test()->call(
        method: 'POST',
        uri: route('stripe.webhook'),
        server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
        content: $payload,
    );
}

function stripePaymentIntentEvent(string $type, string $paymentIntentId, array $overrides = []): array
{
    return [
        'id' => 'evt_'.str()->random(16),
        'object' => 'event',
        'type' => $type,
        'data' => [
            'object' => array_merge([
                'id' => $paymentIntentId,
                'object' => 'payment_intent',
                'amount' => 10000,
                'currency' => 'eur',
                'status' => match ($type) {
                    'payment_intent.succeeded' => 'succeeded',
                    'payment_intent.canceled' => 'canceled',
                    default => 'requires_payment_method',
                },
                'metadata' => [],
                'last_payment_error' => null,
            ], $overrides),
        ],
    ];
}

function stripeChargeRefundedEvent(string $chargeId, string $paymentIntentId, array $overrides = []): array
{
    return [
        'id' => 'evt_'.str()->random(16),
        'object' => 'event',
        'type' => 'charge.refunded',
        'data' => [
            'object' => array_merge([
                'id' => $chargeId,
                'object' => 'charge',
                'payment_intent' => $paymentIntentId,
                'amount_refunded' => 10000,
                'refunded' => true,
            ], $overrides),
        ],
    ];
}

/**
 * Post a raw EasyPay notification body to the easypay.webhook route. Unlike
 * Stripe, EasyPay publishes no signature to compute — its documented
 * security model is the receiving controller calling back the API using the
 * notification's own `id` (see EasyPayWebhookController), not verifying the
 * delivery itself.
 */
function postEasyPayWebhook(array $notification): Illuminate\Testing\TestResponse
{
    return test()->postJson(route('easypay.webhook'), $notification);
}

/** The generic {id, key, type, status, ...} shape every EasyPay notification carries. */
function easyPayNotification(string $type, string $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'key' => 'merchant-key-'.str()->random(8),
        'type' => $type,
        'status' => 'success',
        'messages' => ['ok'],
        'date' => now()->toDateTimeString(),
    ], $overrides);
}

/** The shape EasyPayClient::retrieveSinglePayment()/createSinglePayment() return for a single payment resource. */
function easyPayPaymentBody(string $id, string $orderId, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'key' => $orderId,
        'status' => 'success',
        'method' => 'mbway',
        'value' => '42.50',
        'currency' => 'EUR',
    ], $overrides);
}

/** The shape EasyPayClient::retrieveRefund() returns for a refund resource. */
function easyPayRefundBody(string $id, string $paymentId, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'payment_id' => $paymentId,
        'status' => 'success',
        'value' => '100.00',
    ], $overrides);
}
