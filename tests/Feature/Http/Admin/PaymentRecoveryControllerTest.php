<?php

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Models\PaymentRecoveryAction;
use App\Domain\Payments\Services\PaymentAttemptRecoveryService;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Policies\PaymentRecoveryPolicy;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use Illuminate\Support\Carbon;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Tests\Fakes\FakeStripeHttpClient;

/**
 * Proves App\Http\Controllers\Admin\PaymentRecoveryController stays inside
 * the one safety rule the whole feat/payments-admin-recovery-tools branch
 * exists to uphold: every mutating action is the exact same code path
 * automatic reconciliation already trusts
 * (App\Domain\Payments\Services\PaymentAttemptRecoveryService::recover(),
 * PaymentService::finalizeAttempt()), gated by the `admin` guard, and
 * durably audited — never a shortcut that sets a status or credits the
 * Wallet directly.
 *
 * The three fixture builders below are intentionally its own copies of
 * recoveryOrphanedAttempt() / recoveryLeasedAttempt() /
 * recoveryClaimedAttemptWithPendingWalletTransaction() from
 * tests/Feature/Console/ReconcileOrphanedPaymentAttemptsTest.php (same
 * bodies, distinct names) — Pest loads every Feature test file's top-level
 * functions into one shared global namespace for a full-suite run, so
 * reusing those exact names here would fatal with "cannot redeclare
 * function" the moment both files load together.
 */
afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

function recoveryOrder(string $amount = '20.00'): Order
{
    $store = Store::factory()->create();

    return Order::factory()->forStore($store)->amount($amount)->create();
}

function recoveryOrphanedAttempt(Order $order, int $ageMinutes = 10): PaymentAttempt
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

function recoveryLeasedAttempt(Order $order, Carbon $lockedUntil): PaymentAttempt
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

function recoveryClaimedAttemptWithPendingWalletTransaction(Order $order, string $providerReference): PaymentAttempt
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

// --- Authorization ---

it('rejects a guest from viewing, inspecting, or retrying', function () {
    $order = recoveryOrder();
    $attempt = recoveryOrphanedAttempt($order);

    $this->get(route('admin.payments.recovery.index'))->assertRedirect();
    $this->get(route('admin.payments.recovery.show', $attempt))->assertRedirect();
    $this->post(route('admin.payments.recovery.retry', $attempt))->assertRedirect();

    expect(PaymentRecoveryAction::count())->toBe(0)
        ->and($attempt->fresh()->status)->toBe(PaymentAttemptStatus::Pending);
});

// --- Authorized operator can invoke supported recovery ---

it('lets an authorized admin retry a pending attempt, which claims it via the normal provider call', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryOrphanedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient([
        'id' => 'pi_admin_retry',
        'object' => 'payment_intent',
        'amount' => 2000,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payments.recovery.retry', $attempt))
        ->assertRedirect(route('admin.payments.recovery.show', $attempt));

    $fresh = $attempt->fresh();

    expect($fresh->status)->toBe(PaymentAttemptStatus::Claimed)
        ->and($fresh->provider_reference)->toBe('pi_admin_retry')
        ->and(PaymentRecoveryAction::count())->toBe(1)
        ->and(PaymentRecoveryAction::first()->outcome)->toBe('claimed')
        ->and(PaymentRecoveryAction::first()->action)->toBe('retry_recovery')
        ->and(PaymentRecoveryAction::first()->admin_id)->toBe($admin->id);
});

// --- Double invocation is safe ---

it('is safe to click retry twice — the second click only replays, never re-calls the provider', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryOrphanedAttempt($order);

    $fakeClient = new FakeStripeHttpClient([
        'id' => 'pi_double_click',
        'object' => 'payment_intent',
        'amount' => 2000,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'metadata' => ['order_id' => (string) $order->id],
    ]);
    ApiRequestor::setHttpClient($fakeClient);

    $this->actingAs($admin, 'admin')->post(route('admin.payments.recovery.retry', $attempt))->assertRedirect();
    $this->actingAs($admin, 'admin')->post(route('admin.payments.recovery.retry', $attempt))->assertRedirect();

    expect(PaymentAttempt::count())->toBe(1)
        ->and($fakeClient->requests)->toHaveCount(1)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_double_click')->count())->toBe(1)
        ->and(PaymentRecoveryAction::count())->toBe(2);
});

// --- Concurrent automatic/manual reconciliation is safe ---

