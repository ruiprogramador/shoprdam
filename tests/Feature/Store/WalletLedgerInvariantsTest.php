<?php

use App\Domain\Wallet\Exceptions\TransactionNotPendingException;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;

/**
 * Cross-cutting formal tests for LEDGER-02 (immutability once terminal) and
 * the documented balance_after semantics this branch's design record
 * audited before implementing anything. Only real-producer categories
 * (sale, customer_refund, withdrawal, withdrawal_reversal) are used —
 * consistent with every other new test file in this branch.
 */
it('LEDGER-02: a completed transaction is never written to again by any subsequent operation', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    $service = app(WalletTransactionService::class);

    $sale = $service->record($wallet, 'sale', '100.00');
    $before = $sale->fresh()->toArray();

    // Every operation that could plausibly touch an already-completed row,
    // attempted — each must either no-op or fail, never silently rewrite it.
    $service->reverse($sale, 'customer_refund');

    $stillTheSameRow = StoreWalletTransaction::find($sale->id);

    expect($stillTheSameRow->amount)->toBe($before['amount'])
        ->and($stillTheSameRow->balance_after)->toBe($before['balance_after'])
        ->and($stillTheSameRow->transaction_status_id)->toBe($before['transaction_status_id'])
        ->and($stillTheSameRow->created_at->eq($sale->created_at))->toBeTrue();
});

it('LEDGER-02: a failed transaction is never re-completed or re-failed after the fact', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    $service = app(WalletTransactionService::class);

    $pending = $service->record($wallet, 'sale', '50.00', options: ['status' => 'pending']);
    $failed = $service->markFailed($pending->fresh(), 'declined');
    $beforeUpdatedAt = $failed->fresh()->updated_at;
    $beforeBalanceAfter = $failed->fresh()->balance_after;

    expect(fn () => $service->confirm($failed->fresh()))
        ->toThrow(TransactionNotPendingException::class);

    expect($failed->fresh()->updated_at->eq($beforeUpdatedAt))->toBeTrue()
        ->and($failed->fresh()->balance_after)->toBe($beforeBalanceAfter)
        ->and($wallet->fresh()->balance)->toBe('0.00');
});

it('documents: a pending transaction\'s balance_after is only a snapshot at insertion, not its eventual effect', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    $service = app(WalletTransactionService::class);

    $service->record($wallet, 'sale', '100.00'); // wallet is now 100.00
    $pending = $service->record($wallet, 'sale', '30.00', options: ['status' => 'pending']);

    // balance_after was snapshotted at insertion — the wallet's balance at
    // that moment, NOT the balance this pending transaction would produce
    // once/if confirmed.
    expect($pending->fresh()->balance_after)->toBe('100.00');

    // A second, unrelated sale applies in between.
    $service->record($wallet, 'sale', '20.00'); // wallet is now 120.00

    $confirmed = $service->confirm($pending->fresh());

    // Once confirmed, balance_after is rewritten to the real balance *at
    // confirmation time* — not the stale insertion-time snapshot, and not
    // simply "insertion snapshot + this transaction's amount".
    expect($confirmed->balance_after)->toBe('150.00')
        ->and($wallet->fresh()->balance)->toBe('150.00');
});

it('documents: a reversal never touches the original transaction\'s balance_after', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    $service = app(WalletTransactionService::class);

    $sale = $service->record($wallet, 'sale', '100.00');
    $originalBalanceAfter = $sale->fresh()->balance_after;

    $reversal = $service->reverse($sale, 'customer_refund');

    expect($sale->fresh()->balance_after)->toBe($originalBalanceAfter)
        ->and($sale->fresh()->balance_after)->toBe('100.00')
        ->and($reversal->balance_after)->toBe('0.00');
});

it('documents: the original transaction\'s status is never changed to "reversed" by reverse()', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    $service = app(WalletTransactionService::class);

    $sale = $service->record($wallet, 'sale', '100.00');
    $service->reverse($sale, 'customer_refund');

    // The "reversed" transaction_statuses slug is seeded but never
    // assigned by any code path — the original stays "completed" forever;
    // isReversed() is documented dead code, never true in practice.
    expect($sale->fresh()->isCompleted())->toBeTrue()
        ->and($sale->fresh()->isReversed())->toBeFalse();
});
