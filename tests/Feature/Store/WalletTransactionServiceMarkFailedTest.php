<?php

use App\Models\Store;
use App\Services\Wallet\WalletTransactionService;
use App\Enums\TransactionSource;

it('marks a pending transaction as failed without affecting the balance', function () {
    $service = app(WalletTransactionService::class);
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', ['status' => 'pending']);

    // Pending transactions still adjust balance_after in this implementation,
    // but the wallet's stored balance is what matters here
    $balanceBefore = $wallet->fresh()->balance;

    $failed = $service->markFailed($transaction, 'Card declined');

    expect($failed->status->slug)->toBe('failed')
        ->and($failed->description)->toContain('Card declined')
        ->and($wallet->fresh()->balance)->toBe($balanceBefore);
});

it('throws an exception when marking a non-pending transaction as failed', function () {
    $service = app(WalletTransactionService::class);
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00');

    expect(fn () => $service->markFailed($transaction))
        ->toThrow(RuntimeException::class, 'Only pending transactions can be marked as failed.');
});