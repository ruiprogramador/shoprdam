<?php

use App\Domain\Payments\Contracts\ProviderEventTranslator;
use App\Domain\Payments\DTOs\ProviderEventOutcome;
use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\PaymentProviderManager;
use App\Domain\Payments\ProviderEventTranslatorManager;
use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Tests\Fakes\FakeStripeHttpClient;
use Tests\Fakes\FakeTestPaymentProvider;

function createClaimedPaymentAttemptWithPendingWalletTransaction(Order $order, string $providerReference): PaymentAttempt
{
    $payment = Payment::firstOrCreate(['order_id' => $order->id]);

    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'stripe',
        'method' => 'card',
        'provider_reference' => $providerReference,
        'idempotency_key' => "payment-{$payment->id}-attempt-seed",
        'status' => PaymentAttemptStatus::Claimed,
    ]);

    $payment->update(['current_payment_attempt_id' => $attempt->id]);

    // Mirrors exactly what PaymentService::claimProviderReference() writes —
    // simulating a claim that already committed before the process died
    // (or replayUnmatchedEvents() itself threw) right after.
    $wallet = app(WalletService::class)->getOrCreateWallet($order->store, $order->currency->code);
    app(WalletTransactionService::class)->record(
        wallet: $wallet,
        categorySlug: 'sale',
        amount: $order->amount,
        reference: new WalletTransactionReference('stripe', $providerReference),
        options: [
            'status' => 'pending',
            'referenceable' => $order,
            'source' => TransactionSource::Api,
            'description' => "Order #{$order->id}",
        ],
    );

    return $attempt->fresh();
}

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

function createOrphanedPaymentAttempt(Order $order, int $ageMinutes = 10): PaymentAttempt
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
    $attempt->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

    return $attempt->fresh();
}

function createLeasedPaymentAttempt(Order $order, Carbon $lockedUntil): PaymentAttempt
{
    $payment = Payment::firstOrCreate(['order_id' => $order->id]);

    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'stripe',
        'method' => 'card',
        'idempotency_key' => "payment-{$payment->id}-attempt-seed",
        'status' => PaymentAttemptStatus::Pending,
        'locked_until' => $lockedUntil,
    ]);

    $payment->update(['current_payment_attempt_id' => $attempt->id]);
    $attempt->forceFill(['created_at' => now()->subMinutes(10)])->save();

    return $attempt->fresh();
}

it('recovers an orphaned attempt by recreating the claim and pending Wallet transaction', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    createOrphanedPaymentAttempt($order);

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

    Artisan::call('app:reconcile-orphaned-payment-attempts');

    $attempt = PaymentAttempt::where('payment_id', Payment::where('order_id', $order->id)->value('id'))->firstOrFail();

    expect($attempt->provider_reference)
        ->toBe('pi_reconciled')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_reconciled')->exists())
        ->toBeTrue()
        ->and($attempt->status)
        ->toBe(PaymentAttemptStatus::Claimed)
        // Recovery only rebuilds the pending local state — it never settles
        // the payment or moves the Wallet balance itself; only a provider's
        // webhook does that once the provider confirms.
        ->and($order->fresh()->status->slug)
        ->toBe('pending')
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});

it('ignores attempts that are not stale yet', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    createOrphanedPaymentAttempt($order, ageMinutes: 1);

    $fakeClient = new FakeStripeHttpClient(['id' => 'pi_should_not_be_requested']);
    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-attempts');

    $attempt = PaymentAttempt::where('payment_id', Payment::where('order_id', $order->id)->value('id'))->firstOrFail();

    expect($fakeClient->requests)
        ->toHaveCount(0)
        ->and($attempt->status)
        ->toBe(PaymentAttemptStatus::Pending);
});

