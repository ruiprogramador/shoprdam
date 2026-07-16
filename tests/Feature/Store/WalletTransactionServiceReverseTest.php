<?php

use App\Models\Store;
use App\Services\Wallet\WalletTransactionService;

it('creates a reversal transaction and restores the wallet balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');

    $reversal = $service
        ->reverse($sale, 'customer_refund')
        ->load([
            'category',
            'status',
            'storeWallet',
            'relatedTransaction',
        ]);

    expect($reversal->id)
        ->not->toBe($sale->id)
        ->and($reversal->store_wallet_id)->toBe($wallet->id)
        ->and($reversal->amount)->toBe('100.00')
        ->and($reversal->balance_after)->toBe('0.00')
        ->and($reversal->related_transaction_id)->toBe($sale->id)
        ->and($reversal->relatedTransaction->is($sale))->toBeTrue()
        ->and($reversal->category->slug)->toBe('customer_refund')
        ->and($reversal->status->slug)->toBe('completed')
        ->and($reversal->isReversal())->toBeTrue()
        ->and($reversal->storeWallet->is($wallet))->toBeTrue();

    $wallet = $wallet->fresh()->loadCount('transactions');

    expect($wallet->balance)->toBe('0.00')
        ->and($wallet->transactions_count)->toBe(2);

    $sale = $sale->fresh()->load([
        'status',
        'childTransactions',
    ]);

    expect($sale->amount)
        ->toBe('100.00')
        ->and($sale->balance_after)->toBe('100.00')
        ->and($sale->status->slug)->toBe('completed')
        ->and($sale->childTransactions)->toHaveCount(1)
        ->and($sale->childTransactions->first()->is($reversal))->toBeTrue();
});

it('throws an exception when reversing a non-completed transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
    ]);

    expect(fn () => $service->reverse($sale, 'customer_refund'))
        ->toThrow(
            \RuntimeException::class,
            'Only completed transactions can be reversed.'
        );
});

it('throws an exception when reversing a transaction that is itself a reversal', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');

    $reversal = $service->reverse($sale, 'customer_refund');

    expect(fn () => $service->reverse($reversal, 'commission_refund'))
        ->toThrow(
            \RuntimeException::class,
            'Cannot reverse a transaction that is itself a reversal.'
        );
});

it('cannot reverse the same transaction twice', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');

    $service->reverse($sale, 'customer_refund');

    expect(fn () => $service->reverse($sale, 'commission_refund'))
        ->toThrow(
            \RuntimeException::class,
            'This transaction has already been reversed.'
        );

    $wallet = $wallet->fresh()->loadCount('transactions');

    expect($wallet->balance)->toBe('0.00')
        ->and($wallet->transactions_count)->toBe(2);
});