<?php

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
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Database\QueryException;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\IdempotencyException;
use Tests\Fakes\FakeStripeHttpClient;
use Tests\Fakes\FakeStripeMetadataMode;

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

it('creates a Stripe payment and a matching pending wallet transaction for an order', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_fake_123',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_fake_123_secret_abc',
        'metadata' => ['order_id' => (string) $order->id],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    $attempt = app(PaymentService::class)->startAttempt($order, 'stripe', 'card');

    expect($attempt->provider_reference)
        ->toBe('pi_fake_123')
        ->and($attempt->provider)
        ->toBe('stripe')
        ->and($attempt->method)
        ->toBe('card')
        ->and($attempt->status)
        ->toBe(PaymentAttemptStatus::Claimed)
        ->and($fakeClient->requests)
        ->toHaveCount(1)
        ->and($fakeClient->requests[0]['params']['amount'])
        ->toBe(4250)
        ->and($fakeClient->requests[0]['params']['currency'])
        ->toBe('eur')
        ->and($fakeClient->requests[0]['params']['metadata']['order_id'])
        ->toBe((string) $order->id);

    $transaction = StoreWalletTransaction::where('external_reference', 'pi_fake_123')->firstOrFail();

    expect($transaction->external_provider)
        ->toBe('stripe')
        ->and($transaction->amount)
        ->toBe('42.50')
        ->and($transaction->status->slug)
        ->toBe('pending')
        ->and($transaction->referenceable_type)
        ->toBe($order->getMorphClass())
        ->and($transaction->referenceable_id)
        ->toBe($order->id)
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');

    $payment = Payment::where('order_id', $order->id)->firstOrFail();

    expect($payment->status->value)
        ->toBe('pending')
        ->and($payment->current_payment_attempt_id)
        ->toBe($attempt->id);
});

it('leaves an untracked Stripe payment behind if recording the Wallet transaction fails after it', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_fake_record_fails',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_fake_record_fails_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    $this->mock(WalletTransactionService::class, function ($mock) {
        $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('DB is down'));
    });

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'stripe', 'card'))
        ->toThrow(RuntimeException::class, 'DB is down');

    // Documents the accepted external-non-atomicity gap (see
    // docs/wallet/integrations.md): Stripe already has the payment — the
    // fake client received the request — but no local Wallet transaction
    // points at it, and no webhook can ever resolve that, since the
    // processor only acts on a transaction it can already find.
    expect($fakeClient->requests)
        ->toHaveCount(1)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_fake_record_fails')->exists())
        ->toBeFalse();

    // The claim (provider_reference) rolls back together with the failed
    // record() call, so this Payment isn't permanently locked out of a
    // later retry — the attempt row survives (written in its own, separate
    // commit before Stripe was even called) and stays `pending`, exactly
    // what ReconcileOrphanedPaymentAttempts looks for.
    $attempt = PaymentAttempt::where('payment_id', Payment::where('order_id', $order->id)->value('id'))->firstOrFail();

    expect($attempt->provider_reference)
        ->toBeNull()
        ->and($attempt->status)
        ->toBe(PaymentAttemptStatus::Pending);
});

it('recovers on retry after record() fails, without creating a duplicate claim or transaction', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_fake_retry',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_fake_retry_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    $walletService = app(WalletService::class);
    $providers = app(PaymentProviderManager::class);
    $eventProcessor = app(PaymentEventProcessor::class);

    $failingWalletTransactionService = Mockery::mock(WalletTransactionService::class);
    $failingWalletTransactionService->shouldReceive('record')->once()->andThrow(new RuntimeException('DB is down'));

    $failingService = new PaymentService($walletService, $failingWalletTransactionService, $providers, $eventProcessor);

    expect(fn () => $failingService->startAttempt($order, 'stripe', 'card'))
        ->toThrow(RuntimeException::class, 'DB is down');

    $attempt = PaymentAttempt::where('payment_id', Payment::where('order_id', $order->id)->value('id'))->firstOrFail();

    // Retry with a real WalletTransactionService (no longer mocked to fail).
    // Same idempotency key, so the fake also reports the same reference.
    $recoveringService = new PaymentService($walletService, app(WalletTransactionService::class), $providers, $eventProcessor);

    $recovered = $recoveringService->finalizeAttempt($attempt);

    expect($recovered->provider_reference)
        ->toBe('pi_fake_retry')
        ->and(PaymentAttempt::where('payment_id', $attempt->payment_id)->count())
        ->toBe(1)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_fake_retry')->count())
        ->toBe(1)
        ->and($recovered->status)
        ->toBe(PaymentAttemptStatus::Claimed);
});

