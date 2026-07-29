<?php

use App\Domain\Wallet\WalletTransactionReference;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletTransactionService;

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->wallet = $this->store->wallets()->first();
    $this->order = Order::factory()->forStore($this->store)->amount('100.00')->create();

    // What StripePaymentService::createPaymentIntentForOrder does when the
    // PaymentIntent is created, reproduced here without hitting Stripe's API.
    app(WalletTransactionService::class)->record(
        wallet: $this->wallet,
        categorySlug: 'sale',
        amount: $this->order->amount,
        reference: new WalletTransactionReference('stripe', 'pi_test_123'),
        options: [
            'status' => 'pending',
            'referenceable' => $this->order,
        ],
    );
});

it('confirms the wallet transaction and marks the order paid on payment_intent.succeeded', function () {
    $response = postStripeWebhook(
        stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123')
    );

    $response->assertOk();

    $transaction = StoreWalletTransaction::where('external_reference', 'pi_test_123')->firstOrFail();

    expect($transaction->status->slug)
        ->toBe('completed')
        ->and($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($this->order->fresh()->status->slug)
        ->toBe('paid');
});

it('is idempotent when payment_intent.succeeded is delivered twice', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))->assertOk();

    $response = postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(1);
});

it('marks the transaction and the order failed on payment_intent.payment_failed', function () {
    $response = postStripeWebhook(
        stripePaymentIntentEvent(
            'payment_intent.payment_failed',
            'pi_test_123',
            ['last_payment_error' => ['message' => 'Your card was declined.']],
        )
    );

    $response->assertOk();

    $transaction = StoreWalletTransaction::where('external_reference', 'pi_test_123')->firstOrFail();

    expect($transaction->status->slug)
        ->toBe('failed')
        ->and($transaction->description)
        ->toContain('Your card was declined.')
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->order->fresh()->status->slug)
        ->toBe('failed');
});

it('is idempotent when payment_intent.payment_failed is delivered twice', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.payment_failed', 'pi_test_123'))->assertOk();

    $response = postStripeWebhook(stripePaymentIntentEvent('payment_intent.payment_failed', 'pi_test_123'));

    $response->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'pi_test_123')->firstOrFail()->status->slug)
        ->toBe('failed');
});

it('reverses the wallet transaction and marks the order refunded on charge.refunded', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))->assertOk();

    $response = postStripeWebhook(stripeChargeRefundedEvent('ch_test_123', 'pi_test_123'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(2)
        ->and($this->order->fresh()->status->slug)
        ->toBe('refunded');
});

it('is idempotent when charge.refunded is delivered twice', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))->assertOk();
    postStripeWebhook(stripeChargeRefundedEvent('ch_test_123', 'pi_test_123'))->assertOk();

    $response = postStripeWebhook(stripeChargeRefundedEvent('ch_test_123', 'pi_test_123'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(2);
});

it('does nothing when the payment intent reference is unknown', function () {
    $response = postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_unknown_reference'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->order->fresh()->status->slug)
        ->toBe('pending');
});

it('rejects a webhook with an invalid signature', function () {
    $payload = json_encode(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'));

    $response = test()->call(
        method: 'POST',
        uri: route('stripe.webhook'),
        server: ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=invalidsignature'],
        content: $payload,
    );

    $response->assertStatus(400);

    expect(StoreWalletTransaction::where('external_reference', 'pi_test_123')->firstOrFail()->status->slug)
        ->toBe('pending');
});
