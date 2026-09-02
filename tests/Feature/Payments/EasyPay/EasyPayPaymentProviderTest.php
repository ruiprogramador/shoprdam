<?php

use App\Domain\Payments\Enums\FailureClass;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Exceptions\PaymentAttemptMismatchException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\PaymentProviderManager;
use App\Domain\Payments\Services\PaymentEventProcessor;
use App\Domain\Payments\Services\PaymentService;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Payments\EasyPay\Exceptions\EasyPayConnectionException;
use App\Payments\EasyPay\Exceptions\EasyPayRequestException;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Database\QueryException;
use Tests\Fakes\FakeEasyPayHttpClient;

/**
 * EasyPay's counterpart to Tests\Feature\Payments\Stripe\StripePaymentProviderTest
 * — the same generic PaymentService guarantees, proven against a second,
 * genuinely different provider (decimal `value` instead of minor-unit
 * `amount`, a `key` correlation field instead of `metadata.order_id`, `mb`/
 * `mbway` method codes, header-based auth instead of a bearer secret).
 */
it('creates an EasyPay payment and a matching pending wallet transaction for an order', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $fakeClient = new FakeEasyPayHttpClient(easyPayPaymentBody('ep_fake_123', (string) $order->id));
    $fakeClient->install();

    $attempt = app(PaymentService::class)->startAttempt($order, 'easypay', 'mbway');

    expect($attempt->provider_reference)
        ->toBe('ep_fake_123')
        ->and($attempt->provider)
        ->toBe('easypay')
        ->and($attempt->method)
        ->toBe('mbway')
        ->and($attempt->status)
        ->toBe(PaymentAttemptStatus::Claimed)
        ->and($fakeClient->requests)
        ->toHaveCount(1);

    $request = $fakeClient->requests[0];

    expect($request->method())
        ->toBe('POST')
        ->and($request['value'])
        ->toBe('42.50')
        ->and($request['currency'])
        ->toBe('EUR')
        ->and($request['method'])
        ->toBe('mbway')
        ->and($request['key'])
        ->toBe((string) $order->id)
        ->and($request->header('AccountId')[0])
        ->toBe(config('services.easypay.account_id'))
        ->and($request->header('ApiKey')[0])
        ->toBe(config('services.easypay.api_key'));

    $transaction = StoreWalletTransaction::where('external_reference', 'ep_fake_123')->firstOrFail();

    expect($transaction->external_provider)
        ->toBe('easypay')
        ->and($transaction->amount)
        ->toBe('42.50')
        ->and($transaction->status->slug)
        ->toBe('pending')
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');

    $payment = Payment::where('order_id', $order->id)->firstOrFail();

    expect($payment->status->value)
        ->toBe('pending')
        ->and($payment->current_payment_attempt_id)
        ->toBe($attempt->id);
});

it('maps the multibanco method to EasyPay\'s own "mb" method code, never leaking the domain method name', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $fakeClient = new FakeEasyPayHttpClient(easyPayPaymentBody('ep_fake_mb', (string) $order->id, ['method' => 'mb']));
    $fakeClient->install();

    $attempt = app(PaymentService::class)->startAttempt($order, 'easypay', 'multibanco');

    expect($attempt->method)
        ->toBe('multibanco')
        ->and($fakeClient->requests[0]['method'])
        ->toBe('mb');
});

it('sends a deterministic, attempt-derived Idempotency-Key header to EasyPay', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $fakeClient = new FakeEasyPayHttpClient(easyPayPaymentBody('ep_fake_idem', (string) $order->id));
    $fakeClient->install();

    $attempt = app(PaymentService::class)->startAttempt($order, 'easypay', 'mbway');
    $payment = Payment::where('order_id', $order->id)->firstOrFail();

    expect($fakeClient->requests[0]->header('Idempotency-Key')[0])
        ->toBe("payment-{$payment->id}-attempt-{$attempt->id}");
});

it('leaves an untracked EasyPay payment behind if recording the Wallet transaction fails after it', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_record_fails', (string) $order->id)))->install();

    $this->mock(WalletTransactionService::class, function ($mock) {
        $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('DB is down'));
    });

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'easypay', 'mbway'))
        ->toThrow(RuntimeException::class, 'DB is down');

    expect(StoreWalletTransaction::where('external_reference', 'ep_record_fails')->exists())->toBeFalse();

    $attempt = PaymentAttempt::where('payment_id', Payment::where('order_id', $order->id)->value('id'))->firstOrFail();

    expect($attempt->provider_reference)
        ->toBeNull()
        ->and($attempt->status)
        ->toBe(PaymentAttemptStatus::Pending);
});

