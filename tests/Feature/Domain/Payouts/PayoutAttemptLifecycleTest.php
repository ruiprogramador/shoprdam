<?php

use App\Domain\Payouts\DTOs\ProviderTransferResult;
use App\Domain\Payouts\Enums\PayoutAttemptStatus;
use App\Domain\Payouts\Enums\PayoutStatus;
use App\Domain\Payouts\Exceptions\PayoutAlreadyResolvedException;
use App\Domain\Payouts\Exceptions\PayoutAttemptMismatchException;
use App\Domain\Payouts\Exceptions\PayoutHasUnresolvedAttemptException;
use App\Domain\Payouts\Models\Payout;
use App\Domain\Payouts\Models\PayoutAttempt;
use App\Domain\Payouts\PayoutProviderManager;
use App\Domain\Payouts\Services\PayoutService;
use App\Models\Store;
use App\Payouts\Testing\FakePayoutProvider;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Database\QueryException;

function fakeProvider(): FakePayoutProvider
{
    $fake = new FakePayoutProvider;

    app(PayoutProviderManager::class)->extend('fake', fn () => $fake);

    return $fake;
}

function reservedPayout(string $balance = '100.00', string $amount = '80.00'): Payout
{
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', $balance);

    return app(PayoutService::class)->request($store, 'EUR', $amount, 'key-'.$store->id);
}

it('creates a durable attempt and moves the payout to processing', function () {
    $payout = reservedPayout();

    $attempt = app(PayoutService::class)->createDurableAttempt($payout, 'fake');

    expect($attempt->status)->toBe(PayoutAttemptStatus::Pending)
        ->and($attempt->idempotency_key)->toBe("payout-{$payout->id}-attempt-{$attempt->id}")
        ->and($attempt->provider)->toBe('fake')
        ->and($payout->fresh()->status)->toBe(PayoutStatus::Processing)
        ->and($payout->fresh()->current_payout_attempt_id)->toBe($attempt->id);
});

it('resumes the existing non-terminal attempt instead of creating a second one', function () {
    $payout = reservedPayout();
    $service = app(PayoutService::class);

    $first = $service->createDurableAttempt($payout, 'fake');
    $second = $service->createDurableAttempt($payout, 'fake');

    expect($second->id)->toBe($first->id)
        ->and(PayoutAttempt::where('payout_id', $payout->id)->count())->toBe(1);
});

it('refuses to create a new attempt for an already-terminal payout', function () {
    $payout = reservedPayout();
    $payout->update(['status' => PayoutStatus::Succeeded]);

    expect(fn () => app(PayoutService::class)->createDurableAttempt($payout, 'fake'))
        ->toThrow(PayoutAlreadyResolvedException::class);
});

it('allows a new attempt once the current one has resolved to failed', function () {
    $payout = reservedPayout();
    $service = app(PayoutService::class);

    $failedAttempt = $service->createDurableAttempt($payout, 'fake');
    // Simulates the transition PayoutEventProcessor::applyFailed() performs
    // once real evidence arrives — exercised end-to-end in that class's own
    // tests; here we only need attempt-creation eligibility in isolation.
    $failedAttempt->update(['status' => PayoutAttemptStatus::Failed]);
    $payout->update(['status' => PayoutStatus::Reserved]);

    $newAttempt = $service->createDurableAttempt($payout, 'fake');

    expect($newAttempt->id)->not->toBe($failedAttempt->id)
        ->and(PayoutAttempt::where('payout_id', $payout->id)->count())->toBe(2)
        ->and($payout->fresh()->current_payout_attempt_id)->toBe($newAttempt->id)
        ->and($payout->fresh()->status)->toBe(PayoutStatus::Processing);
});

it('claims the provider reference without touching the wallet', function () {
    $payout = reservedPayout();
    $fake = fakeProvider();
    $service = app(PayoutService::class);

    $attempt = $service->createDurableAttempt($payout, 'fake');
    $walletBalanceBefore = $payout->wallet->fresh()->balance;

    $claimed = $service->finalizeAttempt($attempt);

    expect($claimed->status)->toBe(PayoutAttemptStatus::Claimed)
        ->and($claimed->provider_reference)->not->toBeNull()
        ->and($fake->createTransferCalls)->toBe(1)
        ->and($payout->wallet->fresh()->balance)->toBe($walletBalanceBefore);
});

