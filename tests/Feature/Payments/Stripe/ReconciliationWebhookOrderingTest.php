<?php

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Services\PaymentEventProcessor;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use Illuminate\Support\Facades\Artisan;
use Stripe\ApiRequestor;
use Tests\Fakes\FakeStripeHttpClient;

/**
 * Proves the fix for the documented crash window (docs/wallet/integrations.md,
 * "Recovering orphaned payment attempts"): a provider creates a remote
 * payment, the process dies before any local claim/Wallet transaction
 * exists, and a terminal webhook (succeeded/canceled/a fully-refunded
 * charge) can arrive in any order relative to
 * ReconcileOrphanedPaymentAttempts recovering the orphan. Every letter
 * below starts from the exact same orphaned state (Stripe has a
 * PaymentIntent, `payment_attempts` has a `pending` row, nothing else
 * exists yet) — the only thing that varies is the order events/reconciliation
 * arrive in. None of them may end with a known terminal payment permanently
 * stuck `pending`, credit/reverse a balance twice, or touch `updated_at` on
 * a duplicate delivery.
 */
afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

function orderedOrphanedAttempt(Order $order): PaymentAttempt
{
    $payment = Payment::firstOrCreate(['order_id' => $order->id]);

    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'stripe',
        'method' => 'card',
        'idempotency_key' => "payment-{$payment->id}-attempt-seed",
        'status' => PaymentAttemptStatus::Pending,
    ]);

    $payment->update(['current_payment_attempt_id' => $attempt->id]);
    $attempt->forceFill(['created_at' => now()->subMinutes(10)])->save();

    return $attempt->fresh();
}

function reconcileWithStripePaymentIntent(string $paymentIntentId, Order $order, int $amountMinorUnits = 4250): void
{
    ApiRequestor::setHttpClient(new FakeStripeHttpClient([
        'id' => $paymentIntentId,
        'object' => 'payment_intent',
        'amount' => $amountMinorUnits,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => "{$paymentIntentId}_secret",
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    Artisan::call('app:reconcile-orphaned-payment-attempts');
}

// --- A: reconciliation -> succeeded => paid -----------------------------

it('A: reconciliation then succeeded converges to paid', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    orderedOrphanedAttempt($order);

    reconcileWithStripePaymentIntent('pi_a', $order);

    // Reconciliation alone must never settle anything.
    expect(StoreWalletTransaction::where('external_reference', 'pi_a')->firstOrFail()->status->slug)
        ->toBe('pending');

    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_a', ['amount' => 4250]))
        ->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'pi_a')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($order->fresh()->status->slug)
        ->toBe('paid');
});

// --- B: succeeded -> reconciliation => paid ------------------------------

it('B: succeeded before reconciliation is queued, then converges to paid once reconciliation recovers the claim', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    orderedOrphanedAttempt($order);

    // No local claim exists yet — the processor has nothing to settle, so
    // it queues the event instead of dropping it.
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_b', ['amount' => 4250]))
        ->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'pi_b')->exists())->toBeFalse()
        ->and(PaymentProviderEvent::where('provider_reference', 'pi_b')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Pending);

    reconcileWithStripePaymentIntent('pi_b', $order);

    // Recovering the claim must itself replay the queued event —
    // this is the exact gap that used to leave the payment stuck pending.
    expect(StoreWalletTransaction::where('external_reference', 'pi_b')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($order->fresh()->status->slug)
        ->toBe('paid')
        ->and(PaymentProviderEvent::where('provider_reference', 'pi_b')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Applied);
});

// --- C: succeeded -> succeeded duplicate -> reconciliation => paid once -

it('C: a duplicate succeeded delivery while still orphaned queues only one event, and reconciliation still converges to paid exactly once', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    orderedOrphanedAttempt($order);

    // The same event (same Stripe event id) redelivered before any claim
    // exists — storeUnmatchedEvent() must recognize it as the same row,
    // not queue it twice.
    $event = stripePaymentIntentEvent('payment_intent.succeeded', 'pi_c', ['amount' => 4250]);
    postStripeWebhook($event)->assertOk();
    postStripeWebhook($event)->assertOk();

    expect(PaymentProviderEvent::where('provider_reference', 'pi_c')->count())->toBe(1);

    reconcileWithStripePaymentIntent('pi_c', $order);

    expect(StoreWalletTransaction::where('external_reference', 'pi_c')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_c')->count())
        ->toBe(1)
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($order->fresh()->status->slug)
        ->toBe('paid');
});

// --- D: reconciliation -> succeeded -> succeeded duplicate => paid once -

it('D: reconciliation then succeeded then a duplicate succeeded delivery settles exactly once, without re-touching the order', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    orderedOrphanedAttempt($order);

    reconcileWithStripePaymentIntent('pi_d', $order);

    $event = stripePaymentIntentEvent('payment_intent.succeeded', 'pi_d', ['amount' => 4250]);
    postStripeWebhook($event)->assertOk();

    $updatedAt = $order->fresh()->updated_at;
    $this->travelTo(now()->addMinute());

    postStripeWebhook($event)->assertOk();

    expect($order->fresh()->updated_at)
        ->toEqual($updatedAt)
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_d')->count())
        ->toBe(1);
});