it('recovers on retry after record() fails, without creating a duplicate claim or transaction', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_retry', (string) $order->id)))->install();

    $walletService = app(WalletService::class);
    $providers = app(PaymentProviderManager::class);
    $eventProcessor = app(PaymentEventProcessor::class);

    $failingWalletTransactionService = Mockery::mock(WalletTransactionService::class);
    $failingWalletTransactionService->shouldReceive('record')->once()->andThrow(new RuntimeException('DB is down'));

    $failingService = new PaymentService($walletService, $failingWalletTransactionService, $providers, $eventProcessor);

    expect(fn () => $failingService->startAttempt($order, 'easypay', 'mbway'))
        ->toThrow(RuntimeException::class, 'DB is down');

    $attempt = PaymentAttempt::where('payment_id', Payment::where('order_id', $order->id)->value('id'))->firstOrFail();

    $recoveringService = new PaymentService($walletService, app(WalletTransactionService::class), $providers, $eventProcessor);
    $recovered = $recoveringService->finalizeAttempt($attempt);

    expect($recovered->provider_reference)
        ->toBe('ep_retry')
        ->and(PaymentAttempt::where('payment_id', $attempt->payment_id)->count())
        ->toBe(1)
        ->and(StoreWalletTransaction::where('external_reference', 'ep_retry')->count())
        ->toBe(1)
        ->and($recovered->status)
        ->toBe(PaymentAttemptStatus::Claimed);
});

it('returns the claimed payment, not a second one, when called twice for the same order', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $firstBody = easyPayPaymentBody('ep_first', (string) $order->id);

    $fakeClient = new FakeEasyPayHttpClient(
        responseBody: $firstBody,
        subsequentResponseBody: easyPayPaymentBody('ep_second', (string) $order->id),
        responsesById: ['ep_first' => $firstBody],
    );
    $fakeClient->install();

    $service = app(PaymentService::class);
    $first = $service->startAttempt($order, 'easypay', 'mbway');
    $second = $service->finalizeAttempt($first->fresh());

    expect($first->provider_reference)
        ->toBe('ep_first')
        ->and($second->provider_reference)
        ->toBe('ep_first')
        ->and(PaymentAttempt::where('payment_id', $first->payment_id)->count())
        ->toBe(1)
        ->and(StoreWalletTransaction::where('external_reference', 'ep_second')->exists())
        ->toBeFalse();
});

it('does not mask an unrelated QueryException as the known provider_reference race', function () {
    $store = Store::factory()->create();
    $orderA = Order::factory()->forStore($store)->amount('42.50')->create();
    $orderB = Order::factory()->forStore($store)->amount('10.00')->create();

    $paymentA = Payment::create(['order_id' => $orderA->id]);
    $paymentB = Payment::create(['order_id' => $orderB->id]);

    PaymentAttempt::create([
        'payment_id' => $paymentB->id,
        'provider' => 'easypay',
        'method' => 'mbway',
        'provider_reference' => 'ep_collision',
        'idempotency_key' => "payment-{$paymentB->id}-attempt-collision",
        'status' => PaymentAttemptStatus::Claimed,
    ]);

    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_collision', (string) $orderA->id)))->install();

    expect(fn () => app(PaymentService::class)->startAttempt($orderA, 'easypay', 'mbway'))
        ->toThrow(QueryException::class);

    expect(StoreWalletTransaction::where('referenceable_type', $orderA->getMorphClass())
        ->where('referenceable_id', $orderA->id)
        ->exists())
        ->toBeFalse();
});

it('refuses to claim a payment whose amount does not match the Order, without creating local state', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_wrong_amount', (string) $order->id, ['value' => '9.99'])))->install();

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'easypay', 'mbway'))
        ->toThrow(PaymentAttemptMismatchException::class);

    expect(StoreWalletTransaction::where('external_reference', 'ep_wrong_amount')->exists())->toBeFalse();

    $attempt = PaymentAttempt::where('payment_id', Payment::where('order_id', $order->id)->value('id'))->firstOrFail();

    expect($attempt->status)
        ->toBe(PaymentAttemptStatus::Pending)
        ->and($attempt->provider_reference)
        ->toBeNull();
});

it('refuses to claim a payment whose currency does not match the Order', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_wrong_currency', (string) $order->id, ['currency' => 'USD'])))->install();

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'easypay', 'mbway'))
        ->toThrow(PaymentAttemptMismatchException::class);
});