it('never calls the provider when another process already holds the reconciliation lease', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryLeasedAttempt($order, now()->addMinutes(10));

    $fakeClient = new FakeStripeHttpClient(['id' => 'pi_should_not_be_called']);
    ApiRequestor::setHttpClient($fakeClient);

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payments.recovery.retry', $attempt))
        ->assertRedirect();

    expect($fakeClient->requests)->toHaveCount(0)
        ->and($attempt->fresh()->status)->toBe(PaymentAttemptStatus::Pending)
        ->and(PaymentRecoveryAction::first()->outcome)->toBe('skipped');
});

// --- Duplicate event replay remains exactly-once ---

it('replaying a queued event twice via the admin action settles it exactly once', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryClaimedAttemptWithPendingWalletTransaction($order, 'pi_replay_dup');
    $wallet = $order->store->wallets()->first();

    PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_replay_dup',
        'event_type' => 'payment_intent.succeeded',
        'provider_reference' => 'pi_replay_dup',
        'payload' => stripePaymentIntentEvent('payment_intent.succeeded', 'pi_replay_dup', ['amount' => 2000]),
        'status' => ProviderEventStatus::Pending,
    ]);

    $this->actingAs($admin, 'admin')->post(route('admin.payments.recovery.retry', $attempt))->assertRedirect();

    expect(PaymentProviderEvent::where('provider_event_id', 'evt_replay_dup')->firstOrFail()->status)
        ->toBe(ProviderEventStatus::Applied)
        ->and($wallet->fresh()->balance)->toBe('20.00');

    // Nothing left pending — a second click must be a safe no-op, not a second credit.
    $this->actingAs($admin, 'admin')->post(route('admin.payments.recovery.retry', $attempt))->assertRedirect();

    expect($wallet->fresh()->balance)->toBe('20.00')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_replay_dup')->count())->toBe(1);
});

// --- Failed recovery leaves durable evidence ---

it('a failed recovery leaves durable evidence on both the attempt and the audit trail', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('42.50');
    $attempt = recoveryOrphanedAttempt($order);

    // 401 maps to Stripe\Exception\AuthenticationException — non-retryable,
    // so this goes straight to needs_attention on the first try.
    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']],
        401,
    ));

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payments.recovery.retry', $attempt))
        ->assertRedirect();

    $fresh = $attempt->fresh();

    expect($fresh->status)->toBe(PaymentAttemptStatus::NeedsAttention)
        ->and($fresh->recovery_attempts)->toBe(1)
        ->and($fresh->last_recovery_error)->not->toBeNull()
        ->and(PaymentRecoveryAction::count())->toBe(1)
        ->and(PaymentRecoveryAction::first()->outcome)->toBe('needs_attention')
        ->and(PaymentRecoveryAction::first()->detail)->not->toBeNull();
});

// --- Successful recovery converges through the normal PaymentEventProcessor path ---

it('an admin-triggered claim never itself credits the Wallet — only the normal webhook settles it', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryOrphanedAttempt($order);
    $wallet = $order->store->wallets()->first();

    ApiRequestor::setHttpClient(new FakeStripeHttpClient([
        'id' => 'pi_converge',
        'object' => 'payment_intent',
        'amount' => 2000,
        'currency' => 'eur',
        'status' => 'requires_payment_method',
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    $this->actingAs($admin, 'admin')->post(route('admin.payments.recovery.retry', $attempt))->assertRedirect();

    expect($attempt->fresh()->status)->toBe(PaymentAttemptStatus::Claimed)
        ->and($wallet->fresh()->balance)->toBe('0.00');

    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_converge', ['amount' => 2000]))
        ->assertOk();

    expect($attempt->fresh()->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->status)->toBe(PaymentStatus::Paid)
        ->and($wallet->fresh()->balance)->toBe('20.00');
});

// --- Wrong provider/reference cannot affect another attempt ---

it('replaying one attempt never touches another attempt sharing the same provider', function () {
    $admin = Admin::factory()->create();
    $orderA = recoveryOrder('10.00');
    $orderB = recoveryOrder('15.00');

    $attemptA = recoveryClaimedAttemptWithPendingWalletTransaction($orderA, 'pi_isolated_a');
    $attemptB = recoveryClaimedAttemptWithPendingWalletTransaction($orderB, 'pi_isolated_b');

    PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_isolated_a',
        'event_type' => 'payment_intent.succeeded',
        'provider_reference' => 'pi_isolated_a',
        'payload' => stripePaymentIntentEvent('payment_intent.succeeded', 'pi_isolated_a', ['amount' => 1000]),
        'status' => ProviderEventStatus::Pending,
    ]);

    $this->actingAs($admin, 'admin')->post(route('admin.payments.recovery.retry', $attemptA))->assertRedirect();

    expect($attemptA->fresh()->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($attemptB->fresh()->status)->toBe(PaymentAttemptStatus::Claimed)
        ->and($orderB->store->wallets()->first()->fresh()->balance)->toBe('0.00');
});

// --- Terminal Payments cannot accidentally reopen ---

it('cannot reopen an already-paid Payment — replaying a succeeded attempt with nothing pending is a safe no-op', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryClaimedAttemptWithPendingWalletTransaction($order, 'pi_terminal');

    postStripeWebhook(stripePaymentIntentEvent('payment_intent.succeeded', 'pi_terminal', ['amount' => 2000]))
        ->assertOk();

    expect(Payment::where('order_id', $order->id)->firstOrFail()->status)->toBe(PaymentStatus::Paid);

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payments.recovery.retry', $attempt))
        ->assertRedirect();

    expect(PaymentAttempt::where('payment_id', $attempt->payment_id)->count())->toBe(1)
        ->and(Payment::where('order_id', $order->id)->firstOrFail()->status)->toBe(PaymentStatus::Paid)
        ->and($order->store->wallets()->first()->fresh()->balance)->toBe('20.00');
});