it('marks an attempt needs_attention after exhausting recovery attempts, and stops retrying it', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = createOrphanedPaymentAttempt($order);

    $fakeClient = new FakeStripeHttpClient(
        ['error' => ['type' => 'api_error', 'message' => 'Simulated persistent Stripe failure']],
        500,
    );

    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-attempts', ['--max-attempts' => 1]);

    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::NeedsAttention)
        ->and($attempt->fresh()->recovery_attempts)
        ->toBe(1)
        ->and($attempt->fresh()->last_recovery_error)
        ->not->toBeNull();

    // No longer `pending`, so a further run must leave it alone.
    Artisan::call('app:reconcile-orphaned-payment-attempts', ['--max-attempts' => 1]);

    expect($attempt->fresh()->recovery_attempts)->toBe(1);
});

it('marks an attempt needs_attention once it exceeds --max-age, without calling Stripe, regardless of --max-attempts', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    // Older than the default --stale-after (so it's picked up as orphaned)
    // and, crucially, older than --max-age too — simulating an attempt that
    // was never retried in time, e.g. because the scheduler was down.
    $attempt = createOrphanedPaymentAttempt($order, ageMinutes: 800);

    $fakeClient = new FakeStripeHttpClient(['id' => 'pi_should_not_be_requested']);
    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-attempts', ['--max-age' => 720, '--max-attempts' => 1000]);

    expect($fakeClient->requests)
        ->toHaveCount(0)
        ->and($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::NeedsAttention)
        ->and($attempt->fresh()->recovery_attempts)
        ->toBe(0);
});

it('increments recovery_attempts without giving up before the limit is reached', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = createOrphanedPaymentAttempt($order);

    $fakeClient = new FakeStripeHttpClient(
        ['error' => ['type' => 'api_error', 'message' => 'Simulated transient Stripe failure']],
        500,
    );

    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-attempts', ['--max-attempts' => 3]);

    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Pending)
        ->and($attempt->fresh()->recovery_attempts)
        ->toBe(1);
});

it('leaves an attempt another worker is still recovering alone, even though it is stale', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    // A fresh lease: some other run's acquireLease() won this attempt
    // moments ago and is presumably still calling Stripe for it.
    createLeasedPaymentAttempt($order, lockedUntil: now()->addMinutes(10));

    $fakeClient = new FakeStripeHttpClient(['id' => 'pi_should_not_be_requested']);
    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-attempts');

    $attempt = PaymentAttempt::where('payment_id', Payment::where('order_id', $order->id)->value('id'))->firstOrFail();

    expect($fakeClient->requests)
        ->toHaveCount(0)
        ->and($attempt->status)
        ->toBe(PaymentAttemptStatus::Pending);
});

it('reclaims and recovers an attempt whose lease expired, e.g. because the worker that leased it died', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    // Expired lease: whatever worker leased this attempt never resolved it.
    createLeasedPaymentAttempt($order, lockedUntil: now()->subMinutes(5));

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_reclaimed',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_reclaimed_secret',
        'metadata' => [],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-attempts');

    $attempt = PaymentAttempt::where('payment_id', Payment::where('order_id', $order->id)->value('id'))->firstOrFail();

    expect($attempt->provider_reference)
        ->toBe('pi_reclaimed')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_reclaimed')->exists())
        ->toBeTrue()
        ->and($attempt->status)
        ->toBe(PaymentAttemptStatus::Claimed);
});

it('marks an attempt needs_attention after a single non-retryable Stripe error, without waiting for --max-attempts', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = createOrphanedPaymentAttempt($order);

    // 401 always maps to Stripe\Exception\AuthenticationException — a
    // misconfigured/revoked API key that retrying five times will never fix.
    $fakeClient = new FakeStripeHttpClient(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']],
        401,
    );

    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-attempts', ['--max-attempts' => 5]);

    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::NeedsAttention)
        ->and($attempt->fresh()->recovery_attempts)
        ->toBe(1);
});

it('retries instead of giving up after a 429 rate-limit response', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = createOrphanedPaymentAttempt($order);

    $fakeClient = new FakeStripeHttpClient(
        ['error' => ['type' => 'rate_limit_error', 'message' => 'Too many requests']],
        429,
    );

    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-attempts', ['--max-attempts' => 3]);

    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Pending)
        ->and($attempt->fresh()->recovery_attempts)
        ->toBe(1);
});

