<?php

use App\Domain\Payments\DTOs\ProviderEventOutcome;
use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\Enums\FailureClass;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ProviderEventType;
use App\Domain\Payments\Exceptions\PaymentAlreadyResolvedException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\PaymentProviderManager;
use App\Domain\Payments\Services\PaymentEventProcessor;
use App\Domain\Payments\Services\PaymentService;
use App\Models\Order;
use App\Models\Store;
use Tests\Fakes\FakeTestPaymentProvider;

/**
 * Proves PaymentService's gated multi-attempt invariant purely against the
 * generic domain — no Stripe SDK, no Stripe fake — using a throwaway
 * FakeTestPaymentProvider registered per test. If any of these needed
 * Stripe to pass, that would itself be a sign the domain leaked a
 * provider-specific assumption.
 *
 * The gate: a Payment may have many PaymentAttempts over time (retry with
 * a different provider/method), but at most one may be non-terminal at
 * once — see PaymentAttemptStatus::blocksNewAttempt() and
 * PaymentService::startAttempt(). This is what makes "Stripe fails, try
 * MB WAY, try PayPal" possible without opening a window where two
 * attempts could both succeed and double-credit the Wallet.
 */
function registerFakeProvider(string $name, ProviderPaymentResult $result): FakeTestPaymentProvider
{
    $provider = new FakeTestPaymentProvider($name, $result);
    app(PaymentProviderManager::class)->extend($name, fn () => $provider);

    return $provider;
}

function registerFailingFakeProvider(string $name, Throwable $throws, FailureClass $failureClass): FakeTestPaymentProvider
{
    $provider = new FakeTestPaymentProvider($name, null, $throws, $failureClass);
    app(PaymentProviderManager::class)->extend($name, fn () => $provider);

    return $provider;
}

it('starting a second attempt while one is already in flight resumes it instead of creating a new one', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('20.00')->create();

    registerFakeProvider('fake_a', new ProviderPaymentResult(
        providerReference: 'fa_1',
        amountMinorUnits: 2000,
        currency: 'eur',
        providerStatus: 'requires_action',
        correlationId: (string) $order->id,
    ));

    $service = app(PaymentService::class);

    $first = $service->startAttempt($order, 'fake_a', 'wallet');
    $second = $service->startAttempt($order, 'fake_a', 'wallet');

    expect($second->id)
        ->toBe($first->id)
        ->and(PaymentAttempt::where('payment_id', $first->payment_id)->count())
        ->toBe(1);
});

it('a new attempt with a different provider is allowed once the previous one has terminally failed', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('20.00')->create();

    registerFailingFakeProvider('fake_fail', new RuntimeException('declined'), FailureClass::NonRetryable);
    registerFakeProvider('fake_succeed', new ProviderPaymentResult(
        providerReference: 'fs_1',
        amountMinorUnits: 2000,
        currency: 'eur',
        providerStatus: 'succeeded',
        correlationId: (string) $order->id,
    ));

    $service = app(PaymentService::class);

    expect(fn () => $service->startAttempt($order, 'fake_fail', 'mbway'))
        ->toThrow(RuntimeException::class);

    $payment = Payment::where('order_id', $order->id)->firstOrFail();
    $failedAttemptId = $payment->current_payment_attempt_id;

    // The attempt stays `pending` after a synchronous failure — marking it
    // `failed` is App\Console\Commands\ReconcileOrphanedPaymentAttempts's
    // job (after exhausting retries / a non-retryable error) or a
    // provider's own terminal webhook, not PaymentService itself.
    // Simulating that here to reach the state a real retry-with-another-provider
    // flow would actually be in.
    PaymentAttempt::where('id', $failedAttemptId)->update(['status' => PaymentAttemptStatus::Failed]);

    $second = $service->startAttempt($order, 'fake_succeed', 'paypal');

    expect($second->id)
        ->not->toBe($failedAttemptId)
        ->and($second->provider)
        ->toBe('fake_succeed')
        ->and($second->method)
        ->toBe('paypal')
        ->and($second->provider_reference)
        ->toBe('fs_1')
        ->and(PaymentAttempt::where('payment_id', $payment->id)->count())
        ->toBe(2)
        ->and($payment->fresh()->current_payment_attempt_id)
        ->toBe($second->id);
});

