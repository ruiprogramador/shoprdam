<?php

use App\Models\Store;
use App\Services\Wallet\WalletTransactionService;

it('marks a pending transaction as failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
    ]);

    $failed = $service->markFailed($transaction, 'Card declined')
                ->load('status');

    expect($failed->id)->toBe($transaction->id)
        ->and($failed->store_wallet_id)->toBe($wallet->id)
        ->and($failed->status->slug)->toBe('failed')
        ->and($failed->isFailed())->toBeTrue()
        ->and($failed->description)->toContain('Card declined');

    $wallet = $wallet->fresh()->loadCount('transactions');

    expect($wallet->transactions_count)->toBe(1);
});

it('appends the reason to an existing description', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', [
        'status'      => 'pending',
        'description' => 'Order #1023',
    ]);

    $failed = $service->markFailed($transaction, 'Card declined');

    expect($failed->description)
        ->toBe('Order #1023 | Card declined');
});

it('marks a pending transaction as failed without a reason', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', [
        'status'      => 'pending',
        'description' => 'Order #1023',
    ]);

    $failed = $service->markFailed($transaction);

    expect($failed->isFailed())->toBeTrue()
        ->and($failed->description)->toBe('Order #1023');
});

it('throws an exception when marking a non-pending transaction as failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00');

    expect(fn () => $service->markFailed($transaction))
        ->toThrow(
            \RuntimeException::class,
            'Only pending transactions can be marked as failed.'
        );
});

it('throws an exception when marking a failed transaction as failed again', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
    ]);

    $service->markFailed($transaction);

    $transaction = $transaction->fresh();

    expect(fn () => $service->markFailed($transaction))
        ->toThrow(
            \RuntimeException::class,
            'Only pending transactions can be marked as failed.'
        );
});

it('does not change the wallet balance when marking a pending transaction as failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
    ]);

    expect($wallet->fresh()->balance)
        ->toBe('0.00');

    $service->markFailed($transaction);

    expect($wallet->fresh()->balance)
        ->toBe('0.00');
});