it('does not call the provider again once an attempt is already claimed', function () {
    $payout = reservedPayout();
    $fake = fakeProvider();
    $service = app(PayoutService::class);

    $attempt = $service->finalizeAttempt($service->createDurableAttempt($payout, 'fake'));

    $service->finalizeAttempt($attempt->fresh());

    expect($fake->createTransferCalls)->toBe(1);
});

it('fails closed when the provider result does not match the payout amount/currency/correlation', function () {
    $payout = reservedPayout();
    $fake = fakeProvider();
    $service = app(PayoutService::class);

    $attempt = $service->createDurableAttempt($payout, 'fake');

    $fake->willReturn($attempt, new ProviderTransferResult(
        providerReference: 'mismatched-ref',
        amount: '999.00',
        currency: 'EUR',
        correlationId: (string) $payout->id,
    ));

    expect(fn () => $service->finalizeAttempt($attempt))
        ->toThrow(PayoutAttemptMismatchException::class);
});

it('abandons a payout with no attempt yet and releases the reservation exactly once', function () {
    $payout = reservedPayout(balance: '100.00', amount: '80.00');

    $result = app(PayoutService::class)->abandon($payout, PayoutStatus::Cancelled, 'vendor requested cancellation');

    expect($result->status)->toBe(PayoutStatus::Cancelled)
        ->and($payout->wallet->fresh()->balance)->toBe('100.00');

    $reversal = $payout->debitTransaction->fresh()->childTransactions()->first();
    expect($reversal)->not->toBeNull()
        ->and($reversal->category->slug)->toBe('withdrawal_reversal')
        ->and($reversal->amount)->toBe('80.00');
});

it('abandons a payout whose current attempt already failed definitively', function () {
    $payout = reservedPayout();
    $service = app(PayoutService::class);

    $attempt = $service->createDurableAttempt($payout, 'fake');
    $attempt->update(['status' => PayoutAttemptStatus::Failed]);

    $result = $service->abandon($payout->fresh(), PayoutStatus::Failed, 'manual transfer rejected');

    expect($result->status)->toBe(PayoutStatus::Failed)
        ->and($payout->wallet->fresh()->balance)->toBe('100.00');
});

it('refuses to abandon a payout whose current attempt outcome is still unknown', function () {
    $payout = reservedPayout();
    $service = app(PayoutService::class);

    $service->createDurableAttempt($payout, 'fake'); // stays Pending — outcome unknown

    expect(fn () => $service->abandon($payout->fresh(), PayoutStatus::Cancelled, 'trying anyway'))
        ->toThrow(PayoutHasUnresolvedAttemptException::class);

    expect($payout->wallet->fresh()->balance)->toBe('20.00');
});

it('never reverses the same payout twice', function () {
    $payout = reservedPayout();
    $service = app(PayoutService::class);

    $service->abandon($payout, PayoutStatus::Cancelled, 'first call');
    $service->abandon($payout->fresh(), PayoutStatus::Cancelled, 'second call');

    expect($payout->debitTransaction->fresh()->childTransactions()->count())->toBe(1)
        ->and($payout->wallet->fresh()->balance)->toBe('100.00');
});

it('refuses to delete a PayoutAttempt that a Payout still points to as its current attempt — append-only at the DB level', function () {
    $payout = reservedPayout();
    $attempt = app(PayoutService::class)->createDurableAttempt($payout, 'fake');

    expect($payout->fresh()->current_payout_attempt_id)->toBe($attempt->id);

    expect(fn () => $attempt->delete())->toThrow(QueryException::class);

    expect(PayoutAttempt::find($attempt->id))->not->toBeNull()
        ->and($payout->fresh()->current_payout_attempt_id)->toBe($attempt->id);
});

it('refuses to delete a Payout that still has a PayoutAttempt referencing it — append-only at the DB level', function () {
    $payout = reservedPayout();
    app(PayoutService::class)->createDurableAttempt($payout, 'fake');

    expect(fn () => $payout->delete())->toThrow(QueryException::class);

    expect(Payout::find($payout->id))->not->toBeNull();
});