it('refuses to claim a payment whose correlation key does not match the Order', function () {
    $store = Store::factory()->create();
    $orderA = Order::factory()->forStore($store)->amount('42.50')->create();
    $orderB = Order::factory()->forStore($store)->amount('42.50')->create();

    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_wrong_key', (string) $orderB->id)))->install();

    expect(fn () => app(PaymentService::class)->startAttempt($orderA, 'easypay', 'mbway'))
        ->toThrow(PaymentAttemptMismatchException::class);
});

it('validates the canonical payment fetched via retrieveByReference() when finalizing the same attempt twice concurrently', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $winnerBody = easyPayPaymentBody('ep_race_winner', (string) $order->id);
    $mismatchedOnRetrieve = easyPayPaymentBody('ep_race_winner', (string) $order->id, ['value' => '9.99']);

    $fakeClient = new FakeEasyPayHttpClient(
        responseBody: $winnerBody,
        subsequentResponseBody: easyPayPaymentBody('ep_race_loser', (string) $order->id),
        responsesById: ['ep_race_winner' => $mismatchedOnRetrieve],
    );
    $fakeClient->install();

    $service = app(PaymentService::class);

    $attempt = PaymentAttempt::create([
        'payment_id' => Payment::create(['order_id' => $order->id])->id,
        'provider' => 'easypay',
        'method' => 'mbway',
        'idempotency_key' => "order-{$order->id}-race",
        'status' => PaymentAttemptStatus::Pending,
    ]);

    $workerA = PaymentAttempt::find($attempt->id);
    $workerB = PaymentAttempt::find($attempt->id);

    $winnerResult = $service->finalizeAttempt($workerA);

    expect($winnerResult->provider_reference)->toBe('ep_race_winner');

    expect(fn () => $service->finalizeAttempt($workerB))
        ->toThrow(PaymentAttemptMismatchException::class);

    expect(PaymentAttempt::where('payment_id', $attempt->payment_id)->count())->toBe(1)
        ->and(StoreWalletTransaction::where('referenceable_type', $order->getMorphClass())
            ->where('referenceable_id', $order->id)
            ->count())
        ->toBe(1);
});

it('recovers cleanly after a network failure whose ambiguity leaves EasyPay possibly having processed the request', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    FakeEasyPayHttpClient::connectionFailure()->install();

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'easypay', 'mbway'))
        ->toThrow(EasyPayConnectionException::class);

    $attempt = PaymentAttempt::where('payment_id', Payment::where('order_id', $order->id)->value('id'))->firstOrFail();

    expect($attempt->status)->toBe(PaymentAttemptStatus::Pending);

    (new FakeEasyPayHttpClient(easyPayPaymentBody('ep_network_ambiguity', (string) $order->id)))->install();

    $recovered = app(PaymentService::class)->finalizeAttempt($attempt);

    expect($recovered->provider_reference)->toBe('ep_network_ambiguity')
        ->and(PaymentAttempt::where('payment_id', $attempt->payment_id)->count())
        ->toBe(1)
        ->and(StoreWalletTransaction::where('external_reference', 'ep_network_ambiguity')->count())
        ->toBe(1)
        ->and($recovered->status)
        ->toBe(PaymentAttemptStatus::Claimed);
});

// --- classifyFailure() ---------------------------------------------------

it('classifies a connection failure as retryable', function () {
    $provider = new App\Payments\EasyPay\EasyPayPaymentProvider;

    expect($provider->classifyFailure(new EasyPayConnectionException('timeout')))
        ->toBe(FailureClass::Retryable);
});

it('classifies EasyPay 409/429/5xx responses as retryable', function (int $status) {
    $provider = new App\Payments\EasyPay\EasyPayPaymentProvider;

    expect($provider->classifyFailure(new EasyPayRequestException($status, [], 'error')))
        ->toBe(FailureClass::Retryable);
})->with([409, 429, 500, 502, 503]);

it('classifies EasyPay 400/403/404/422 responses as non-retryable', function (int $status) {
    $provider = new App\Payments\EasyPay\EasyPayPaymentProvider;

    expect($provider->classifyFailure(new EasyPayRequestException($status, [], 'error')))
        ->toBe(FailureClass::NonRetryable);
})->with([400, 403, 404, 422]);

it('classifies an unsupported method as non-retryable — no request was even sent', function () {
    $provider = new App\Payments\EasyPay\EasyPayPaymentProvider;

    expect($provider->classifyFailure(new InvalidArgumentException("EasyPay does not support payment method 'card'.")))
        ->toBe(FailureClass::NonRetryable);
});

it('fails closed with a non-retryable error for a method EasyPay does not support', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'easypay', 'card'))
        ->toThrow(InvalidArgumentException::class);
});
