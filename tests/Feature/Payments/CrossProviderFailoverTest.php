<?php

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Services\PaymentService;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use Stripe\ApiRequestor;
use Tests\Fakes\FakeEasyPayHttpClient;
use Tests\Fakes\FakeStripeHttpClient;

/**
 * The architectural proof this whole branch exists for: does the
 * provider-agnostic core (App\Domain\Payments\Services\PaymentService,
 * PaymentEventProcessor, the payment_attempts gate) actually generalize
 * across two *real* providers, or did Stripe secretly leak an assumption
 * into it? Tests/Feature/Domain/Payments/PaymentServiceGatingTest already
 * proves the gate itself against throwaway fakes; this proves the same
 * story end-to-end against the two real adapters this codebase ships:
 * Stripe terminally fails, EasyPay succeeds on retry (and the reverse) —
 * one Payment, two historical PaymentAttempts, the failed one never
 * mutated by the other provider's webhook, the Wallet crediting exactly
 * once. See docs/wallet/integrations.md.
 */
afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

it('Stripe terminally fails, EasyPay succeeds on retry: one Payment, two historical attempts, exactly one Wallet credit', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    // Attempt A: stripe/card, claimed then terminally canceled.
    ApiRequestor::setHttpClient(new FakeStripeHttpClient([
        'id' => 'pi_failover_a',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_failover_a_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    $attemptA = app(PaymentService::class)->startAttempt($order, 'stripe', 'card');

    expect($attemptA->status)->toBe(PaymentAttemptStatus::Claimed);

    postStripeWebhook(stripePaymentIntentEvent(
        'payment_intent.canceled',
        'pi_failover_a',
        ['amount' => 4250, 'last_payment_error' => ['message' => 'Your card was declined.']],
    ))->assertOk();

    expect($attemptA->fresh()->status)
        ->toBe(PaymentAttemptStatus::Failed)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->status)
        ->toBe(PaymentStatus::Pending);

    // Attempt B: easypay/mbway — a genuinely different provider, permitted
    // now that A is terminally Failed.
    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_failover_b', (string) $order->id, ['status' => 'success'])))->install();

    $attemptB = app(PaymentService::class)->startAttempt($order, 'easypay', 'mbway');

    expect($attemptB->id)
        ->not->toBe($attemptA->id)
        ->and($attemptB->provider)
        ->toBe('easypay')
        ->and($attemptB->provider_reference)
        ->toBe('ep_failover_b')
        ->and(PaymentAttempt::where('payment_id', $attemptA->payment_id)->count())
        ->toBe(2)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->current_payment_attempt_id)
        ->toBe($attemptB->id);

    (new FakeEasyPayHttpClient(responsesById: [
        'ep_failover_b' => easyPayPaymentBody('ep_failover_b', (string) $order->id, ['status' => 'success']),
    ]))->install();

    postEasyPayWebhook(easyPayNotification('capture', 'ep_failover_b'))->assertOk();

    // --- The full set of guarantees the master prompt demands ---

    expect(Payment::where('order_id', $order->id)->count())
        ->toBe(1)
        ->and(PaymentAttempt::where('payment_id', $attemptA->payment_id)->count())
        ->toBe(2)
        // A remains Failed — untouched by B's success.
        ->and($attemptA->fresh()->status)
        ->toBe(PaymentAttemptStatus::Failed)
        // B is Succeeded.
        ->and($attemptB->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        // Payment is Paid.
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->status)
        ->toBe(PaymentStatus::Paid)
        // Order is paid.
        ->and($order->fresh()->status->slug)
        ->toBe('paid')
        // Wallet received exactly one completed payment.
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($wallet->transactions()->count())
        ->toBe(2) // A's pending sale (still pending, never completed) + B's completed sale.
        ->and(StoreWalletTransaction::where('external_reference', 'pi_failover_a')->firstOrFail()->status->slug)
        ->toBe('failed')
        ->and(StoreWalletTransaction::where('external_reference', 'ep_failover_b')->firstOrFail()->status->slug)
        ->toBe('completed');

    // No provider can mutate the other's attempt: a duplicate Stripe
    // cancellation redelivery must still only ever touch A.
    postStripeWebhook(stripePaymentIntentEvent(
        'payment_intent.canceled',
        'pi_failover_a',
        ['amount' => 4250],
    ))->assertOk();

    expect($attemptB->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and($wallet->fresh()->balance)
        ->toBe('42.50');
});

it('EasyPay terminally fails, Stripe succeeds on retry: one Payment, two historical attempts, exactly one Wallet credit (reverse ordering)', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    // Attempt A: easypay/multibanco, claimed then terminally failed.
    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_failover_rev_a', (string) $order->id, ['method' => 'mb'])))->install();

    $attemptA = app(PaymentService::class)->startAttempt($order, 'easypay', 'multibanco');

    expect($attemptA->status)->toBe(PaymentAttemptStatus::Claimed);

    (new FakeEasyPayHttpClient(responsesById: [
        'ep_failover_rev_a' => easyPayPaymentBody('ep_failover_rev_a', (string) $order->id, [
            'status' => 'failed',
            'messages' => ['Multibanco reference expired unpaid.'],
        ]),
    ]))->install();

    postEasyPayWebhook(easyPayNotification('capture', 'ep_failover_rev_a'))->assertOk();

    expect($attemptA->fresh()->status)
        ->toBe(PaymentAttemptStatus::Failed)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->status)
        ->toBe(PaymentStatus::Pending);

    // Attempt B: stripe/card — permitted now that A is terminally Failed.
    ApiRequestor::setHttpClient(new FakeStripeHttpClient([
        'id' => 'pi_failover_rev_b',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_failover_rev_b_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    $attemptB = app(PaymentService::class)->startAttempt($order, 'stripe', 'card');

    expect($attemptB->id)
        ->not->toBe($attemptA->id)
        ->and($attemptB->provider)
        ->toBe('stripe')
        ->and($attemptB->provider_reference)
        ->toBe('pi_failover_rev_b')
        ->and(PaymentAttempt::where('payment_id', $attemptA->payment_id)->count())
        ->toBe(2)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->current_payment_attempt_id)
        ->toBe($attemptB->id);

    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_failover_rev_b', ['amount' => 4250]))
        ->assertOk();

    expect(Payment::where('order_id', $order->id)->count())
        ->toBe(1)
        ->and(PaymentAttempt::where('payment_id', $attemptA->payment_id)->count())
        ->toBe(2)
        ->and($attemptA->fresh()->status)
        ->toBe(PaymentAttemptStatus::Failed)
        ->and($attemptB->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->status)
        ->toBe(PaymentStatus::Paid)
        ->and($order->fresh()->status->slug)
        ->toBe('paid')
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and(StoreWalletTransaction::where('external_reference', 'ep_failover_rev_a')->firstOrFail()->status->slug)
        ->toBe('failed')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_failover_rev_b')->firstOrFail()->status->slug)
        ->toBe('completed');

    // No provider can mutate the other's attempt: a duplicate EasyPay
    // failure redelivery must still only ever touch A.
    (new FakeEasyPayHttpClient(responsesById: [
        'ep_failover_rev_a' => easyPayPaymentBody('ep_failover_rev_a', (string) $order->id, ['status' => 'failed']),
    ]))->install();
    postEasyPayWebhook(easyPayNotification('capture', 'ep_failover_rev_a'))->assertOk();

    expect($attemptB->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and($wallet->fresh()->balance)
        ->toBe('42.50');
});
