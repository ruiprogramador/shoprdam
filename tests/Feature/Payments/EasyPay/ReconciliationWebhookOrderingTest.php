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
use Tests\Fakes\FakeEasyPayHttpClient;

/**
 * EasyPay's counterpart to Tests\Feature\Payments\Stripe\ReconciliationWebhookOrderingTest
 * — proves the same orphaned-attempt crash window (docs/wallet/integrations.md,
 * "Recovering orphaned payment attempts") closes identically for a second,
 * genuinely different provider. Every letter starts from the exact same
 * orphaned state (EasyPay has a payment, `payment_attempts` has a `pending`
 * row, nothing else exists yet).
 */
function easyPayOrderedOrphanedAttempt(Order $order): PaymentAttempt
{
    $payment = Payment::firstOrCreate(['order_id' => $order->id]);

    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'easypay',
        'method' => 'mbway',
        'idempotency_key' => "payment-{$payment->id}-attempt-seed",
        'status' => PaymentAttemptStatus::Pending,
    ]);

    $payment->update(['current_payment_attempt_id' => $attempt->id]);
    $attempt->forceFill(['created_at' => now()->subMinutes(10)])->save();

    return $attempt->fresh();
}

function reconcileWithEasyPayPayment(string $paymentId, Order $order): void
{
    (new FakeEasyPayHttpClient(easyPayPaymentBody($paymentId, (string) $order->id, ['status' => 'success'])))->install();

    Artisan::call('app:reconcile-orphaned-payment-attempts');
}

function verifyEasyPayNotificationAs(string $paymentId, array $resource): void
{
    (new FakeEasyPayHttpClient(responsesById: [$paymentId => $resource]))->install();
}

// --- A: reconciliation -> success => paid --------------------------------

