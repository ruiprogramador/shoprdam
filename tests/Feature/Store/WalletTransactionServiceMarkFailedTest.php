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

    $failed = $service->markFailed($transaction, 'Card declined');

    expect($failed->id)
        ->toBe($transaction->id)
        ->and($failed->store_wallet_id)->toBe($wallet->id)
        ->and($failed->status->slug)->toBe('failed')
        ->and($failed->isFailed())->toBeTrue()
        ->and($failed->description)->toContain('Card declined');
});

it('does not affect the wallet balance when marking a transaction as failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    // Establish a known balance first
    $service->record($wallet, 'sale', '50.00');

    $transaction = $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
    ]);

    expect($wallet->fresh()->balance)->toBe('50.00');

    $service->markFailed($transaction, 'Card declined');

    expect($wallet->fresh()->balance)->toBe('50.00');
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

    expect($failed->description)->toBe('Order #1023 | Card declined');
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

    expect($failed->status->slug)->toBe('failed')
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

    expect(fn () => $service->markFailed($transaction))
        ->toThrow(
            \RuntimeException::class,
            'Only pending transactions can be marked as failed.'
        );
});