it('sends a deterministic, attempt-derived idempotency key to Stripe', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_fake_idem',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_fake_idem_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    $attempt = app(PaymentService::class)->startAttempt($order, 'stripe', 'card');

    $idempotencyHeader = collect($fakeClient->requests[0]['headers'])
        ->first(fn ($header) => str_starts_with($header, 'Idempotency-Key:'));

    $payment = Payment::where('order_id', $order->id)->firstOrFail();

    expect($idempotencyHeader)->toBe("Idempotency-Key: payment-{$payment->id}-attempt-{$attempt->id}");
});

it('returns the claimed payment, not a second one, when called twice for the same order', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $firstBody = [
        'id' => 'pi_fake_first',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_fake_first_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ];

    // Two different PaymentIntent ids on the two create() calls, as if
    // Stripe's idempotency-key dedup somehow didn't hold — proving the
    // payment_attempts (provider, provider_reference) unique constraint,
    // not trust in Stripe's behavior, is what enforces the invariant.
    $fakeClient = new FakeStripeHttpClient(
        responseBody: $firstBody,
        subsequentResponseBody: [
            'id' => 'pi_fake_second',
            'object' => 'payment_intent',
            'amount' => 4250,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_fake_second_secret',
            'metadata' => ['order_id' => (string) $order->id],
        ],
        responsesById: ['pi_fake_first' => $firstBody],
    );

    ApiRequestor::setHttpClient($fakeClient);

    $service = app(PaymentService::class);
    $first = $service->startAttempt($order, 'stripe', 'card');

    // The second call resumes the same (already-claimed) attempt instead
    // of starting a new one — an attempt with a provider_reference set is
    // no longer eligible to be resumed as "in flight" by startAttempt();
    // finalizeAttempt() directly is the equivalent of a plain repeat call.
    $second = $service->finalizeAttempt($first->fresh());

    expect($first->provider_reference)
        ->toBe('pi_fake_first')
        ->and($second->provider_reference)
        ->toBe('pi_fake_first')
        ->and(PaymentAttempt::where('payment_id', $first->payment_id)->count())
        ->toBe(1)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_fake_first')->exists())
        ->toBeTrue()
        ->and(StoreWalletTransaction::where('external_reference', 'pi_fake_second')->exists())
        ->toBeFalse();
});

it('does not mask an unrelated QueryException as the known provider_reference race', function () {
    $store = Store::factory()->create();
    $orderA = Order::factory()->forStore($store)->amount('42.50')->create();
    $orderB = Order::factory()->forStore($store)->amount('10.00')->create();

    $paymentA = Payment::create(['order_id' => $orderA->id]);
    $paymentB = Payment::create(['order_id' => $orderB->id]);

    // A claim for a *different* attempt already owns this provider
    // reference — impossible in real use (the idempotency key is
    // attempt-specific), but it lets the (provider, provider_reference)
    // unique constraint fail the insert for a reason that has nothing to
    // do with attemptA's own race.
    PaymentAttempt::create([
        'payment_id' => $paymentB->id,
        'provider' => 'stripe',
        'method' => 'card',
        'provider_reference' => 'pi_collision',
        'idempotency_key' => "payment-{$paymentB->id}-attempt-collision",
        'status' => PaymentAttemptStatus::Claimed,
    ]);

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_collision',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_collision_secret',
        'metadata' => ['order_id' => (string) $orderA->id],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    expect(fn () => app(PaymentService::class)->startAttempt($orderA, 'stripe', 'card'))
        ->toThrow(QueryException::class);

    expect(StoreWalletTransaction::where('referenceable_type', $orderA->getMorphClass())
        ->where('referenceable_id', $orderA->id)
        ->exists())
        ->toBeFalse();
});

