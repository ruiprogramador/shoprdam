<?php

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Artisan;

/**
 * Proves `php artisan payments:health`
 * (App\Domain\Payments\Services\PaymentsHealthCheck) answers the master
 * prompt's operator questions — are payments stuck, which provider, which
 * attempts, are unmatched events accumulating, is reconciliation failing,
 * does this need a human — purely by reading, and that a single ordinary
 * retry/pending state is never itself reported as unhealthy. This is a
 * READ/OBSERVE branch: nothing here exercises a mutation path, and the
 * "never mutates" tests below are the actual point of the suite, not an
 * afterthought.
 */
function healthOrder(): Order
{
    $store = Store::factory()->create();

    return Order::factory()->forStore($store)->amount('20.00')->create();
}

function healthPayment(): Payment
{
    return Payment::create(['order_id' => healthOrder()->id, 'status' => PaymentStatus::Pending]);
}

function healthAttempt(array $overrides = []): PaymentAttempt
{
    $payment = $overrides['payment'] ?? healthPayment();

    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => $overrides['provider'] ?? 'stripe',
        'method' => $overrides['method'] ?? 'card',
        'provider_reference' => $overrides['provider_reference'] ?? null,
        'idempotency_key' => $overrides['idempotency_key'] ?? 'k_'.str()->random(16),
        'status' => $overrides['status'] ?? PaymentAttemptStatus::Pending,
        'recovery_attempts' => $overrides['recovery_attempts'] ?? 0,
        'last_recovery_error' => $overrides['last_recovery_error'] ?? null,
    ]);

    $fill = [];

    foreach (['created_at', 'locked_until', 'last_attempted_at'] as $field) {
        if (array_key_exists($field, $overrides)) {
            $fill[$field] = $overrides[$field];
        }
    }

    if ($fill !== []) {
        $attempt->forceFill($fill)->save();
    }

    return $attempt->fresh();
}

function healthProviderEvent(array $overrides = []): PaymentProviderEvent
{
    $event = PaymentProviderEvent::create([
        'provider' => $overrides['provider'] ?? 'stripe',
        'provider_event_id' => $overrides['provider_event_id'] ?? 'evt_'.str()->random(16),
        'event_type' => $overrides['event_type'] ?? 'payment_intent.succeeded',
        'provider_reference' => $overrides['provider_reference'] ?? 'pi_'.str()->random(16),
        'payload' => $overrides['payload'] ?? ['id' => 'pi_test'],
        'status' => $overrides['status'] ?? ProviderEventStatus::Pending,
        'replay_attempts' => $overrides['replay_attempts'] ?? 0,
        'last_replay_error' => $overrides['last_replay_error'] ?? null,
    ]);

    $fill = [];

    foreach (['created_at', 'processed_at'] as $field) {
        if (array_key_exists($field, $overrides)) {
            $fill[$field] = $overrides[$field];
        }
    }

    if ($fill !== []) {
        $event->forceFill($fill)->save();
    }

    return $event->fresh();
}

function runHealthJson(): array
{
    Artisan::call('payments:health', ['--json' => true]);

    return json_decode(Artisan::output(), true);
}

// --- Healthy state ---