it('a real webhook-driven attempt failure (PaymentEventProcessor::applyFailed) leaves the Payment pending so a retry with another provider still succeeds', function () {
    // Regression test: applyFailed() used to mark the whole Payment
    // `failed` (not just the PaymentAttempt), which — since
    // createDurableAttempt() refuses any new attempt once Payment.status
    // isn't `pending` — permanently blocked exactly the "Stripe declines,
    // retry with MB WAY" flow this domain exists to support. The test
    // above proves the *gate* is correct once an attempt is `failed`; this
    // one proves the real webhook path (not a manual DB update) actually
    // gets it there while leaving the Payment retryable.
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('20.00')->create();

    registerFakeProvider('fake_declines', new ProviderPaymentResult(
        providerReference: 'fd_1',
        amountMinorUnits: 2000,
        currency: 'eur',
        providerStatus: 'requires_action',
        correlationId: (string) $order->id,
    ));

    $service = app(PaymentService::class);
    $firstAttempt = $service->startAttempt($order, 'fake_declines', 'card');

    expect($firstAttempt->status)->toBe(PaymentAttemptStatus::Claimed);

    app(PaymentEventProcessor::class)->apply(new ProviderEventOutcome(
        provider: 'fake_declines',
        eventId: 'evt_declined',
        eventType: 'test.failed',
        type: ProviderEventType::Failed,
        providerReference: 'fd_1',
        failureReason: 'card_declined',
    ));

    $payment = Payment::where('order_id', $order->id)->firstOrFail();

    expect($firstAttempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Failed)
        ->and($payment->status)
        ->toBe(PaymentStatus::Pending);

    registerFakeProvider('fake_mbway', new ProviderPaymentResult(
        providerReference: 'fm_1',
        amountMinorUnits: 2000,
        currency: 'eur',
        providerStatus: 'succeeded',
        correlationId: (string) $order->id,
    ));

    $secondAttempt = $service->startAttempt($order, 'fake_mbway', 'mbway');

    expect($secondAttempt->id)
        ->not->toBe($firstAttempt->id)
        ->and($secondAttempt->provider)
        ->toBe('fake_mbway')
        ->and($secondAttempt->provider_reference)
        ->toBe('fm_1')
        ->and($payment->fresh()->current_payment_attempt_id)
        ->toBe($secondAttempt->id);
});

it('refuses to start a new attempt once the Payment is already resolved', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('20.00')->create();

    Payment::create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'fake_a', 'wallet'))
        ->toThrow(PaymentAlreadyResolvedException::class);
});

it('resumes a Payment whose current attempt already succeeded, independent of the Payment-level status guard', function () {
    // Defense in depth: PaymentAttemptStatus::blocksNewAttempt() must gate
    // this on its own even in a contrived state where the Payment itself
    // hasn't (yet, or for whatever reason) been marked `paid`.
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('20.00')->create();

    $payment = Payment::create(['order_id' => $order->id]);
    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'fake_a',
        'method' => 'wallet',
        'provider_reference' => 'fa_already_succeeded',
        'idempotency_key' => 'k',
        'status' => PaymentAttemptStatus::Succeeded,
    ]);
    $payment->update(['current_payment_attempt_id' => $attempt->id]);

    registerFakeProvider('fake_a', new ProviderPaymentResult(
        providerReference: 'fa_never_used',
        amountMinorUnits: 2000,
        currency: 'eur',
        providerStatus: 'succeeded',
        correlationId: (string) $order->id,
    ));

    $resumed = app(PaymentService::class)->startAttempt($order, 'fake_a', 'wallet');

    expect($resumed->id)
        ->toBe($attempt->id)
        ->and(PaymentAttempt::where('payment_id', $payment->id)->count())
        ->toBe(1);
});

it('a repeat call after a claimed attempt is idempotent and never calls the provider again', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('20.00')->create();

    $provider = registerFakeProvider('fake_a', new ProviderPaymentResult(
        providerReference: 'fa_idempotent',
        amountMinorUnits: 2000,
        currency: 'eur',
        providerStatus: 'requires_action',
        correlationId: (string) $order->id,
    ));

    $service = app(PaymentService::class);

    $first = $service->startAttempt($order, 'fake_a', 'wallet');
    $service->finalizeAttempt($first->fresh());

    expect($provider->calledForAttemptIds)->toHaveCount(1);
});