it('retries instead of giving up after a network failure reaching Stripe', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = createOrphanedPaymentAttempt($order);

    $fakeClient = new FakeStripeHttpClient(
        throws: new ApiConnectionException('Simulated network failure reaching Stripe'),
    );

    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-attempts', ['--max-attempts' => 3]);

    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Pending)
        ->and($attempt->fresh()->recovery_attempts)
        ->toBe(1);
});

it('marks an attempt needs_attention, without creating a local claim, when the recovered payment does not match the Order', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = createOrphanedPaymentAttempt($order);

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_mismatched',
        'object' => 'payment_intent',
        'amount' => 999,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_mismatched_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]);

    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-attempts', ['--max-attempts' => 5]);

    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::NeedsAttention)
        ->and($attempt->fresh()->recovery_attempts)
        ->toBe(1)
        ->and($attempt->fresh()->last_recovery_error)
        ->toContain('does not match Payment')
        ->and($attempt->fresh()->provider_reference)
        ->toBeNull()
        ->and(StoreWalletTransaction::where('external_reference', 'pi_mismatched')->exists())
        ->toBeFalse();
});

it('rejects invalid CLI options without touching any attempt or calling Stripe', function (array $options, string $expectedMessage) {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = createOrphanedPaymentAttempt($order);

    $fakeClient = new FakeStripeHttpClient(['id' => 'pi_should_not_be_requested']);
    ApiRequestor::setHttpClient($fakeClient);

    $exitCode = Artisan::call('app:reconcile-orphaned-payment-attempts', $options);

    expect($exitCode)
        ->toBe(Command::INVALID)
        ->and(Artisan::output())
        ->toContain($expectedMessage)
        ->and($fakeClient->requests)
        ->toHaveCount(0)
        ->and($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Pending)
        ->and($attempt->fresh()->recovery_attempts)
        ->toBe(0);
})->with([
    'negative --stale-after' => [['--stale-after' => -1], '--stale-after must be >= 0'],
    '--max-attempts of 0' => [['--max-attempts' => 0], '--max-attempts must be >= 1'],
    'non-positive --lease-timeout' => [['--lease-timeout' => 0], '--lease-timeout must be > 0'],
    '--max-age not greater than --stale-after' => [['--stale-after' => 10, '--max-age' => 10], '--max-age (10) must be greater than --stale-after (10)'],
]);

it('replays a succeeded event that was queued before this attempt was recovered', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    createOrphanedPaymentAttempt($order);

    // Simulates the webhook having arrived and been queued (by
    // PaymentEventProcessor::storeUnmatchedEvent()) before any local claim
    // existed for this reference — see ReconciliationWebhookOrderingTest.
    PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_replay_succeeded',
        'event_type' => 'payment_intent.succeeded',
        'provider_reference' => 'pi_replay_succeeded',
        'payload' => [
            'id' => 'evt_replay_succeeded',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_replay_succeeded',
                'object' => 'payment_intent',
                'amount' => 4250,
                'currency' => 'eur',
                'status' => 'succeeded',
                'metadata' => ['order_id' => (string) $order->id],
                'last_payment_error' => null,
            ]],
        ],
        'status' => ProviderEventStatus::Pending,
    ]);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient([
        'id' => 'pi_replay_succeeded',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_replay_succeeded_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    Artisan::call('app:reconcile-orphaned-payment-attempts');

    expect(StoreWalletTransaction::where('external_reference', 'pi_replay_succeeded')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($order->fresh()->status->slug)
        ->toBe('paid')
        ->and(PaymentProviderEvent::where('provider_event_id', 'evt_replay_succeeded')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Applied);
});

it('replays a canceled event that was queued before this attempt was recovered', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    createOrphanedPaymentAttempt($order);

    PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_replay_canceled',
        'event_type' => 'payment_intent.canceled',
        'provider_reference' => 'pi_replay_canceled',
        'payload' => [
            'id' => 'evt_replay_canceled',
            'object' => 'event',
            'type' => 'payment_intent.canceled',
            'data' => ['object' => [
                'id' => 'pi_replay_canceled',
                'object' => 'payment_intent',
                'amount' => 4250,
                'currency' => 'eur',
                'status' => 'canceled',
                'metadata' => ['order_id' => (string) $order->id],
                'last_payment_error' => ['message' => 'Your card was declined.'],
            ]],
        ],
        'status' => ProviderEventStatus::Pending,
    ]);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient([
        'id' => 'pi_replay_canceled',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_replay_canceled_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    Artisan::call('app:reconcile-orphaned-payment-attempts');

    expect(StoreWalletTransaction::where('external_reference', 'pi_replay_canceled')->firstOrFail()->status->slug)
        ->toBe('failed')
        ->and($order->fresh()->status->slug)
        ->toBe('failed')
        ->and(PaymentProviderEvent::where('provider_event_id', 'evt_replay_canceled')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Applied);
});