it('reports healthy with exit code 0 when nothing is stuck', function () {
    $exitCode = Artisan::call('payments:health', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($report['healthy'])->toBeTrue()
        ->and($report['needs_attention']['count'])->toBe(0)
        ->and($report['stale_pending_attempts']['count'])->toBe(0)
        ->and($report['stale_reconciliation_leases']['count'])->toBe(0)
        ->and($report['stale_provider_events']['count'])->toBe(0)
        ->and($report['repeated_replay_failures']['count'])->toBe(0);
});

it('reports a fresh pending attempt and a fresh pending event as healthy, not stuck', function () {
    healthAttempt(['status' => PaymentAttemptStatus::Pending, 'created_at' => now()]);
    healthProviderEvent(['status' => ProviderEventStatus::Pending, 'created_at' => now()]);

    $report = runHealthJson();

    expect($report['healthy'])->toBeTrue()
        ->and($report['stale_pending_attempts']['count'])->toBe(0)
        ->and($report['pending_provider_events']['count'])->toBe(1) // informational, not actionable
        ->and($report['stale_provider_events']['count'])->toBe(0);
});

// --- NeedsAttention ---

it('detects a NeedsAttention attempt, grouped by provider, and reports unhealthy with exit code 1', function () {
    healthAttempt(['provider' => 'stripe', 'status' => PaymentAttemptStatus::NeedsAttention]);

    $exitCode = Artisan::call('payments:health', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and($report['healthy'])->toBeFalse()
        ->and($report['needs_attention']['count'])->toBe(1)
        ->and($report['needs_attention']['by_provider'])->toBe(['stripe' => 1])
        ->and($report['needs_attention']['sample'][0]['provider'])->toBe('stripe');
});

// --- Stale pending attempts ---

it('flags a stale pending attempt but not one still within the normal reconciliation window', function () {
    healthAttempt(['provider' => 'easypay', 'status' => PaymentAttemptStatus::Pending, 'created_at' => now()->subMinutes(20)]);
    healthAttempt(['provider' => 'stripe', 'status' => PaymentAttemptStatus::Pending, 'created_at' => now()->subMinutes(2)]);

    $report = runHealthJson();

    expect($report['stale_pending_attempts']['count'])->toBe(1)
        ->and($report['stale_pending_attempts']['by_provider'])->toBe(['easypay' => 1])
        ->and($report['healthy'])->toBeFalse();
});

// --- Stale reconciliation leases ---

it('flags an attempt whose reconciliation lease has been outstanding far longer than normal', function () {
    healthAttempt([
        'provider' => 'easypay',
        'status' => PaymentAttemptStatus::Pending,
        'locked_until' => now()->subMinutes(10),
        'last_attempted_at' => now()->subMinutes(40),
    ]);

    // A lease acquired moments ago must never be flagged, even though it is
    // technically the same "pending + locked_until set" shape.
    healthAttempt([
        'provider' => 'stripe',
        'status' => PaymentAttemptStatus::Pending,
        'locked_until' => now()->addMinutes(10),
        'last_attempted_at' => now()->subMinutes(1),
    ]);

    $report = runHealthJson();

    expect($report['stale_reconciliation_leases']['count'])->toBe(1)
        ->and($report['stale_reconciliation_leases']['by_provider'])->toBe(['easypay' => 1]);
});

// --- Provider events: pending, stale, oldest age ---

it('reports pending provider events and their oldest age, distinguishing stale from fresh', function () {
    healthProviderEvent(['provider' => 'stripe', 'created_at' => now()->subMinutes(20)]);
    healthProviderEvent(['provider' => 'easypay', 'created_at' => now()->subMinutes(1)]);

    $report = runHealthJson();

    expect($report['pending_provider_events']['count'])->toBe(2)
        ->and($report['pending_provider_events']['oldest_age_minutes'])->toBeGreaterThanOrEqual(20)
        ->and($report['stale_provider_events']['count'])->toBe(1)
        ->and($report['stale_provider_events']['by_provider'])->toBe(['stripe' => 1]);
});

it('reports no pending events and a null oldest age when the inbox is empty', function () {
    $report = runHealthJson();

    expect($report['pending_provider_events']['count'])->toBe(0)
        ->and($report['pending_provider_events']['oldest_age_minutes'])->toBeNull();
});

// --- Repeated replay failures ---

it('flags an event with repeated replay failures but not one with a single normal retry', function () {
    healthProviderEvent(['provider' => 'stripe', 'replay_attempts' => 3, 'last_replay_error' => 'boom']);
    healthProviderEvent(['provider' => 'easypay', 'replay_attempts' => 1]);

    $report = runHealthJson();

    expect($report['repeated_replay_failures']['count'])->toBe(1)
        ->and($report['repeated_replay_failures']['by_provider'])->toBe(['stripe' => 1])
        ->and($report['repeated_replay_failures']['sample'][0]['last_replay_error'])->toBe('boom');
});

// --- Pruning preview (informational only, never deletes) ---

it('reports events eligible for pruning without ever deleting them', function () {
    $event = healthProviderEvent([
        'status' => ProviderEventStatus::Applied,
        'processed_at' => now()->subDays(120),
    ]);

    $report = runHealthJson();

    expect($report['events_eligible_for_pruning'])->toBe(1)
        ->and(PaymentProviderEvent::find($event->id))->not->toBeNull();
});

// --- Recovery failures: informational, never alone unhealthy ---

it('counts attempts still retrying after a recorded recovery failure as informational only', function () {
    healthAttempt([
        'status' => PaymentAttemptStatus::Pending,
        'recovery_attempts' => 1,
        'last_recovery_error' => 'transient network error',
        'created_at' => now()->subMinutes(2), // not stale — still well within the normal window
    ]);

    $report = runHealthJson();

    expect($report['recovery_failure_count'])->toBe(1)
        ->and($report['healthy'])->toBeTrue();
});

// --- Multiple providers distinguishable ---

it('distinguishes multiple providers in the overall distribution', function () {
    healthAttempt(['provider' => 'stripe', 'status' => PaymentAttemptStatus::Succeeded]);
    healthAttempt(['provider' => 'easypay', 'status' => PaymentAttemptStatus::Failed]);

    $report = runHealthJson();

    expect($report['provider_distribution']['stripe']['succeeded'])->toBe(1)
        ->and($report['provider_distribution']['easypay']['failed'])->toBe(1);
});

// --- Read-only guarantee ---

it('never mutates any payment record, in either human or --json output mode', function () {
    $needsAttention = healthAttempt(['status' => PaymentAttemptStatus::NeedsAttention]);
    $stalePending = healthAttempt(['status' => PaymentAttemptStatus::Pending, 'created_at' => now()->subMinutes(30)]);
    $staleLease = healthAttempt([
        'status' => PaymentAttemptStatus::Pending,
        'locked_until' => now()->subMinutes(5),
        'last_attempted_at' => now()->subMinutes(45),
        'recovery_attempts' => 2,
    ]);
    $repeatedFailureEvent = healthProviderEvent(['replay_attempts' => 5, 'last_replay_error' => 'still failing']);
    $eligibleForPruning = healthProviderEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(200)]);

    $snapshot = fn () => [
        $needsAttention->fresh()->getAttributes(),
        $stalePending->fresh()->getAttributes(),
        $staleLease->fresh()->getAttributes(),
        $repeatedFailureEvent->fresh()->getAttributes(),
        $eligibleForPruning->fresh()->getAttributes(),
    ];

    $before = $snapshot();
    $attemptCountBefore = PaymentAttempt::count();
    $eventCountBefore = PaymentProviderEvent::count();

    Artisan::call('payments:health');
    Artisan::call('payments:health', ['--json' => true]);

    expect($snapshot())->toEqual($before)
        ->and(PaymentAttempt::count())->toBe($attemptCountBefore)
        ->and(PaymentProviderEvent::count())->toBe($eventCountBefore);
});

// --- No sensitive/raw payload content ever surfaces ---

it('never surfaces a provider event\'s raw payload content in its output', function () {
    healthProviderEvent(['payload' => ['secret_marker' => 'DO-NOT-LEAK-CANARY-XYZ']]);

    Artisan::call('payments:health', ['--json' => true]);

    expect(Artisan::output())->not->toContain('DO-NOT-LEAK-CANARY-XYZ');
});
