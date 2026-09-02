<?php

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Services\PaymentService;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use Tests\Fakes\FakeEasyPayHttpClient;

/**
 * EasyPay's counterpart to Tests\Feature\Payments\Stripe\TerminalFailedInvariantTest
 * — same invariant (PaymentAttemptStatus::Failed must mean irreversibly
 * terminal), same full-stack proof style (a real PaymentService::startAttempt(),
 * then a real webhook through EasyPayEventTranslator), but exercising
 * EasyPay's own lifecycle: unlike Stripe's PaymentIntent — where the same id
 * stays retryable after payment_intent.payment_failed — an EasyPay `failed`
 * single payment is that id's own final outcome (see
 * EasyPayEventTranslator's docblock), so the "still non-terminal, still
 * blocking, still compatible with a later success" half of Stripe's test has
 * no EasyPay equivalent: pending/waiting/delayed play that role instead.
 */
function startClaimedEasyPayAttempt(Order $order, string $paymentId, string $method = 'mbway'): PaymentAttempt
{
    (new FakeEasyPayHttpClient(easyPayPaymentBody($paymentId, (string) $order->id, ['status' => 'success'])))->install();

    return app(PaymentService::class)->startAttempt($order, 'easypay', $method);
}

it('a non-terminal status (pending/waiting/delayed) leaves the PaymentAttempt non-terminal, still blocking a new attempt, and compatible with a later success', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = startClaimedEasyPayAttempt($order, 'ep_still_resolving');

    expect($attempt->status)->toBe(PaymentAttemptStatus::Claimed);

    (new FakeEasyPayHttpClient(responsesById: [
        'ep_still_resolving' => easyPayPaymentBody('ep_still_resolving', (string) $order->id, ['status' => 'waiting']),
    ]))->install();

    postEasyPayWebhook(easyPayNotification('capture', 'ep_still_resolving'))->assertOk();

    // Must NOT have become Failed — the payment can still resolve.
    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Claimed)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->status)
        ->toBe(PaymentStatus::Pending);

    // Must NOT have unblocked a new attempt.
    $resumed = app(PaymentService::class)->startAttempt($order, 'easypay', 'mbway');

    expect($resumed->id)
        ->toBe($attempt->id)
        ->and(PaymentAttempt::where('payment_id', $attempt->payment_id)->count())
        ->toBe(1);

    // Still compatible with a later success on the same payment id.
    (new FakeEasyPayHttpClient(responsesById: [
        'ep_still_resolving' => easyPayPaymentBody('ep_still_resolving', (string) $order->id, ['status' => 'success']),
    ]))->install();

    postEasyPayWebhook(easyPayNotification('capture', 'ep_still_resolving'))->assertOk();

    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($order->fresh()->status->slug)
        ->toBe('paid');
});

it('a failed status terminally fails the exact PaymentAttempt, leaves the Payment retryable, and permits a new attempt', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = startClaimedEasyPayAttempt($order, 'ep_terminal_fail');

    (new FakeEasyPayHttpClient(responsesById: [
        'ep_terminal_fail' => easyPayPaymentBody('ep_terminal_fail', (string) $order->id, [
            'status' => 'failed',
            'messages' => ['Payment declined by the issuer.'],
        ]),
    ]))->install();

    postEasyPayWebhook(easyPayNotification('capture', 'ep_terminal_fail'))->assertOk();

    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Failed)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->status)
        ->toBe(PaymentStatus::Pending)
        ->and(StoreWalletTransaction::where('external_reference', 'ep_terminal_fail')->firstOrFail()->status->slug)
        ->toBe('failed');

    // Failed::blocksNewAttempt() === false — a retry is now permitted, and
    // must create a genuinely new attempt/payment, not resume the failed one.
    $second = startClaimedEasyPayAttempt($order, 'ep_after_fail_retry');

    expect($second->id)
        ->not->toBe($attempt->id)
        ->and($second->provider_reference)
        ->toBe('ep_after_fail_retry')
        ->and(PaymentAttempt::where('payment_id', $attempt->payment_id)->count())
        ->toBe(2)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->current_payment_attempt_id)
        ->toBe($second->id);

    (new FakeEasyPayHttpClient(responsesById: [
        'ep_after_fail_retry' => easyPayPaymentBody('ep_after_fail_retry', (string) $order->id, ['status' => 'success']),
    ]))->install();

    postEasyPayWebhook(easyPayNotification('capture', 'ep_after_fail_retry'))->assertOk();

    expect($second->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Failed)
        ->and($order->fresh()->status->slug)
        ->toBe('paid');
});