it('never re-applies an already-applied unmatched event, and never moves the balance twice, across repeated reconciliation runs', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    createOrphanedPaymentAttempt($order);

    PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_replay_idempotent',
        'event_type' => 'payment_intent.succeeded',
        'provider_reference' => 'pi_replay_idempotent',
        'payload' => [
            'id' => 'evt_replay_idempotent',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_replay_idempotent',
                'object' => 'payment_intent',
                'amount' => 4250,
                'currency' => 'eur',
                'status' => 'succeeded',
                'metadata' => ['order_id' => (string) $order->id],
                'last_payment_error' => null,
            ]],
        ],
        'status' => ProviderEventStatus::Pending,
    ]);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient([
        'id' => 'pi_replay_idempotent',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_replay_idempotent_secret',
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    Artisan::call('app:reconcile-orphaned-payment-attempts');
    // Second run: the attempt is now `claimed` (excluded from candidates)
    // and the unmatched event is `applied` — a no-op on every axis.
    Artisan::call('app:reconcile-orphaned-payment-attempts');

    expect($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($wallet->transactions()->count())
        ->toBe(1)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_replay_idempotent')->count())
        ->toBe(1);
});

it('eventually replays a provider event queued against an already-claimed attempt, e.g. after a crash between the claim commit and its replay', function () {
    // Regression test for the narrower crash window: PaymentService::finalizeAttempt()
    // commits the claim (status=claimed, pending Wallet transaction) and
    // only *then* calls replayUnmatchedEvents() as a separate step. If the
    // process dies (or that call itself throws) in between, the attempt is
    // durably `claimed` but its queued webhook was never replayed — and
    // nothing else re-triggers replay for it, since the original
    // "orphaned pending attempts" query only ever selects `status = pending`.
    // ReconcileOrphanedPaymentAttempts::handle() now has a second candidate
    // set for exactly this: `claimed` attempts with a still-`pending`
    // payment_provider_events row. This test builds that exact DB state
    // directly (bypassing PaymentService, the same way the crash would)
    // and proves a single reconciliation run resolves it — without ever
    // creating a second PaymentAttempt or a second Wallet transaction.
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = createClaimedPaymentAttemptWithPendingWalletTransaction($order, 'pi_stuck_claimed');

    $event = PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_stuck_claimed',
        'event_type' => 'payment_intent.succeeded',
        'provider_reference' => 'pi_stuck_claimed',
        'payload' => [
            'id' => 'evt_stuck_claimed',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_stuck_claimed',
                'object' => 'payment_intent',
                'amount' => 4250,
                'currency' => 'eur',
                'status' => 'succeeded',
                'metadata' => ['order_id' => (string) $order->id],
                'last_payment_error' => null,
            ]],
        ],
        'status' => ProviderEventStatus::Pending,
    ]);
    // Older than the default --stale-after (5 minutes), same as every other
    // orphan fixture in this file — this candidate set filters on the
    // *event's* own age, not the attempt's.
    $event->forceFill(['created_at' => now()->subMinutes(10)])->save();

    Artisan::call('app:reconcile-orphaned-payment-attempts');

    expect(StoreWalletTransaction::where('external_reference', 'pi_stuck_claimed')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_stuck_claimed')->count())
        ->toBe(1)
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($order->fresh()->status->slug)
        ->toBe('paid')
        ->and($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and(PaymentAttempt::where('payment_id', $attempt->payment_id)->count())
        ->toBe(1)
        ->and(PaymentProviderEvent::where('provider_event_id', 'evt_stuck_claimed')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Applied);

    // A later run must not re-apply it or move the balance again — the
    // event is no longer `pending`, so it's outside both candidate queries.
    Artisan::call('app:reconcile-orphaned-payment-attempts');

    expect($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and($wallet->transactions()->count())
        ->toBe(1);
});

it('eventually replays a refund event queued against an attempt already settled to succeeded, not just a claimed one', function () {
    // Generalization proof: the second candidate set must not depend on
    // status = claimed. PaymentEventProcessor::applySucceeded() has the
    // exact same "commit, then a separate replay() call" shape as
    // finalizeAttempt() — it confirms the sale and marks the attempt
    // `succeeded` in one committed transaction, then makes a *separate*
    // nested replay() call to resolve a Refunded event queued ahead of it.
    // If that later call dies or throws (e.g. StripeWebhookController has
    // no try/catch around apply(), so a live-webhook failure here is an
    // uncaught 500 with no retry of its own), the attempt is already
    // `succeeded` — not `claimed` — while its queued refund sits `pending`
    // forever unless something keys off provider_reference instead of one
    // specific status.
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $payment = Payment::create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);
    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'stripe',
        'method' => 'card',
        'provider_reference' => 'pi_succeeded_stuck',
        'idempotency_key' => "payment-{$payment->id}-attempt-seed",
        'status' => PaymentAttemptStatus::Succeeded,
    ]);
    $payment->update(['current_payment_attempt_id' => $attempt->id]);

    // Mirrors the completed sale transaction applySucceeded()'s confirm()
    // would have left behind.
    app(WalletTransactionService::class)->record(
        wallet: $wallet,
        categorySlug: 'sale',
        amount: $order->amount,
        reference: new WalletTransactionReference('stripe', 'pi_succeeded_stuck'),
        options: [
            'referenceable' => $order,
            'source' => TransactionSource::Api,
            'description' => "Order #{$order->id}",
        ],
    );

    $event = PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_refund_stuck',
        'event_type' => 'charge.refunded',
        'provider_reference' => 'pi_succeeded_stuck',
        'payload' => [
            'id' => 'evt_refund_stuck',
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_refund_stuck',
                'object' => 'charge',
                'payment_intent' => 'pi_succeeded_stuck',
                'amount_refunded' => 4250,
                'refunded' => true,
            ]],
        ],
        'status' => ProviderEventStatus::Pending,
    ]);
    $event->forceFill(['created_at' => now()->subMinutes(10)])->save();

    Artisan::call('app:reconcile-orphaned-payment-attempts');

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2)
        ->and($order->fresh()->status->slug)
        ->toBe('refunded')
        ->and($payment->fresh()->status)
        ->toBe(PaymentStatus::Refunded)
        // Refunding never touches the attempt's own status — it settled
        // (succeeded) and stays that way; only Payment/Order reflect the
        // refund.
        ->and($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and(PaymentProviderEvent::where('provider_event_id', 'evt_refund_stuck')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Applied);

    // A later run must not reverse it a second time.
    Artisan::call('app:reconcile-orphaned-payment-attempts');

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);
});

