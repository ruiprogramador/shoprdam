<?php

use App\Enums\PaymentIntentAttemptStatus;
use App\Models\Order;
use App\Models\OrderPaymentIntent;
use App\Models\OrderPaymentIntentAttempt;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Payments\Stripe\StripePaymentService;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Database\QueryException;
use Stripe\ApiRequestor;
use Tests\Fakes\FakeStripeHttpClient;

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

it('creates a Stripe PaymentIntent and a matching pending wallet transaction for an order', function () {
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

    $paymentIntent = app(StripePaymentService::class)->createPaymentIntentForOrder($order);

    expect($paymentIntent->id)
        ->toBe('pi_fake_123')
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

    expect(OrderPaymentIntentAttempt::where('order_id', $order->id)->first()->status)
        ->toBe(PaymentIntentAttemptStatus::Claimed);
});

it('leaves an untracked Stripe PaymentIntent behind if recording the Wallet transaction fails after it', function () {
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

    expect(fn () => app(StripePaymentService::class)->createPaymentIntentForOrder($order))
        ->toThrow(RuntimeException::class, 'DB is down');

    // Documents the accepted external-non-atomicity gap (see
    // docs/wallet/integrations.md): Stripe already has the PaymentIntent —
    // the fake client received the request — but no local Wallet
    // transaction points at it, and no webhook can ever resolve that,
    // since the dispatcher only acts on a transaction it can already find.
    expect($fakeClient->requests)
        ->toHaveCount(1)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_fake_record_fails')->exists())
        ->toBeFalse()
        // The claim row rolls back together with the failed record() call,
        // so this Order isn't permanently locked out of a later retry.
        ->and(OrderPaymentIntent::where('order_id', $order->id)->exists())
        ->toBeFalse();

    // The attempt row survives the rollback (it's written in its own,
    // separate commit before Stripe is even called) and stays `pending` —
    // this is exactly the state ReconcileOrphanedPaymentIntents looks for.
    expect(OrderPaymentIntentAttempt::where('order_id', $order->id)->first()->status)
        ->toBe(PaymentIntentAttemptStatus::Pending);
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

    $failingWalletTransactionService = Mockery::mock(WalletTransactionService::class);
    $failingWalletTransactionService->shouldReceive('record')->once()->andThrow(new RuntimeException('DB is down'));

    $failingService = new StripePaymentService($walletService, $failingWalletTransactionService);

    expect(fn () => $failingService->createPaymentIntentForOrder($order))
        ->toThrow(RuntimeException::class, 'DB is down');

    // Retry with a real WalletTransactionService (no longer mocked to fail).
    // Same idempotency key, so the fake also reports the same PaymentIntent id.
    $recoveringService = new StripePaymentService($walletService, app(WalletTransactionService::class));

    $paymentIntent = $recoveringService->createPaymentIntentForOrder($order);

    expect($paymentIntent->id)
        ->toBe('pi_fake_retry')
        ->and(OrderPaymentIntent::where('order_id', $order->id)->count())
        ->toBe(1)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_fake_retry')->count())
        ->toBe(1)
        ->and(OrderPaymentIntentAttempt::where('order_id', $order->id)->first()->status)
        ->toBe(PaymentIntentAttemptStatus::Claimed);
});

it('sends a deterministic, order-derived idempotency key to Stripe', function () {
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

    app(StripePaymentService::class)->createPaymentIntentForOrder($order);

    $idempotencyHeader = collect($fakeClient->requests[0]['headers'])
        ->first(fn ($header) => str_starts_with($header, 'Idempotency-Key:'));

    expect($idempotencyHeader)->toBe("Idempotency-Key: order-{$order->id}-payment-intent");
});

it('returns the claimed PaymentIntent, not a second one, when called twice for the same order', function () {
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
    // order_payment_intents unique constraint, not trust in Stripe's
    // behavior, is what enforces the invariant. responsesById lets the
    // fake answer the service's retrieve() fallback (used to recover the
    // actually-claimed PaymentIntent after losing the local race) with the
    // right object instead of whatever the last create() call returned.
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

    $service = app(StripePaymentService::class);
    $firstPaymentIntent = $service->createPaymentIntentForOrder($order);
    $secondPaymentIntent = $service->createPaymentIntentForOrder($order);

    expect($firstPaymentIntent->id)
        ->toBe('pi_fake_first')
        // The second call must return the same PaymentIntent the Wallet
        // transaction actually references, not pi_fake_second — even
        // though that's what its own Stripe call returned.
        ->and($secondPaymentIntent->id)
        ->toBe('pi_fake_first')
        ->and(OrderPaymentIntent::where('order_id', $order->id)->count())
        ->toBe(1)
        ->and(OrderPaymentIntent::where('order_id', $order->id)->value('payment_intent_id'))
        ->toBe('pi_fake_first')
        ->and(StoreWalletTransaction::where('referenceable_type', $order->getMorphClass())
            ->where('referenceable_id', $order->id)
            ->count())
        ->toBe(1)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_fake_first')->exists())
        ->toBeTrue()
        ->and(StoreWalletTransaction::where('external_reference', 'pi_fake_second')->exists())
        ->toBeFalse();
});

it('does not mask an unrelated QueryException as the known order_id race', function () {
    $store = Store::factory()->create();
    $orderA = Order::factory()->forStore($store)->amount('42.50')->create();
    $orderB = Order::factory()->forStore($store)->amount('10.00')->create();

    // A claim for a *different* Order already owns this PaymentIntent id —
    // impossible in real use (the idempotency key is order-specific), but
    // it lets the payment_intent_id unique constraint fail the insert for
    // a reason that has nothing to do with orderA's own order_id race.
    OrderPaymentIntent::create([
        'order_id' => $orderB->id,
        'payment_intent_id' => 'pi_collision',
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

    // No claim exists for orderA's own order_id, so the recovery lookup
    // finds nothing to explain the failure — it must rethrow rather than
    // silently treat this as "the known race" and return a bogus claim.
    expect(fn () => app(StripePaymentService::class)->createPaymentIntentForOrder($orderA))
        ->toThrow(QueryException::class);

    expect(OrderPaymentIntent::where('order_id', $orderA->id)->exists())
        ->toBeFalse()
        ->and(StoreWalletTransaction::where('referenceable_type', $orderA->getMorphClass())
            ->where('referenceable_id', $orderA->id)
            ->exists())
        ->toBeFalse();
});
