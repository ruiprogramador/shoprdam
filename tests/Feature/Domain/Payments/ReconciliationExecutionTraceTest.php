<?php

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Services\PaymentAttemptRecoveryService;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Support\Facades\Artisan;
use Stripe\ApiRequestor;
use Tests\Fakes\FakeStripeHttpClient;

/**
 * Encodes docs/financial/RECONCILIATION.md §3's execution traces as
 * regression tests, per §17's explicit requirement — proof, not prose, that
 * the automatic-action matrix (§14) is evidenced rather than assumed. These
 * tests exercise the EXISTING, unmodified
 * App\Domain\Payments\Services\PaymentAttemptRecoveryService,
 * App\Domain\Payments\Services\PaymentService, and
 * App\Console\Commands\ReconcileOrphanedPaymentAttempts — nothing in this
 * file changes any of them. If any of these ever fails, it means a later
 * change to those classes has changed the trace §3 documented, and
 * docs/financial/RECONCILIATION.md's §8/§14 classifications must be
 * re-reviewed before this feature's behavior can still be trusted — not
 * that this test should be "fixed" to match new behavior.
 */
afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

function traceClaimedAttemptWithPendingWalletTransaction(Order $order, string $providerReference): PaymentAttempt
{
    $payment = Payment::firstOrCreate(['order_id' => $order->id]);

    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'stripe',
        'method' => 'card',
        'provider_reference' => $providerReference,
        'idempotency_key' => "payment-{$payment->id}-attempt-trace",
        'status' => PaymentAttemptStatus::Claimed,
    ]);
    $payment->update(['current_payment_attempt_id' => $attempt->id]);

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

it('§3.A — recover() advances a Pending attempt to Claimed with a PENDING wallet transaction, and does NOT settle it, when the provider already succeeded but no webhook was ever delivered', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $payment = Payment::create(['order_id' => $order->id]);
    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'stripe',
        'method' => 'card',
        'idempotency_key' => "payment-{$payment->id}-attempt-trace-a",
        'status' => PaymentAttemptStatus::Pending,
    ]);
    $payment->update(['current_payment_attempt_id' => $attempt->id]);

    // The provider already has a succeeded PaymentIntent under this
    // attempt's own idempotency key — simulating "reconciliation would see
    // this as succeeded" — but no webhook has ever been delivered for it
    // (no PaymentProviderEvent row exists at all).
    ApiRequestor::setHttpClient(new FakeStripeHttpClient([
        'id' => 'pi_trace_a_already_succeeded',
        'object' => 'payment_intent',
        'amount' => 4250,
        'currency' => 'eur',
        'status' => 'succeeded',
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    app(PaymentAttemptRecoveryService::class)->recover($attempt, maxAttempts: 5, maxAge: 720, leaseTimeout: 15);

    $fresh = $attempt->fresh();

    expect($fresh->status)
        ->toBe(PaymentAttemptStatus::Claimed)
        ->and($fresh->provider_reference)
        ->toBe('pi_trace_a_already_succeeded')
        // The load-bearing assertion: the sale transaction is PENDING, not
        // completed — recover() never confirms it.
        ->and(StoreWalletTransaction::where('external_reference', 'pi_trace_a_already_succeeded')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($payment->fresh()->status)
        ->toBe(PaymentStatus::Pending)
        ->and($order->fresh()->status->slug)
        ->toBe('pending');
});

it('§3.B — finalizeAttempt() is a pure no-op for an already-Claimed attempt with no stored provider event, and never calls the provider again', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = traceClaimedAttemptWithPendingWalletTransaction($order, 'pi_trace_b_stuck');

    // If finalizeAttempt() called the provider at all here (it must not —
    // provider_reference is already set, so claimProviderReference() must
    // be skipped entirely), this throws and the test fails loudly.
    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        throws: new RuntimeException('finalizeAttempt() must not call the provider for an already-claimed attempt.'),
    ));

    $result = app(PaymentService::class)->finalizeAttempt($attempt);

    expect($result->status)
        ->toBe(PaymentAttemptStatus::Claimed)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_trace_b_stuck')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});

it('§3.D — the EXISTING, unmodified ReconcileOrphanedPaymentAttempts scheduled command deterministically settles a Claimed attempt once its matching pending provider event ages past --stale-after, proving RemoteSucceededAwaitingReplay genuinely self-resolves', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = traceClaimedAttemptWithPendingWalletTransaction($order, 'pi_trace_d_awaiting_replay');

    $event = PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_trace_d',
        'event_type' => 'payment_intent.succeeded',
        'provider_reference' => 'pi_trace_d_awaiting_replay',
        'payload' => [
            'id' => 'evt_trace_d',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_trace_d_awaiting_replay',
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
    // Aged past the default --stale-after (5 minutes) — the exact condition
    // candidate set 2's `whereExists` predicate requires (§3.D).
    $event->forceFill(['created_at' => now()->subMinutes(10)])->save();

    // No provider call is expected on this path at all (finalizeAttempt()
    // skips claimProviderReference() for an already-claimed attempt) — a
    // throwing client proves that if it's ever reached, this test fails
    // loudly rather than silently passing for the wrong reason.
    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        throws: new RuntimeException('This trace must never call the provider — see §3.D.'),
    ));

    // The real, existing, unmodified command — never a reconciliation
    // finding's own code.
    Artisan::call('app:reconcile-orphaned-payment-attempts');

    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_trace_d_awaiting_replay')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->status)
        ->toBe(PaymentStatus::Paid)
        ->and($order->fresh()->status->slug)
        ->toBe('paid')
        ->and($event->fresh()->status)
        ->toBe(ProviderEventStatus::Applied);
});

it('§3.D counter-check — the same event NOT yet aged past --stale-after is left untouched, proving convergence is bounded, not immediate', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();

    $attempt = traceClaimedAttemptWithPendingWalletTransaction($order, 'pi_trace_d_too_fresh');

    PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_trace_d_too_fresh',
        'event_type' => 'payment_intent.succeeded',
        'provider_reference' => 'pi_trace_d_too_fresh',
        'payload' => [
            'id' => 'evt_trace_d_too_fresh',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_trace_d_too_fresh',
                'object' => 'payment_intent',
                'amount' => 4250,
                'currency' => 'eur',
                'status' => 'succeeded',
                'metadata' => ['order_id' => (string) $order->id],
                'last_payment_error' => null,
            ]],
        ],
        'status' => ProviderEventStatus::Pending,
        // Not aged past --stale-after — deliberately fresh.
    ]);

    Artisan::call('app:reconcile-orphaned-payment-attempts');

    expect($attempt->fresh()->status)
        ->toBe(PaymentAttemptStatus::Claimed)
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});