it('refuses to claim a payment whose amount does not match the Order, without creating local state', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_wrong_amount',
        'object' => 'payment_intent',
        'amount' => 999, // Order expects 4250 minor units (42.50).
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_wrong_amount_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'stripe', 'card'))
        ->toThrow(PaymentAttemptMismatchException::class);

    expect(StoreWalletTransaction::where('external_reference', 'pi_wrong_amount')->exists())
        ->toBeFalse();

    $attempt = PaymentAttempt::where('payment_id', Payment::where('order_id', $order->id)->value('id'))->firstOrFail();

    // Thrown before the attempt row is ever touched again, so it's left
    // exactly where createDurableAttempt() put it — pending, ready for
    // ReconcileOrphanedPaymentAttempts to pick up and, on the same
    // mismatch recurring, send straight to needs_attention.
    expect($attempt->status)
        ->toBe(PaymentAttemptStatus::Pending)
        ->and($attempt->provider_reference)
        ->toBeNull();
});

it('refuses to claim a payment whose currency does not match the Order', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_wrong_currency',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'usd', // Order's currency is eur.
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_wrong_currency_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'stripe', 'card'))
        ->toThrow(PaymentAttemptMismatchException::class);
});

it('refuses to claim a payment whose correlation id does not match the Order', function () {
    $store = Store::factory()->create();
    $orderA = Order::factory()->forStore($store)->amount('42.50')->create();
    $orderB = Order::factory()->forStore($store)->amount('42.50')->create();

    // ReturnExactConfiguredMetadata: AutoEchoRequestMetadata (the fake's
    // default) would overwrite this with the request's own (correct)
    // metadata.order_id, making it impossible to express "Stripe responded
    // with the wrong order_id" at all.
    $fakeClient = new FakeStripeHttpClient(
        responseBody: [
            'id' => 'pi_wrong_metadata',
            'object' => 'payment_intent',
            'amount' => 4250,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_wrong_metadata_secret',
            'metadata' => ['order_id' => (string) $orderB->id],
        ],
        metadataMode: FakeStripeMetadataMode::ReturnExactConfiguredMetadata,
    );

    ApiRequestor::setHttpClient($fakeClient);

    expect(fn () => app(PaymentService::class)->startAttempt($orderA, 'stripe', 'card'))
        ->toThrow(PaymentAttemptMismatchException::class);
});

it('refuses to claim a payment whose metadata is missing entirely', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $fakeClient = new FakeStripeHttpClient(
        responseBody: [
            'id' => 'pi_missing_metadata',
            'object' => 'payment_intent',
            'amount' => 4250,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_missing_metadata_secret',
        ],
        metadataMode: FakeStripeMetadataMode::ReturnExactConfiguredMetadata,
    );

    ApiRequestor::setHttpClient($fakeClient);

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'stripe', 'card'))
        ->toThrow(PaymentAttemptMismatchException::class);
});