it('leaves a successfully-claimed attempt as `claimed` when its post-claim event replay throws, instead of overwriting it to needs_attention', function () {
    // Regression test: processAttempt()'s catch block used to run a plain
    // `$attempt->update([...])` on any exception from finalizeAttempt() —
    // including one thrown *after* claimProviderReference() already
    // durably committed the claim (status=claimed, pending Wallet
    // transaction) and only the later replayUnmatchedEvents() step failed.
    // That silently downgraded an already-successful claim back to
    // `needs_attention`, even though a real claim + pending Wallet
    // transaction already existed — see
    // ReconcileOrphanedPaymentAttempts::recordRecoveryFailure(), which now
    // guards every write with the same `WHERE status = 'pending'`
    // compare-and-set acquireLease()/markNeedsAttention() already use.
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('15.00')->create();

    app(PaymentProviderManager::class)->extend(
        'fake_claims_then_replay_fails',
        fn () => new FakeTestPaymentProvider('fake_claims_then_replay_fails', new ProviderPaymentResult(
            providerReference: 'poison_ref',
            amountMinorUnits: 1500,
            currency: 'eur',
            providerStatus: 'requires_action',
            correlationId: (string) $order->id,
        )),
    );

    app(ProviderEventTranslatorManager::class)->extend(
        'fake_claims_then_replay_fails',
        fn () => new class implements ProviderEventTranslator
        {
            public function translate(mixed $nativeEvent): ProviderEventOutcome
            {
                throw new RuntimeException('Simulated translation failure during replay.');
            }

            public function reconstructFromReplayPayload(array $payload): mixed
            {
                return $payload;
            }
        },
    );

    $payment = Payment::create(['order_id' => $order->id]);
    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'fake_claims_then_replay_fails',
        'method' => 'wallet',
        'idempotency_key' => "payment-{$payment->id}-attempt-seed",
        'status' => PaymentAttemptStatus::Pending,
    ]);
    $payment->update(['current_payment_attempt_id' => $attempt->id]);
    $attempt->forceFill(['created_at' => now()->subMinutes(10)])->save();

    // Queued ahead of the claim ever existing — same shape as the other
    // "replays a ... event that was queued before this attempt was
    // recovered" tests above, except this event's translator is rigged to
    // blow up once replayUnmatchedEvents() picks it back up.
    PaymentProviderEvent::create([
        'provider' => 'fake_claims_then_replay_fails',
        'provider_event_id' => 'evt_poison',
        'event_type' => 'test.poison',
        'provider_reference' => 'poison_ref',
        'payload' => [],
        'status' => ProviderEventStatus::Pending,
    ]);

    Artisan::call('app:reconcile-orphaned-payment-attempts');

    $fresh = $attempt->fresh();

    expect($fresh->status)
        ->toBe(PaymentAttemptStatus::Claimed)
        ->and($fresh->provider_reference)
        ->toBe('poison_ref')
        ->and($fresh->recovery_attempts)
        ->toBe(0)
        ->and($fresh->last_recovery_error)
        ->toBeNull()
        ->and(StoreWalletTransaction::where('external_reference', 'poison_ref')->exists())
        ->toBeTrue()
        ->and(Artisan::output())
        ->toContain('already progressed past pending');
});

it('processes every stale attempt across multiple chunkById() pages, not just the first page', function () {
    config(['payments.reconciliation_chunk_size' => 2]);

    $store = Store::factory()->create();

    // All older than --max-age: this path never calls Stripe at all, which
    // sidesteps FakeStripeHttpClient's single fixed response body/id — it
    // isolates proving that chunkById() iterates every page of candidates,
    // not just the first, from the (already covered elsewhere) claim logic.
    $attemptIds = collect(range(1, 5))->map(function (int $i) use ($store) {
        $order = Order::factory()->forStore($store)->amount('10.00')->create();

        return createOrphanedPaymentAttempt($order, ageMinutes: 800)->id;
    });

    $fakeClient = new FakeStripeHttpClient(['id' => 'pi_should_not_be_requested']);
    ApiRequestor::setHttpClient($fakeClient);

    Artisan::call('app:reconcile-orphaned-payment-attempts', ['--max-age' => 720]);

    expect($fakeClient->requests)
        ->toHaveCount(0)
        ->and(PaymentAttempt::whereIn('id', $attemptIds)->pluck('status')->unique()->all())
        ->toBe([PaymentAttemptStatus::NeedsAttention]);
});
