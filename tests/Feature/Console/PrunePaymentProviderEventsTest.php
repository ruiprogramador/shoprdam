<?php

use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Models\PaymentProviderEvent;
use Illuminate\Support\Facades\Artisan;

/**
 * Proves App\Console\Commands\PrunePaymentProviderEvents only ever deletes
 * rows that have both left the replay inbox for good (`applied`) and aged
 * past retention, anchored on `processed_at` — never a `pending` row
 * (regardless of age or replay_attempts), and never an `applied` row with a
 * null `processed_at` (fail-safe rather than guessing at an anchor). See
 * docs/wallet/integrations.md and config/payments.php.
 */
function providerEvent(array $overrides = []): PaymentProviderEvent
{
    $event = PaymentProviderEvent::create([
        'provider' => $overrides['provider'] ?? 'stripe',
        'provider_event_id' => $overrides['provider_event_id'] ?? 'evt_'.str()->random(12),
        'event_type' => $overrides['event_type'] ?? 'payment_intent.succeeded',
        'provider_reference' => $overrides['provider_reference'] ?? 'pi_'.str()->random(12),
        'payload' => $overrides['payload'] ?? ['id' => 'pi_test'],
        'status' => $overrides['status'] ?? ProviderEventStatus::Pending,
        'replay_attempts' => $overrides['replay_attempts'] ?? 0,
        'last_replay_error' => $overrides['last_replay_error'] ?? null,
    ]);

    $fill = [];

    if (array_key_exists('created_at', $overrides)) {
        $fill['created_at'] = $overrides['created_at'];
    }

    if (array_key_exists('processed_at', $overrides)) {
        $fill['processed_at'] = $overrides['processed_at'];
    }

    if ($fill !== []) {
        $event->forceFill($fill)->save();
    }

    return $event->fresh();
}

it('prunes an old applied event past the retention cutoff', function () {
    $event = providerEvent([
        'status' => ProviderEventStatus::Applied,
        'processed_at' => now()->subDays(120),
    ]);

    Artisan::call('app:prune-payment-provider-events');

    expect(PaymentProviderEvent::find($event->id))->toBeNull();
});

it('preserves a recently applied event still inside the retention window', function () {
    $event = providerEvent([
        'status' => ProviderEventStatus::Applied,
        'processed_at' => now()->subDays(5),
    ]);

    Artisan::call('app:prune-payment-provider-events');

    expect(PaymentProviderEvent::find($event->id))->not->toBeNull();
});

it('never prunes a pending event regardless of age', function () {
    $event = providerEvent([
        'status' => ProviderEventStatus::Pending,
        'created_at' => now()->subDays(400),
    ]);

    Artisan::call('app:prune-payment-provider-events');

    expect(PaymentProviderEvent::find($event->id))->not->toBeNull();
});

it('never prunes an old pending event that has recorded replay errors', function () {
    $event = providerEvent([
        'status' => ProviderEventStatus::Pending,
        'created_at' => now()->subDays(400),
        'replay_attempts' => 7,
        'last_replay_error' => 'still waiting on the sale to confirm',
    ]);

    Artisan::call('app:prune-payment-provider-events');

    $fresh = PaymentProviderEvent::find($event->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(ProviderEventStatus::Pending)
        ->and($fresh->replay_attempts)->toBe(7);
});

it('never prunes an applied event with a null processed_at, failing safe instead of guessing an anchor', function () {
    // Not reachable through PaymentEventProcessor (it always sets
    // processed_at when marking a row applied), but the predicate itself
    // must not silently treat a missing anchor as eligible.
    $event = PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_no_processed_at',
        'event_type' => 'payment_intent.succeeded',
        'provider_reference' => 'pi_no_processed_at',
        'payload' => [],
        'status' => ProviderEventStatus::Applied,
    ]);
    $event->forceFill(['created_at' => now()->subDays(400)])->save();

    expect($event->fresh()->processed_at)->toBeNull();

    Artisan::call('app:prune-payment-provider-events');

    expect(PaymentProviderEvent::find($event->id))->not->toBeNull();
});

it('is safe to run repeatedly, deleting nothing further on a second pass', function () {
    providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)]);
    $preserved = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(5)]);

    Artisan::call('app:prune-payment-provider-events');
    expect(PaymentProviderEvent::count())->toBe(1);

    Artisan::call('app:prune-payment-provider-events');

    expect(PaymentProviderEvent::count())
        ->toBe(1)
        ->and(PaymentProviderEvent::find($preserved->id))
        ->not->toBeNull();
});

it('prunes eligible events across multiple providers without provider-specific logic', function () {
    $stripeOld = providerEvent(['provider' => 'stripe', 'status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)]);
    $easypayOld = providerEvent(['provider' => 'easypay', 'status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)]);
    $stripeRecent = providerEvent(['provider' => 'stripe', 'status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(5)]);

    Artisan::call('app:prune-payment-provider-events');

    expect(PaymentProviderEvent::find($stripeOld->id))->toBeNull()
        ->and(PaymentProviderEvent::find($easypayOld->id))->toBeNull()
        ->and(PaymentProviderEvent::find($stripeRecent->id))->not->toBeNull();
});

it('deletes in chunks across multiple batches without leaving eligible rows behind', function () {
    config(['payments.provider_event_prune_chunk_size' => 2]);

    $eligible = collect(range(1, 7))->map(
        fn () => providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)])
    );
    $preserved = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(1)]);

    Artisan::call('app:prune-payment-provider-events');

    expect(PaymentProviderEvent::whereIn('id', $eligible->pluck('id'))->count())
        ->toBe(0)
        ->and(PaymentProviderEvent::find($preserved->id))
        ->not->toBeNull();
});

it('respects a configured retention period shorter than the default via --days', function () {
    $event = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(10)]);

    Artisan::call('app:prune-payment-provider-events', ['--days' => 5]);

    expect(PaymentProviderEvent::find($event->id))->toBeNull();
});

