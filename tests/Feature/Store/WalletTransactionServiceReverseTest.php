<?php

use App\Models\Store;
use App\Services\Wallet\WalletTransactionService;

it('reverses a completed transaction and restores the balance', function () {
    $service = app(WalletTransactionService::class);
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');
    $reversal = $service->reverse($sale, 'customer_refund');

    expect($reversal->related_transaction_id)->toBe($sale->id)
        ->and($reversal->category->slug)->toBe('customer_refund')
        ->and($reversal->isReversal())->toBeTrue()
        ->and($wallet->fresh()->balance)->toBe('0.00');
});

it('throws an exception when reversing a non-completed transaction', function () {
    $service = app(WalletTransactionService::class);
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00', ['status' => 'pending']);

    expect(fn () => $service->reverse($sale, 'customer_refund'))
        ->toThrow(RuntimeException::class, 'Only completed transactions can be reversed.');
});

it('throws an exception when reversing a transaction that is already a reversal', function () {
    $service = app(WalletTransactionService::class);
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');
    $reversal = $service->reverse($sale, 'customer_refund');

    expect(fn () => $service->reverse($reversal, 'commission_refund'))
        ->toThrow(RuntimeException::class, 'Cannot reverse a transaction that is itself a reversal.');
});