<?php

use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use Stripe\ApiRequestor;
use Tests\Fakes\FakeStripeHttpClient;

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

it('creates an order and a stripe payment intent for a store', function () {
    $store = Store::factory()->create();

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_console_test_123',
        'object' => 'payment_intent',
        'amount' => 2500,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_console_test_123_secret',
        'metadata' => [],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    $this->artisan('app:stripe-test-order', [
        'store_id' => $store->id,
        'amount' => '25.00',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('pi_console_test_123');

    $order = Order::where('store_id', $store->id)->firstOrFail();

    expect($order->amount)
        ->toBe('25.00')
        ->and($order->status->slug)
        ->toBe('pending');

    $transaction = StoreWalletTransaction::where('external_reference', 'pi_console_test_123')->firstOrFail();

    expect($transaction->referenceable_id)
        ->toBe($order->id)
        ->and($transaction->status->slug)
        ->toBe('pending');
});

it('fails gracefully when the store does not exist', function () {
    $this->artisan('app:stripe-test-order', ['store_id' => 999999])
        ->assertExitCode(1)
        ->expectsOutputToContain('not found');
});

it('refuses to run in production', function () {
    app()['env'] = 'production';

    $store = Store::factory()->create();

    $this->artisan('app:stripe-test-order', ['store_id' => $store->id])
        ->assertExitCode(1);

    app()['env'] = 'testing';
});
