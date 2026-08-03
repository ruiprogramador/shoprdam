<?php

use App\Domain\Wallet\WalletTransactionReference;
use App\Models\Order;
use App\Models\OrderStatus;
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

it('does not re-touch the order on a duplicate payment_intent.succeeded delivery', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))->assertOk();

    $updatedAt = $this->order->fresh()->updated_at;

    $this->travelTo(now()->addMinute());

    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))->assertOk();

    expect($this->order->fresh()->updated_at)->toEqual($updatedAt);
});

it('rolls back the Wallet confirmation when marking the order paid fails, keeping the pair atomic', function () {
    OrderStatus::where('slug', 'paid')->delete();

    $response = postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'));

    // markOrder() throws ModelNotFoundException (via OrderStatus::bySlugOrFail),
    // which Laravel's default handler renders as 404 — the exact status code
    // isn't the point here; what matters is that the exception propagated
    // instead of being swallowed, and that it rolled back the Wallet mutation.
    $response->assertStatus(404);

    expect(StoreWalletTransaction::where('external_reference', 'pi_test_123')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->order->fresh()->status->slug)
        ->toBe('pending');
});

it('marks the transaction and the order failed on payment_intent.canceled', function () {
    $response = postStripeWebhook(
        stripePaymentIntentEvent(
            'payment_intent.canceled',
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

it('is idempotent when payment_intent.canceled is delivered twice', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.canceled', 'pi_test_123'))->assertOk();

    $response = postStripeWebhook(stripePaymentIntentEvent('payment_intent.canceled', 'pi_test_123'));

    $response->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'pi_test_123')->firstOrFail()->status->slug)
        ->toBe('failed');
});

it('does not re-touch the order on a duplicate payment_intent.canceled delivery', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.canceled', 'pi_test_123'))->assertOk();

    $updatedAt = $this->order->fresh()->updated_at;

    $this->travelTo(now()->addMinute());

    postStripeWebhook(stripePaymentIntentEvent('payment_intent.canceled', 'pi_test_123'))->assertOk();

    expect($this->order->fresh()->updated_at)->toEqual($updatedAt);
});

it('does nothing when payment_intent.canceled references an unknown payment intent', function () {
    $response = postStripeWebhook(stripePaymentIntentEvent('payment_intent.canceled', 'pi_unknown_reference'));

    $response->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'pi_test_123')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($this->order->fresh()->status->slug)
        ->toBe('pending');
});

it('never touches the Wallet or the Order on payment_intent.payment_failed, regardless of the embedded status', function (string $paymentIntentStatus) {
    $response = postStripeWebhook(
        stripePaymentIntentEvent(
            'payment_intent.payment_failed',
            'pi_test_123',
            ['status' => $paymentIntentStatus, 'last_payment_error' => ['message' => 'Your card was declined.']],
        )
    );

    $response->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'pi_test_123')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->order->fresh()->status->slug)
        ->toBe('pending');
})->with(['requires_payment_method', 'requires_action', 'processing', 'canceled']);

it('confirms the transaction on a later payment_intent.succeeded after a retryable failed attempt', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.payment_failed', 'pi_test_123'))->assertOk();

    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_test_123')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and($this->order->fresh()->status->slug)
        ->toBe('paid');
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

it('does not re-touch the order on a duplicate charge.refunded delivery', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))->assertOk();
    postStripeWebhook(stripeChargeRefundedEvent('ch_test_123', 'pi_test_123'))->assertOk();

    $updatedAt = $this->order->fresh()->updated_at;

    $this->travelTo(now()->addMinute());

    postStripeWebhook(stripeChargeRefundedEvent('ch_test_123', 'pi_test_123'))->assertOk();

    expect($this->order->fresh()->updated_at)->toEqual($updatedAt);
});

it('skips a partial refund instead of reversing the full transaction amount', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))->assertOk();

    $response = postStripeWebhook(
        stripeChargeRefundedEvent('ch_test_123', 'pi_test_123', ['amount_refunded' => 5000])
    );

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(1)
        ->and($this->order->fresh()->status->slug)
        ->toBe('paid');
});

it('skips a refund whose amount_refunded exceeds the original transaction amount', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))->assertOk();

    $response = postStripeWebhook(
        stripeChargeRefundedEvent('ch_test_123', 'pi_test_123', ['amount_refunded' => 15000])
    );

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(1)
        ->and($this->order->fresh()->status->slug)
        ->toBe('paid');
});

it('never treats a reversal transaction as an original when its own reference is looked up as a PaymentIntent id', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))->assertOk();
    postStripeWebhook(stripeChargeRefundedEvent('ch_test_123', 'pi_test_123'))->assertOk();

    // The reversal created above now sits at external_reference = 'ch_test_123'.
    // Misusing that as if it were a PaymentIntent id must not match it via
    // findOriginalTransactionByPaymentIntentId() — it has a related_transaction_id
    // (it's a reversal), so it must never be treated as an original sale.
    $response = postStripeWebhook(stripeChargeRefundedEvent('ch_test_456', 'ch_test_123'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(2);
});

it('reverses once the full amount when charge.refunded reports the cumulative total across two partial refunds', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))->assertOk();

    postStripeWebhook(
        stripeChargeRefundedEvent('ch_test_123', 'pi_test_123', ['amount_refunded' => 4000])
    )->assertOk();

    $response = postStripeWebhook(
        stripeChargeRefundedEvent('ch_test_123', 'pi_test_123', ['amount_refunded' => 10000])
    );

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(2)
        ->and($this->order->fresh()->status->slug)
        ->toBe('refunded');
});

it('does nothing when charge.refunded arrives before the payment intent has been confirmed', function () {
    $response = postStripeWebhook(stripeChargeRefundedEvent('ch_test_123', 'pi_test_123'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_test_123')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($this->order->fresh()->status->slug)
        ->toBe('pending');
});

it('does nothing when charge.refunded arrives for a transaction that was already marked failed', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.canceled', 'pi_test_123'))->assertOk();

    $response = postStripeWebhook(stripeChargeRefundedEvent('ch_test_123', 'pi_test_123'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_test_123')->firstOrFail()->status->slug)
        ->toBe('failed')
        ->and($this->order->fresh()->status->slug)
        ->toBe('failed');
});

it('does nothing when charge.refunded references a PaymentIntent id with no matching transaction at all', function () {
    $response = postStripeWebhook(stripeChargeRefundedEvent('ch_test_123', 'pi_completely_unknown'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(1);
});

it('does nothing when the payment intent reference is unknown', function () {
    $response = postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_unknown_reference'));

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->order->fresh()->status->slug)
        ->toBe('pending');
});

it('accepts payment_intent.payment_failed for an unknown payment intent without error', function () {
    $response = postStripeWebhook(
        stripePaymentIntentEvent('payment_intent.payment_failed', 'pi_unknown_reference')
    );

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_test_123')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($this->order->fresh()->status->slug)
        ->toBe('pending');
});

it('does nothing when charge.refunded has no payment intent reference', function () {
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_test_123'))->assertOk();

    $response = postStripeWebhook(
        stripeChargeRefundedEvent('ch_test_123', 'pi_test_123', ['payment_intent' => null])
    );

    $response->assertOk();

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(1)
        ->and($this->order->fresh()->status->slug)
        ->toBe('paid');
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
