<?php

use App\Domain\Payouts\Enums\PayoutAttemptStatus;
use App\Domain\Payouts\Exceptions\PayoutAttemptMismatchException;
use App\Domain\Payouts\Models\PayoutAttempt;
use App\Domain\Payouts\PayoutProviderManager;
use App\Domain\Payouts\Services\PayoutService;
use App\Models\Store;
use App\Payouts\Testing\FakePayoutProvider;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;

function fakeCliProvider(): FakePayoutProvider
{
    $fake = new FakePayoutProvider;

    app(PayoutProviderManager::class)->extend('fake-cli', fn () => $fake);

    return $fake;
}

function orphanedPayoutAttempt(int $ageMinutes = 10, ?FakePayoutProvider $fake = null): PayoutAttempt
{
    $fake ??= fakeCliProvider();

    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', '100.00');

    $payout = app(PayoutService::class)->request($store, 'EUR', '80.00', 'key-'.$store->id);
    $attempt = app(PayoutService::class)->createDurableAttempt($payout, 'fake-cli');

    // Never claimed — simulates a crash between createDurableAttempt() and finalizeAttempt().
    $attempt->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

    return $attempt;
}

it('claims a stale pending attempt via the normal provider call', function () {
    $attempt = orphanedPayoutAttempt();

    $this->artisan('app:reconcile-orphaned-payout-attempts')->assertExitCode(0);

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::Claimed)
        ->and($attempt->fresh()->provider_reference)->not->toBeNull();
});

it('ignores an attempt still within the stale-after window', function () {
    $attempt = orphanedPayoutAttempt(ageMinutes: 1);

    $this->artisan('app:reconcile-orphaned-payout-attempts')->assertExitCode(0);

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::Pending);
});

it('never calls the provider for an attempt whose lease is still held by another worker', function () {
    $fake = fakeCliProvider();
    $attempt = orphanedPayoutAttempt(fake: $fake);
    $attempt->update(['locked_until' => now()->addMinutes(10)]);

    $this->artisan('app:reconcile-orphaned-payout-attempts')->assertExitCode(0);

    expect($fake->createTransferCalls)->toBe(0)
        ->and($attempt->fresh()->status)->toBe(PayoutAttemptStatus::Pending);
});

it('marks an attempt needs_attention once it exceeds max-age, without ever calling the provider', function () {
    $fake = fakeCliProvider();
    $attempt = orphanedPayoutAttempt(ageMinutes: 800, fake: $fake);

    $this->artisan('app:reconcile-orphaned-payout-attempts', ['--max-age' => 720])->assertExitCode(0);

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::NeedsAttention)
        ->and($fake->createTransferCalls)->toBe(0);
});

it('marks an attempt needs_attention after a non-retryable failure, never leaving it silently pending', function () {
    $fake = fakeCliProvider();
    $attempt = orphanedPayoutAttempt(fake: $fake);
    $fake->willThrow(new PayoutAttemptMismatchException('mismatch'));

    $this->artisan('app:reconcile-orphaned-payout-attempts')->assertExitCode(0);

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::NeedsAttention)
        ->and($attempt->fresh()->last_recovery_error)->not->toBeNull();
});

it('is safe to run twice — a claimed attempt is never re-processed', function () {
    $attempt = orphanedPayoutAttempt();

    $this->artisan('app:reconcile-orphaned-payout-attempts')->assertExitCode(0);
    $reference = $attempt->fresh()->provider_reference;

    $this->artisan('app:reconcile-orphaned-payout-attempts')->assertExitCode(0);

    expect($attempt->fresh()->provider_reference)->toBe($reference);
});

it('rejects invalid option values instead of silently defaulting', function () {
    $this->artisan('app:reconcile-orphaned-payout-attempts', ['--max-attempts' => 'abc'])
        ->assertExitCode(2);
});