it('validates the canonical payment fetched via retrieveByReference() when finalizing the same attempt twice concurrently, not just the one this call created', function () {
    // Simulates two reconciliation workers finalizing the *same* attempt
    // row concurrently (e.g. a lease reclaimed while the original worker
    // was still mid-call) — each loads its own copy of the row before
    // either writes, so both see provider_reference still null.
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $winnerBody = [
        'id' => 'pi_race_winner',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_race_winner_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ];

    // Contrived (Stripe wouldn't really return a different object on
    // retrieve() than on create() for the same id), but it's the only way
    // to isolate "does the losing side's fallback retrieveByReference()
    // path actually get validated" — responsesById always wins over
    // responseBody/subsequentResponseBody.
    $mismatchedOnRetrieve = [
        'id' => 'pi_race_winner',
        'object' => 'payment_intent',
        'amount' => 999,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_race_winner_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ];

    $fakeClient = new FakeStripeHttpClient(
        responseBody: $winnerBody,
        subsequentResponseBody: [
            'id' => 'pi_race_loser',
            'object' => 'payment_intent',
            'amount' => 4250,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_race_loser_secret',
            'metadata' => ['order_id' => (string) $order->id],
        ],
        responsesById: ['pi_race_winner' => $mismatchedOnRetrieve],
    );

    ApiRequestor::setHttpClient($fakeClient);

    $service = app(PaymentService::class);

    // Both "workers" find the same durable attempt row (this is what
    // createDurableAttempt() would have already written once, before
    // either worker ever calls the provider) and each loads its own copy
    // of it.
    $attempt = PaymentAttempt::create([
        'payment_id' => Payment::create(['order_id' => $order->id])->id,
        'provider' => 'stripe',
        'method' => 'card',
        'idempotency_key' => "order-{$order->id}-race",
        'status' => PaymentAttemptStatus::Pending,
    ]);

    $workerA = PaymentAttempt::find($attempt->id);
    $workerB = PaymentAttempt::find($attempt->id);

    $winnerResult = $service->finalizeAttempt($workerA);

    expect($winnerResult->provider_reference)->toBe('pi_race_winner');

    // Worker B's own create() call (the fake's subsequentResponseBody)
    // returns pi_race_loser, but its conditional UPDATE loses to worker
    // A's already-committed provider_reference — it must fetch and
    // validate pi_race_winner (via responsesById's mismatched body) before
    // ever trusting it, and fail closed instead.
    expect(fn () => $service->finalizeAttempt($workerB))
        ->toThrow(PaymentAttemptMismatchException::class);

    // Untouched: the mismatch was only detected on the loser's own return
    // value, after the winner's claim/transaction already existed.
    expect(PaymentAttempt::where('payment_id', $attempt->payment_id)->count())->toBe(1)
        ->and(StoreWalletTransaction::where('referenceable_type', $order->getMorphClass())
            ->where('referenceable_id', $order->id)
            ->count())
        ->toBe(1);
});

it('fails closed with a non-retryable IdempotencyException when the Order was mutated before a retry reuses the same key', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    // Real Stripe raises IdempotencyException when the same key is replayed
    // with different request parameters — exactly what happens here, since
    // the Order's amount changed between the two calls but the attempt's
    // idempotency key did not.
    $fakeClient = new FakeStripeHttpClient(
        throws: new IdempotencyException(
            'Keys for idempotent requests can only be used with the same parameters they were first used with. Learn more at https://stripe.com/docs/api/idempotent_requests.'
        ),
    );

    ApiRequestor::setHttpClient($fakeClient);

    $order->update(['amount' => '99.99']);

    expect(fn () => app(PaymentService::class)->startAttempt($order->fresh(), 'stripe', 'card'))
        ->toThrow(IdempotencyException::class);

    expect(StoreWalletTransaction::where('referenceable_type', $order->getMorphClass())
        ->where('referenceable_id', $order->id)
        ->exists())
        ->toBeFalse();
});

it('recovers cleanly after a network failure whose ambiguity leaves Stripe possibly having processed the request', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    // First call: the request never got a response at all (DNS failure,
    // timeout, connection reset) — Stripe may or may not have actually
    // processed it before the network broke.
    $failingClient = new FakeStripeHttpClient(
        throws: new ApiConnectionException('Simulated network failure reaching Stripe'),
    );

    ApiRequestor::setHttpClient($failingClient);

    expect(fn () => app(PaymentService::class)->startAttempt($order, 'stripe', 'card'))
        ->toThrow(ApiConnectionException::class);

    $attempt = PaymentAttempt::where('payment_id', Payment::where('order_id', $order->id)->value('id'))->firstOrFail();

    expect($attempt->status)->toBe(PaymentAttemptStatus::Pending);

    // Retry: same idempotency key, so whether or not Stripe actually
    // processed the first attempt, it returns exactly one payment.
    $recoveringClient = new FakeStripeHttpClient([
        'id' => 'pi_network_ambiguity',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_network_ambiguity_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]);

    ApiRequestor::setHttpClient($recoveringClient);

    $recovered = app(PaymentService::class)->finalizeAttempt($attempt);

    expect($recovered->provider_reference)->toBe('pi_network_ambiguity')
        ->and(PaymentAttempt::where('payment_id', $attempt->payment_id)->count())
        ->toBe(1)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_network_ambiguity')->count())
        ->toBe(1)
        ->and($recovered->status)
        ->toBe(PaymentAttemptStatus::Claimed);
});