// --- Fails closed when there is no provider evidence to act on ---

it('fails closed for a needs_attention attempt with no provider claim at all', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryOrphanedAttempt($order);
    $attempt->forceFill(['status' => PaymentAttemptStatus::NeedsAttention])->save();

    $this->actingAs($admin, 'admin')
        ->get(route('admin.payments.recovery.show', $attempt))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_retry', false));

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payments.recovery.retry', $attempt))
        ->assertForbidden();

    expect(PaymentRecoveryAction::count())->toBe(0)
        ->and($attempt->fresh()->status)->toBe(PaymentAttemptStatus::NeedsAttention);
});

it('still fails closed against a network failure — a connection error is retryable, not silently ignored', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryOrphanedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        throws: new ApiConnectionException('Simulated network failure reaching Stripe'),
    ));

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payments.recovery.retry', $attempt))
        ->assertRedirect();

    $fresh = $attempt->fresh();

    expect($fresh->status)->toBe(PaymentAttemptStatus::Pending) // still retryable, not needs_attention
        ->and($fresh->recovery_attempts)->toBe(1)
        ->and(PaymentRecoveryAction::first()->outcome)->toBe('retry_pending');
});

// --- Canary: a secret embedded in a provider exception must never leak anywhere this UI exposes ---

it('never lets a secret embedded in a provider exception message reach the audit row, the flash message, or Inertia props', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryOrphanedAttempt($order);

    $secret = 'CANARY_SECRET_DO_NOT_EXPOSE_51SECRETVALUE0000000000';

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        throws: new RuntimeException("Request failed with key {$secret}"),
    ));

    $response = $this->actingAs($admin, 'admin')
        ->post(route('admin.payments.recovery.retry', $attempt));

    $response->assertRedirect();
    $response->assertSessionHas('error', fn ($message) => ! str_contains($message, $secret));

    $record = PaymentRecoveryAction::first();

    expect($record->detail)->not->toContain($secret)
        // Structured metadata only — provider and exception class, never
        // the message text, regardless of what it contained.
        ->and($record->detail)->toContain('stripe')
        ->and($record->detail)->toContain(RuntimeException::class);

    $show = $this->actingAs($admin, 'admin')->get(route('admin.payments.recovery.show', $attempt));

    $show->assertOk();
    expect($show->getContent())->not->toContain($secret);
});

it('never lets a secret embedded in a stored last_recovery_error reach the inspect page', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryOrphanedAttempt($order);

    $secret = 'whsec_STOREDCANARYSECRET1234567890';
    $attempt->forceFill(['last_recovery_error' => "Webhook verification failed: {$secret}"])->save();

    $show = $this->actingAs($admin, 'admin')->get(route('admin.payments.recovery.show', $attempt));

    $show->assertOk();
    $show->assertInertia(fn ($page) => $page->where('attempt.has_last_recovery_error', true));
    expect($show->getContent())->not->toContain($secret);
});

