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
use Tests\Fakes\FakeStripeHttpClient;

/**
 * Proves the final hardening invariant: `PaymentAttemptStatus::Failed` (and
 * the `ProviderEventType::Failed` that produces it) must mean the remote
 * payment is irreversibly terminal — never a retryable/intermediate
 * failure — because `Failed::blocksNewAttempt() === false` is what lets a
 * second PaymentAttempt be created for the same Payment. See "Terminal
 * Failed vs. a retryable attempt-level failure" in
 * docs/wallet/integrations.md and PaymentAttemptStatus::Failed's own
 * docblock.
 *
 * Unlike StripeWebhookControllerTest (which drives the Wallet directly and
 * never creates a real PaymentAttempt), these tests go through the full
 * stack — PaymentService::startAttempt() to create a real, claimed
 * PaymentAttempt, then a real signed Stripe webhook through
 * StripeEventTranslator — so the PaymentAttempt's own status/gating
 * behavior is actually exercised end-to-end, not just the Wallet
 * transaction.
 */
afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

function startClaimedStripeAttempt(Order $order, string $paymentIntentId, int $amountMinorUnits = 4250): PaymentAttempt
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

    return app(PaymentService::class)->startAttempt($order, 'stripe', 'card');
}

it('payment_intent.payment_failed leaves the PaymentAttempt non-terminal, still blocking a new attempt, and compatible with a later succeeded', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = startClaimedStripeAttempt($order, 'pi_retryable_failure');

    expect($attempt->status)->toBe(PaymentAttemptStatus::Claimed);

    postStripeWebhook(
        stripePaymentIntentEvent(
            'payment_intent.payment_failed',
            'pi_retryable_failure',
            ['amount' => 4250, 'last_payment_error' => ['message' => 'Your card was declined.']],
        )
    )->assertOk();

    // Must NOT have become Failed — Stripe still lets the customer retry
    // this same PaymentIntent, which can still end in `succeeded`.
    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Claimed)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->status)
        ->toBe(PaymentStatus::Pending);

    // Must NOT have unblocked a new attempt: Claimed still blocksNewAttempt(),
    // so a repeat call resumes this same attempt instead of creating another.
    $resumed = app(PaymentService::class)->startAttempt($order, 'stripe', 'card');

    expect($resumed->id)
        ->toBe($attempt->id)
        ->and(PaymentAttempt::where('payment_id', $attempt->payment_id)->count())
        ->toBe(1);

    // Still compatible with a later succeeded on the same PaymentIntent.
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_retryable_failure', ['amount' => 4250]))
        ->assertOk();

    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($order->fresh()->status->slug)
        ->toBe('paid');
});

it('payment_intent.canceled terminally fails the exact PaymentAttempt, leaves the Payment retryable, and permits a new attempt', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = startClaimedStripeAttempt($order, 'pi_terminal_cancel');

    postStripeWebhook(
        stripePaymentIntentEvent(
            'payment_intent.canceled',
            'pi_terminal_cancel',
            ['amount' => 4250, 'last_payment_error' => ['message' => 'Your card was declined.']],
        )
    )->assertOk();

    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Failed)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->status)
        ->toBe(PaymentStatus::Pending)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_terminal_cancel')->firstOrFail()->status->slug)
        ->toBe('failed');

    // Failed::blocksNewAttempt() === false — a retry is now permitted, and
    // must create a genuinely new attempt/PaymentIntent, not resume the
    // canceled one.
    $second = startClaimedStripeAttempt($order, 'pi_after_cancel_retry');

    expect($second->id)
        ->not->toBe($attempt->id)
        ->and($second->provider_reference)
        ->toBe('pi_after_cancel_retry')
        ->and(PaymentAttempt::where('payment_id', $attempt->payment_id)->count())
        ->toBe(2)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->current_payment_attempt_id)
        ->toBe($second->id);

    // The retry can still succeed on its own merits, independent of the
    // first, canceled attempt.
    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_after_cancel_retry', ['amount' => 4250]))
        ->assertOk();

    expect($second->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and($attempt->fresh()->status)
        // The first, canceled attempt is untouched by the retry's success —
        // exact-attempt targeting (see PaymentEventProcessorExactAttemptSettlementTest)
        // holds here too.
        ->toBe(PaymentAttemptStatus::Failed)
        ->and($order->fresh()->status->slug)
        ->toBe('paid');
});
