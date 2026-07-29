<?php

use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Payments\Stripe\StripePaymentService;
use Stripe\ApiRequestor;
use Tests\Fakes\FakeStripeHttpClient;

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

it('creates a Stripe PaymentIntent and a matching pending wallet transaction for an order', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_fake_123',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_fake_123_secret_abc',
        'metadata' => ['order_id' => (string) $order->id],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    $paymentIntent = app(StripePaymentService::class)->createPaymentIntentForOrder($order);

    expect($paymentIntent->id)
        ->toBe('pi_fake_123')
        ->and($fakeClient->requests)
        ->toHaveCount(1)
        ->and($fakeClient->requests[0]['params']['amount'])
        ->toBe(4250)
        ->and($fakeClient->requests[0]['params']['currency'])
        ->toBe('eur')
        ->and($fakeClient->requests[0]['params']['metadata']['order_id'])
        ->toBe((string) $order->id);

    $transaction = StoreWalletTransaction::where('external_reference', 'pi_fake_123')->firstOrFail();

    expect($transaction->external_provider)
        ->toBe('stripe')
        ->and($transaction->amount)
        ->toBe('42.50')
        ->and($transaction->status->slug)
        ->toBe('pending')
        ->and($transaction->referenceable_type)
        ->toBe($order->getMorphClass())
        ->and($transaction->referenceable_id)
        ->toBe($order->id)
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});
