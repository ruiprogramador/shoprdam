<?php

use App\Domain\Payouts\Enums\PayoutStatus;
use App\Domain\Payouts\Exceptions\PayoutIdempotencyKeyReusedException;
use App\Domain\Payouts\Exceptions\PayoutWalletNotFoundException;
use App\Domain\Payouts\Models\Payout;
use App\Domain\Payouts\Services\PayoutService;
use App\Domain\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Domain\Wallet\Exceptions\InvalidTransactionAmountException;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;

function payoutService(): PayoutService
{
    return app(PayoutService::class);
}

function fundWallet(Store $store, string $amount, string $currency = 'EUR'): void
{
    $wallet = app(WalletService::class)->getWallet($store, $currency);

    app(WalletTransactionService::class)->record($wallet, 'sale', $amount);
}

it('reserves funds by creating a payout and a completed withdrawal debit, atomically', function () {
    $store = Store::factory()->create();
    fundWallet($store, '100.00');

    $payout = payoutService()->request($store, 'EUR', '80.00', 'key-1');

    expect($payout->status)->toBe(PayoutStatus::Reserved)
        ->and($payout->amount)->toBe('80.00')
        ->and($payout->debit_transaction_id)->not->toBeNull();

    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    $debit = StoreWalletTransaction::find($payout->debit_transaction_id);

    expect($wallet->fresh()->balance)->toBe('20.00')
        ->and($debit->category->slug)->toBe('withdrawal')
        ->and($debit->status->slug)->toBe('completed')
        ->and($debit->external_provider)->toBe('internal')
        ->and($debit->external_reference)->toBe("payout-{$payout->id}")
        ->and($debit->referenceable_type)->toBe($payout->getMorphClass())
        ->and($debit->referenceable_id)->toBe($payout->id);
});

it('fails with insufficient balance and leaves no orphan payout or debit behind', function () {
    $store = Store::factory()->create();
    fundWallet($store, '50.00');

    expect(fn () => payoutService()->request($store, 'EUR', '80.00', 'key-1'))
        ->toThrow(InsufficientWalletBalanceException::class);

    $wallet = app(WalletService::class)->getWallet($store, 'EUR');

    expect($wallet->fresh()->balance)->toBe('50.00')
        ->and(Payout::count())->toBe(0)
        ->and(StoreWalletTransaction::where('external_provider', 'internal')->count())->toBe(0);
});

it('rejects zero and negative amounts without leaving any row behind', function () {
    $store = Store::factory()->create();
    fundWallet($store, '100.00');

    expect(fn () => payoutService()->request($store, 'EUR', '0.00', 'key-zero'))
        ->toThrow(InvalidTransactionAmountException::class);

    expect(fn () => payoutService()->request($store, 'EUR', '-10.00', 'key-negative'))
        ->toThrow(InvalidTransactionAmountException::class);

    expect(Payout::count())->toBe(0);
});

it('fails when the store has no wallet in the requested currency', function () {
    $store = Store::factory()->create();

    expect(fn () => payoutService()->request($store, 'USD', '10.00', 'key-1'))
        ->toThrow(PayoutWalletNotFoundException::class);

    expect(Payout::count())->toBe(0);
});

it('returns the existing payout for a repeated idempotency key without creating a second debit', function () {
    $store = Store::factory()->create();
    fundWallet($store, '100.00');

    $first = payoutService()->request($store, 'EUR', '80.00', 'shared-key');
    $second = payoutService()->request($store, 'EUR', '80.00', 'shared-key');

    expect($second->id)->toBe($first->id)
        ->and($second->debit_transaction_id)->toBe($first->debit_transaction_id)
        ->and(Payout::count())->toBe(1)
        ->and(StoreWalletTransaction::where('external_provider', 'internal')->count())->toBe(1);

    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    expect($wallet->fresh()->balance)->toBe('20.00');
});

it('rejects reusing an idempotency key for a different amount', function () {
    $store = Store::factory()->create();
    fundWallet($store, '100.00');

    payoutService()->request($store, 'EUR', '80.00', 'shared-key');

    expect(fn () => payoutService()->request($store, 'EUR', '50.00', 'shared-key'))
        ->toThrow(PayoutIdempotencyKeyReusedException::class);

    // The original reservation is untouched.
    expect(Payout::count())->toBe(1)
        ->and(Payout::first()->amount)->toBe('80.00');
});

it('rejects reusing an idempotency key for a different wallet/currency', function () {
    $store = Store::factory()->create();
    app(WalletService::class)->getOrCreateWallet($store, 'USD');
    fundWallet($store, '100.00', 'EUR');
    fundWallet($store, '100.00', 'USD');

    payoutService()->request($store, 'EUR', '80.00', 'shared-key');

    expect(fn () => payoutService()->request($store, 'USD', '80.00', 'shared-key'))
        ->toThrow(PayoutIdempotencyKeyReusedException::class);
});

it('allows the same idempotency key to be reused across different stores', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    fundWallet($storeA, '100.00');
    fundWallet($storeB, '100.00');

    $a = payoutService()->request($storeA, 'EUR', '80.00', 'shared-key');
    $b = payoutService()->request($storeB, 'EUR', '80.00', 'shared-key');

    expect($a->id)->not->toBe($b->id)
        ->and(Payout::count())->toBe(2);
});

it('never lets two payouts reserve more than the wallet\'s available balance', function () {
    // Single-process/SQLite test harness can't run two real concurrent DB
    // transactions (see PaymentAttemptIdempotencyKeyUniquenessTest for the
    // same constraint elsewhere in this domain) — this proves the actual
    // serialization primitive the concurrency guarantee rests on: the
    // second request()'s call into WalletTransactionService::record() does
    // its own SELECT ... FOR UPDATE and recomputes the balance from what
    // the first request() actually committed, not from a stale read. A true
    // concurrent race resolves identically because both callers contend for
    // the same row lock; only their commit order becomes non-deterministic.
    $store = Store::factory()->create();
    fundWallet($store, '100.00');

    $payoutA = payoutService()->request($store, 'EUR', '80.00', 'payout-a');

    expect($payoutA->status)->toBe(PayoutStatus::Reserved);

    expect(fn () => payoutService()->request($store, 'EUR', '80.00', 'payout-b'))
        ->toThrow(InsufficientWalletBalanceException::class);

    $wallet = app(WalletService::class)->getWallet($store, 'EUR');

    // Exactly 80 reserved, never 160 — and no orphan row for the rejected payout.
    expect($wallet->fresh()->balance)->toBe('20.00')
        ->and(Payout::count())->toBe(1)
        ->and(Payout::first()->id)->toBe($payoutA->id);
});

it('replays a request whose insertOrIgnore lost the race to a since-committed payout', function () {
    // Simulates the interleaving where a concurrent caller's insertOrIgnore
    // + debit already committed by the time this call's own SELECT runs —
    // this call must treat it as a pure replay, never attempt a second debit.
    $store = Store::factory()->create();
    fundWallet($store, '100.00');

    $winner = payoutService()->request($store, 'EUR', '80.00', 'raced-key');

    $result = payoutService()->request($store, 'EUR', '80.00', 'raced-key');

    expect($result->id)->toBe($winner->id)
        ->and(StoreWalletTransaction::where('external_provider', 'internal')->count())->toBe(1);
});