// --- E: canceled -> reconciliation => failed -----------------------------

it('E: canceled before reconciliation is queued, then converges to failed once reconciliation recovers the claim', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    orderedOrphanedAttempt($order);

    postStripeWebhook(stripePaymentIntentEvent(
        'payment_intent.canceled',
        'pi_e',
        ['amount' => 4250, 'last_payment_error' => ['message' => 'Your card was declined.']],
    ))->assertOk();

    expect(PaymentProviderEvent::where('provider_reference', 'pi_e')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Pending);

    reconcileWithStripePaymentIntent('pi_e', $order);

    expect(StoreWalletTransaction::where('external_reference', 'pi_e')->firstOrFail()->status->slug)
        ->toBe('failed')
        ->and($order->fresh()->status->slug)
        ->toBe('failed')
        ->and(PaymentProviderEvent::where('provider_reference', 'pi_e')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Applied);
});

// --- F: reconciliation -> canceled => failed -----------------------------

it('F: reconciliation then canceled converges to failed', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    orderedOrphanedAttempt($order);

    reconcileWithStripePaymentIntent('pi_f', $order);

    postStripeWebhook(stripePaymentIntentEvent(
        'payment_intent.canceled',
        'pi_f',
        ['amount' => 4250, 'last_payment_error' => ['message' => 'Your card was declined.']],
    ))->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'pi_f')->firstOrFail()->status->slug)
        ->toBe('failed')
        ->and($order->fresh()->status->slug)
        ->toBe('failed');
});

// --- G: payment_failed -> reconciliation => stays pending ----------------

it('G: a non-terminal payment_failed before reconciliation leaves the payment pending, not queued', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    orderedOrphanedAttempt($order);

    // payment_intent.payment_failed is never terminal (I10) — no local
    // transaction exists yet for it to even act on, and it must never be
    // queued for replay.
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.payment_failed', 'pi_g'))->assertOk();

    expect(PaymentProviderEvent::where('provider_reference', 'pi_g')->exists())->toBeFalse();

    reconcileWithStripePaymentIntent('pi_g', $order);

    expect(StoreWalletTransaction::where('external_reference', 'pi_g')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($order->fresh()->status->slug)
        ->toBe('pending');
});

// --- H: payment_failed -> reconciliation -> succeeded => paid -----------

it('H: a retryable failed attempt before reconciliation does not block a later succeeded from converging to paid', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    orderedOrphanedAttempt($order);

    postStripeWebhook(stripePaymentIntentEvent('payment_intent.payment_failed', 'pi_h'))->assertOk();

    reconcileWithStripePaymentIntent('pi_h', $order);

    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_h', ['amount' => 4250]))
        ->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'pi_h')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($order->fresh()->status->slug)
        ->toBe('paid');
});

// --- I: stored terminal event -> reconciliation -> replay -> repeat replay = exactly-once

it('I: a stored terminal event survives reconciliation and repeated manual replay as an exactly-once financial outcome', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    orderedOrphanedAttempt($order);

    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_i', ['amount' => 4250]))
        ->assertOk();

    reconcileWithStripePaymentIntent('pi_i', $order);

    expect($wallet->fresh()->balance)->toBe('42.50');

    // Reconciliation itself already replayed the event once (marking it
    // Applied); explicitly re-running replay must be a total no-op — it's
    // no longer `pending`, so it can never be picked up again.
    app(PaymentEventProcessor::class)->replayUnmatchedEvents('stripe', 'pi_i');
    app(PaymentEventProcessor::class)->replayUnmatchedEvents('stripe', 'pi_i');

    expect($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_i')->count())
        ->toBe(1)
        ->and($order->fresh()->status->slug)
        ->toBe('paid');
});

// --- J: refund ordering => a single reversal -----------------------------

it('J: a charge.refunded that arrives before payment_intent.succeeded confirms is queued and reversed exactly once, automatically, once succeeded lands', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    orderedOrphanedAttempt($order);

    // The claim/pending Wallet transaction now exists, but the sale isn't
    // `completed` yet — a refund can never reverse a non-completed sale
    // (I7/I12), so this must be queued rather than silently dropped.
    reconcileWithStripePaymentIntent('pi_j', $order);

    postStripeWebhook(stripeChargeRefundedEvent('ch_j', 'pi_j', ['amount_refunded' => 4250]))->assertOk();

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_j')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and(PaymentProviderEvent::where('provider_reference', 'pi_j')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Pending);

    // Confirming the sale must itself replay the queued refund — the
    // reversal happens automatically, without a second refund webhook.
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_j', ['amount' => 4250]))
        ->assertOk();

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2)
        ->and($order->fresh()->status->slug)
        ->toBe('refunded')
        ->and(PaymentProviderEvent::where('provider_reference', 'pi_j')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Applied);

    // A later redelivery of the same refund event must not reverse twice.
    postStripeWebhook(stripeChargeRefundedEvent('ch_j', 'pi_j', ['amount_refunded' => 4250]))->assertOk();

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);
});
