<?php

use App\Enums\PaymentIntentAttemptStatus;
use App\Models\Order;
use App\Models\OrderPaymentIntent;
use App\Models\OrderPaymentIntentAttempt;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Payments\Stripe\StripePaymentService;
use Illuminate\Support\Facades\Artisan;
use Stripe\ApiRequestor;
use Tests\Fakes\FakeStripeHttpClient;

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

function createOrphanedPaymentIntentAttempt(Order $order, int $ageMinutes = 10): OrderPaymentIntentAttempt
{
    $attempt = OrderPaymentIntentAttempt::create([
        'order_id' => $order->id,
        'idempotency_key' => "order-{$order->id}-payment-intent",
        'status' => PaymentIntentAttemptStatus::Pending,
    ]);

    $attempt->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

    return $attempt->fresh();
}

it('recovers an orphaned attempt by recreating the claim and pending Wallet transaction', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    createOrphanedPaymentIntentAttempt($order);

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_reconciled',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_reconciled_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-intents');

    expect(OrderPaymentIntent::where('order_id', $order->id)->value('payment_intent_id'))
        ->toBe('pi_reconciled')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_reconciled')->exists())
        ->toBeTrue()
        ->and(OrderPaymentIntentAttempt::where('order_id', $order->id)->first()->status)
        ->toBe(PaymentIntentAttemptStatus::Claimed)
        // Recovery only rebuilds the pending local state — it never settles
        // the payment or moves the Wallet balance itself; only the Stripe
        // webhook does that once Stripe confirms the PaymentIntent.
        ->and($order->fresh()->status->slug)
        ->toBe('pending')
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});

it('ignores attempts that are not stale yet', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    createOrphanedPaymentIntentAttempt($order, ageMinutes: 1);

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_should_not_be_requested',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'secret',
        'metadata' => [],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-intents');

    expect($fakeClient->requests)
        ->toHaveCount(0)
        ->and(OrderPaymentIntentAttempt::where('order_id', $order->id)->first()->status)
        ->toBe(PaymentIntentAttemptStatus::Pending);
});

it('marks an attempt needs_attention after exhausting recovery attempts, and stops retrying it', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = createOrphanedPaymentIntentAttempt($order);

    $fakeClient = new FakeStripeHttpClient(
        ['error' => ['type' => 'api_error', 'message' => 'Simulated persistent Stripe failure']],
        500,
    );

    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-intents', ['--max-attempts' => 1]);

    expect($attempt->fresh()->status)
        ->toBe(PaymentIntentAttemptStatus::NeedsAttention)
        ->and($attempt->fresh()->recovery_attempts)
        ->toBe(1)
        ->and($attempt->fresh()->last_recovery_error)
        ->not->toBeNull();

    // No longer `pending`, so a further run must leave it alone.
    Artisan::call('app:reconcile-orphaned-payment-intents', ['--max-attempts' => 1]);

    expect($attempt->fresh()->recovery_attempts)->toBe(1);
});

it('marks an attempt needs_attention once it exceeds --max-age, without calling Stripe, regardless of --max-attempts', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    // Older than the default --stale-after (so it's picked up as orphaned)
    // and, crucially, older than --max-age too — simulating an attempt that
    // was never retried in time, e.g. because the scheduler was down.
    $attempt = createOrphanedPaymentIntentAttempt($order, ageMinutes: 800);

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_should_not_be_requested',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'secret',
        'metadata' => [],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    // A huge --max-attempts budget must not override the absolute age
    // ceiling — otherwise raising it alone could push recovery past
    // Stripe's idempotency key retention window (at least 24h) and risk
    // the same key being processed as a new request.
    Artisan::call('app:reconcile-orphaned-payment-intents', ['--max-age' => 720, '--max-attempts' => 1000]);

    expect($fakeClient->requests)
        ->toHaveCount(0)
        ->and($attempt->fresh()->status)
        ->toBe(PaymentIntentAttemptStatus::NeedsAttention)
        ->and($attempt->fresh()->recovery_attempts)
        ->toBe(0);
});

it('returns the existing claim, not a duplicate, when two sequential recovery calls target the same orphaned attempt', function () {
    // Not a test of real concurrency (these two calls run one after the
    // other, on one connection) — it proves the invariant that actually
    // matters when two workers *do* hit this at the same time: whichever
    // call's INSERT into order_payment_intents commits second loses to the
    // unique `order_id` constraint and falls back to reading the winner's
    // claim, regardless of which call's own Stripe response it started
    // with. That commit-order outcome is exactly what two truly concurrent
    // callers would race to produce; this test fixes the order deterministically
    // instead of leaving it to timing.
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = createOrphanedPaymentIntentAttempt($order);

    $firstBody = [
        'id' => 'pi_reconcile_race_first',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_reconcile_race_first_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ];

    // Two different PaymentIntent ids on the two create() calls, as if two
    // workers each independently called createPaymentIntentForOrder() for
    // the same orphaned Order (exactly what
    // ReconcileOrphanedPaymentIntents::handle() does per attempt) and Stripe's
    // own idempotency-key dedup somehow didn't hold — proving the
    // order_payment_intents unique constraint, not trust in Stripe's
    // response, is what decides the single canonical claim.
    $fakeClient = new FakeStripeHttpClient(
        responseBody: $firstBody,
        subsequentResponseBody: [
            'id' => 'pi_reconcile_race_second',
            'object' => 'payment_intent',
            'amount' => 4250,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_reconcile_race_second_secret',
            'metadata' => ['order_id' => (string) $order->id],
        ],
        responsesById: ['pi_reconcile_race_first' => $firstBody],
    );

    ApiRequestor::setHttpClient($fakeClient);

    $service = app(StripePaymentService::class);
    $firstResult = $service->createPaymentIntentForOrder($order);
    $secondResult = $service->createPaymentIntentForOrder($order);

    expect($firstResult->id)
        ->toBe('pi_reconcile_race_first')
        ->and($secondResult->id)
        ->toBe('pi_reconcile_race_first')
        ->and(OrderPaymentIntent::where('order_id', $order->id)->count())
        ->toBe(1)
        ->and(StoreWalletTransaction::where('referenceable_type', $order->getMorphClass())
            ->where('referenceable_id', $order->id)
            ->count())
        ->toBe(1)
        ->and($attempt->fresh()->status)
        ->toBe(PaymentIntentAttemptStatus::Claimed);
});

it('increments recovery_attempts without giving up before the limit is reached', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = createOrphanedPaymentIntentAttempt($order);

    $fakeClient = new FakeStripeHttpClient(
        ['error' => ['type' => 'api_error', 'message' => 'Simulated transient Stripe failure']],
        500,
    );

    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-intents', ['--max-attempts' => 3]);

    expect($attempt->fresh()->status)
        ->toBe(PaymentIntentAttemptStatus::Pending)
        ->and($attempt->fresh()->recovery_attempts)
        ->toBe(1);
});