it('A: reconciliation then success converges to paid', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    easyPayOrderedOrphanedAttempt($order);

    reconcileWithEasyPayPayment('ep_a', $order);

    expect(StoreWalletTransaction::where('external_reference', 'ep_a')->firstOrFail()->status->slug)
        ->toBe('pending');

    verifyEasyPayNotificationAs('ep_a', easyPayPaymentBody('ep_a', (string) $order->id, ['status' => 'success']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_a'))->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'ep_a')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($order->fresh()->status->slug)
        ->toBe('paid');
});

// --- B: success -> reconciliation => paid --------------------------------

it('B: success before reconciliation is queued, then converges to paid once reconciliation recovers the claim', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    easyPayOrderedOrphanedAttempt($order);

    verifyEasyPayNotificationAs('ep_b', easyPayPaymentBody('ep_b', (string) $order->id, ['status' => 'success']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_b'))->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'ep_b')->exists())->toBeFalse()
        ->and(PaymentProviderEvent::where('provider_reference', 'ep_b')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Pending);

    reconcileWithEasyPayPayment('ep_b', $order);

    expect(StoreWalletTransaction::where('external_reference', 'ep_b')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($order->fresh()->status->slug)
        ->toBe('paid')
        ->and(PaymentProviderEvent::where('provider_reference', 'ep_b')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Applied);
});

// --- C: duplicate success while orphaned -> reconciliation => paid once -

it('C: a duplicate success delivery while still orphaned queues only one event, and reconciliation still converges to paid exactly once', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    easyPayOrderedOrphanedAttempt($order);

    verifyEasyPayNotificationAs('ep_c', easyPayPaymentBody('ep_c', (string) $order->id, ['status' => 'success']));
    $event = easyPayNotification('capture', 'ep_c');
    postEasyPayWebhook($event)->assertOk();
    postEasyPayWebhook($event)->assertOk();

    expect(PaymentProviderEvent::where('provider_reference', 'ep_c')->count())->toBe(1);

    reconcileWithEasyPayPayment('ep_c', $order);

    expect(StoreWalletTransaction::where('external_reference', 'ep_c')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and(StoreWalletTransaction::where('external_reference', 'ep_c')->count())
        ->toBe(1)
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($order->fresh()->status->slug)
        ->toBe('paid');
});

// --- D: reconciliation -> success -> duplicate success => paid once -----

it('D: reconciliation then success then a duplicate success delivery settles exactly once, without re-touching the order', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    easyPayOrderedOrphanedAttempt($order);

    reconcileWithEasyPayPayment('ep_d', $order);

    verifyEasyPayNotificationAs('ep_d', easyPayPaymentBody('ep_d', (string) $order->id, ['status' => 'success']));
    $event = easyPayNotification('capture', 'ep_d');
    postEasyPayWebhook($event)->assertOk();

    $updatedAt = $order->fresh()->updated_at;
    $this->travelTo(now()->addMinute());

    postEasyPayWebhook($event)->assertOk();

    expect($order->fresh()->updated_at)
        ->toEqual($updatedAt)
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and(StoreWalletTransaction::where('external_reference', 'ep_d')->count())
        ->toBe(1);
});

// --- E: failed -> reconciliation => failed -------------------------------

it('E: a failed notification before reconciliation is queued, then converges to failed once reconciliation recovers the claim', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    easyPayOrderedOrphanedAttempt($order);

    verifyEasyPayNotificationAs('ep_e', easyPayPaymentBody('ep_e', (string) $order->id, ['status' => 'failed']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_e'))->assertOk();

    expect(PaymentProviderEvent::where('provider_reference', 'ep_e')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Pending);

    reconcileWithEasyPayPayment('ep_e', $order);

    expect(StoreWalletTransaction::where('external_reference', 'ep_e')->firstOrFail()->status->slug)
        ->toBe('failed')
        ->and($order->fresh()->status->slug)
        ->toBe('failed')
        ->and(PaymentProviderEvent::where('provider_reference', 'ep_e')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Applied);
});

// --- F: reconciliation -> failed => failed -------------------------------

it('F: reconciliation then failed converges to failed', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    easyPayOrderedOrphanedAttempt($order);

    reconcileWithEasyPayPayment('ep_f', $order);

    verifyEasyPayNotificationAs('ep_f', easyPayPaymentBody('ep_f', (string) $order->id, ['status' => 'failed']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_f'))->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'ep_f')->firstOrFail()->status->slug)
        ->toBe('failed')
        ->and($order->fresh()->status->slug)
        ->toBe('failed');
});

// --- G: still-resolving -> reconciliation => stays pending ---------------

it('G: a non-terminal waiting status before reconciliation leaves the payment pending, not queued', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    easyPayOrderedOrphanedAttempt($order);

    verifyEasyPayNotificationAs('ep_g', easyPayPaymentBody('ep_g', (string) $order->id, ['status' => 'waiting']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_g'))->assertOk();

    expect(PaymentProviderEvent::where('provider_reference', 'ep_g')->exists())->toBeFalse();

    reconcileWithEasyPayPayment('ep_g', $order);

    expect(StoreWalletTransaction::where('external_reference', 'ep_g')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($order->fresh()->status->slug)
        ->toBe('pending');
});

// --- H: still-resolving -> reconciliation -> success => paid -------------

it('H: a still-resolving status before reconciliation does not block a later success from converging to paid', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    easyPayOrderedOrphanedAttempt($order);

    verifyEasyPayNotificationAs('ep_h', easyPayPaymentBody('ep_h', (string) $order->id, ['status' => 'waiting']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_h'))->assertOk();

    reconcileWithEasyPayPayment('ep_h', $order);

    verifyEasyPayNotificationAs('ep_h', easyPayPaymentBody('ep_h', (string) $order->id, ['status' => 'success']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_h'))->assertOk();

    expect(StoreWalletTransaction::where('external_reference', 'ep_h')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($order->fresh()->status->slug)
        ->toBe('paid');
});

// --- I: stored terminal event -> reconciliation -> replay -> repeat = exactly-once

it('I: a stored terminal event survives reconciliation and repeated manual replay as an exactly-once financial outcome', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    easyPayOrderedOrphanedAttempt($order);

    verifyEasyPayNotificationAs('ep_i', easyPayPaymentBody('ep_i', (string) $order->id, ['status' => 'success']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_i'))->assertOk();

    reconcileWithEasyPayPayment('ep_i', $order);

    expect($wallet->fresh()->balance)->toBe('42.50');

    app(PaymentEventProcessor::class)->replayUnmatchedEvents('easypay', 'ep_i');
    app(PaymentEventProcessor::class)->replayUnmatchedEvents('easypay', 'ep_i');

    expect($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and(StoreWalletTransaction::where('external_reference', 'ep_i')->count())
        ->toBe(1)
        ->and($order->fresh()->status->slug)
        ->toBe('paid');
});

// --- J: refund ordering => a single reversal -----------------------------

it('J: a refund that arrives before the payment confirms is queued and reversed exactly once, automatically, once success lands', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    easyPayOrderedOrphanedAttempt($order);

    // The claim/pending Wallet transaction now exists, but the sale isn't
    // `completed` yet — a refund can never reverse a non-completed sale.
    reconcileWithEasyPayPayment('ep_j', $order);

    (new FakeEasyPayHttpClient(responsesById: [
        'ref_j' => ['id' => 'ref_j', 'payment_id' => 'ep_j', 'status' => 'success', 'value' => '42.50'],
    ]))->install();
    postEasyPayWebhook(easyPayNotification('refund', 'ref_j'))->assertOk();

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and(StoreWalletTransaction::where('external_reference', 'ep_j')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and(PaymentProviderEvent::where('provider_reference', 'ep_j')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Pending);

    // Confirming the sale must itself replay the queued refund.
    verifyEasyPayNotificationAs('ep_j', easyPayPaymentBody('ep_j', (string) $order->id, ['status' => 'success']));
    postEasyPayWebhook(easyPayNotification('capture', 'ep_j'))->assertOk();

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2)
        ->and($order->fresh()->status->slug)
        ->toBe('refunded')
        ->and(PaymentProviderEvent::where('provider_reference', 'ep_j')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Applied);

    // A later redelivery of the same refund event must not reverse twice.
    (new FakeEasyPayHttpClient(responsesById: [
        'ref_j' => ['id' => 'ref_j', 'payment_id' => 'ep_j', 'status' => 'success', 'value' => '42.50'],
    ]))->install();
    postEasyPayWebhook(easyPayNotification('refund', 'ref_j'))->assertOk();

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);
});