it('reports how many rows were pruned', function () {
    providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)]);
    providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)]);

    Artisan::call('app:prune-payment-provider-events');

    expect(Artisan::output())->toContain('Pruned 2');
});

it('rejects a negative --days option instead of silently misbehaving', function () {
    $preserved = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)]);

    $exitCode = Artisan::call('app:prune-payment-provider-events', ['--days' => -1]);

    expect($exitCode)->toBe(Illuminate\Console\Command::INVALID)
        ->and(PaymentProviderEvent::find($preserved->id))
        ->not->toBeNull();
});

// --- Retention parsing must fail closed, never coerce via (int), never delete on invalid input ---

it('rejects a non-numeric --days value instead of silently coercing it to zero', function () {
    // (int) 'abc' === 0 — if this command trusted that cast, --days=abc
    // would be silently treated as "prune everything currently eligible"
    // instead of refusing to run.
    $preserved = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)]);

    $exitCode = Artisan::call('app:prune-payment-provider-events', ['--days' => 'abc']);

    expect($exitCode)->toBe(Illuminate\Console\Command::INVALID)
        ->and(Artisan::output())->toContain('Invalid retention value for --days')
        ->and(PaymentProviderEvent::find($preserved->id))->not->toBeNull()
        ->and(PaymentProviderEvent::count())->toBe(1);
});

it('rejects a non-integer decimal --days value instead of truncating it', function () {
    $preserved = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)]);

    $exitCode = Artisan::call('app:prune-payment-provider-events', ['--days' => '3.5']);

    expect($exitCode)->toBe(Illuminate\Console\Command::INVALID)
        ->and(PaymentProviderEvent::find($preserved->id))->not->toBeNull()
        ->and(PaymentProviderEvent::count())->toBe(1);
});

it('rejects an empty --days value instead of coercing it to zero', function () {
    $preserved = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)]);

    $exitCode = Artisan::call('app:prune-payment-provider-events', ['--days' => '']);

    expect($exitCode)->toBe(Illuminate\Console\Command::INVALID)
        ->and(PaymentProviderEvent::find($preserved->id))->not->toBeNull()
        ->and(PaymentProviderEvent::count())->toBe(1);
});

it('never falls back to the configured retention value when --days was given but is invalid', function () {
    config(['payments.provider_event_retention_days' => 1]);
    $preserved = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)]);

    $exitCode = Artisan::call('app:prune-payment-provider-events', ['--days' => 'abc']);

    expect($exitCode)->toBe(Illuminate\Console\Command::INVALID)
        ->and(PaymentProviderEvent::find($preserved->id))
        ->not->toBeNull();
});

it('rejects a malformed configured retention value and deletes nothing, without --days given', function () {
    config(['payments.provider_event_retention_days' => 'abc']);
    $preserved = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)]);

    $exitCode = Artisan::call('app:prune-payment-provider-events');

    expect($exitCode)->toBe(Illuminate\Console\Command::INVALID)
        ->and(Artisan::output())->toContain('payments.provider_event_retention_days')
        ->and(PaymentProviderEvent::find($preserved->id))
        ->not->toBeNull();
});

it('rejects a negative configured retention value and deletes nothing, without --days given', function () {
    config(['payments.provider_event_retention_days' => '-5']);
    $preserved = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(120)]);

    $exitCode = Artisan::call('app:prune-payment-provider-events');

    expect($exitCode)->toBe(Illuminate\Console\Command::INVALID)
        ->and(PaymentProviderEvent::find($preserved->id))
        ->not->toBeNull();
});

it('still resolves a valid string configured retention value correctly (e.g. from env())', function () {
    config(['payments.provider_event_retention_days' => '30']);

    $old = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(45)]);
    $recent = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subDays(10)]);

    Artisan::call('app:prune-payment-provider-events');

    expect(PaymentProviderEvent::find($old->id))->toBeNull()
        ->and(PaymentProviderEvent::find($recent->id))->not->toBeNull();
});

it('accepts --days=0 as a valid, intentional zero-retention value and prunes only currently-eligible rows', function () {
    $eligibleNow = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->subSecond()]);
    $pending = providerEvent(['status' => ProviderEventStatus::Pending]);
    $futureProcessed = providerEvent(['status' => ProviderEventStatus::Applied, 'processed_at' => now()->addDay()]);

    $exitCode = Artisan::call('app:prune-payment-provider-events', ['--days' => 0]);

    expect($exitCode)->toBe(Illuminate\Console\Command::SUCCESS)
        ->and(PaymentProviderEvent::find($eligibleNow->id))->toBeNull()
        ->and(PaymentProviderEvent::find($pending->id))->not->toBeNull()
        ->and(PaymentProviderEvent::find($futureProcessed->id))->not->toBeNull();
});