it('never lets a secret embedded in a pending event\'s last_replay_error reach the inspect page', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryClaimedAttemptWithPendingWalletTransaction($order, 'pi_canary_replay');

    $secret = 'Authorization: Bearer canary-secret-token-xyz';

    PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_canary_replay',
        'event_type' => 'payment_intent.succeeded',
        'provider_reference' => 'pi_canary_replay',
        'payload' => stripePaymentIntentEvent('payment_intent.succeeded', 'pi_canary_replay', ['amount' => 2000]),
        'status' => ProviderEventStatus::Pending,
        'replay_attempts' => 1,
        'last_replay_error' => "Replay failed — {$secret}",
    ]);

    $show = $this->actingAs($admin, 'admin')->get(route('admin.payments.recovery.show', $attempt));

    $show->assertOk();
    $show->assertInertia(fn ($page) => $page->where('pending_events.0.has_last_replay_error', true));
    expect($show->getContent())->not->toContain($secret);
});

// --- Audit retention: evidence must never silently disappear ---

it('refuses to delete a PaymentAttempt that has recovery audit history', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryOrphanedAttempt($order);

    $attempt->recoveryActions()->create([
        'admin_id' => $admin->id,
        'action' => 'retry_recovery',
        'outcome' => 'skipped',
        'detail' => null,
    ]);

    expect(fn () => $attempt->delete())->toThrow(\Illuminate\Database\QueryException::class);

    expect(PaymentAttempt::find($attempt->id))->not->toBeNull()
        ->and(PaymentRecoveryAction::where('payment_attempt_id', $attempt->id)->count())->toBe(1);
});

// --- Crash-durable audit: the "started" row survives even if the operation never finishes ---

it('leaves a durable "started" audit row even if the recovery operation crashes before it can be finished', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryOrphanedAttempt($order);

    // Simulates the process dying (or an unexpected bug) between the audit
    // insert and the provider call actually completing — recover() itself
    // normally never lets an exception escape (it catches Throwable
    // internally), so this forces the one genuine "crash mid-operation"
    // shape: something throws after startAction() already committed.
    $this->mock(PaymentAttemptRecoveryService::class, function ($mock) {
        $mock->shouldReceive('recover')->once()->andThrow(new RuntimeException('simulated crash mid-recovery'));
    });

    $this->withoutExceptionHandling();

    $threw = false;

    try {
        $this->actingAs($admin, 'admin')->post(route('admin.payments.recovery.retry', $attempt));
    } catch (RuntimeException $e) {
        $threw = true;
        expect($e->getMessage())->toBe('simulated crash mid-recovery');
    }

    expect($threw)->toBeTrue('Expected the simulated crash to propagate out of the request.');

    $record = PaymentRecoveryAction::where('payment_attempt_id', $attempt->id)->firstOrFail();

    expect($record->outcome)->toBe('started')
        ->and($record->detail)->toBeNull()
        ->and($record->admin_id)->toBe($admin->id)
        ->and($record->action)->toBe('retry_recovery');
});

// --- Manual recovery must never be looser than automatic reconciliation ---

it('rejects manual recovery of a fresh pending attempt still within the automatic reconciliation window', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryOrphanedAttempt($order, ageMinutes: 1); // fresh — well under the 5-minute stale-after window

    $fakeClient = new FakeStripeHttpClient(['id' => 'pi_should_not_be_called']);
    ApiRequestor::setHttpClient($fakeClient);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.payments.recovery.show', $attempt))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_retry', false));

    $this->actingAs($admin, 'admin')
        ->post(route('admin.payments.recovery.retry', $attempt))
        ->assertForbidden();

    expect($fakeClient->requests)->toHaveCount(0)
        ->and($attempt->fresh()->status)->toBe(PaymentAttemptStatus::Pending)
        ->and($attempt->fresh()->recovery_attempts)->toBe(0)
        ->and($attempt->fresh()->provider_reference)->toBeNull()
        ->and(PaymentRecoveryAction::count())->toBe(0)
        ->and($order->store->wallets()->first()->fresh()->balance)->toBe('0.00');
});

it('allows manual recovery once a pending attempt reaches the stale-after boundary', function () {
    $admin = Admin::factory()->create();
    $order = recoveryOrder('20.00');
    $attempt = recoveryOrphanedAttempt($order, ageMinutes: PaymentRecoveryPolicy::STALE_AFTER_MINUTES);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.payments.recovery.show', $attempt))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_retry', true));
});
