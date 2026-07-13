<?php

use App\Models\Store;
use App\Services\Wallet\WalletTransactionService;
use App\Enums\TransactionSource;

it('records a credit transaction and increases the wallet balance', function () {
    $service = app(WalletTransactionService::class);
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00');

    expect($transaction->amount)->toBe('100.00')
        ->and($transaction->balance_after)->toBe('100.00')
        ->and($transaction->store_wallet_id)->toBe($wallet->id)
        ->and($transaction->category->slug)->toBe('sale')
        ->and($transaction->status->slug)->toBe('completed')
        ->and($wallet->fresh()->balance)->toBe('100.00')
        ->and($wallet->fresh()->last_transaction_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($transaction->storeWallet->is($wallet))->toBeTrue();
});

it('records a debit transaction and decreases the wallet balance', function () {
    $service = app(WalletTransactionService::class);
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record($wallet, 'sale', '100.00');
    $transaction = $service->record($wallet, 'commission', '10.00');

    expect($transaction->category->slug)->toBe('commission')
        ->and($transaction->amount)->toBe('10.00')
        ->and($transaction->balance_after)->toBe('90.00')
        ->and($wallet->fresh()->balance)->toBe('90.00')
        ->and($transaction->status->slug)->toBe('completed');
});

it('throws an exception when amount is zero or negative', function () {
    $service = app(WalletTransactionService::class);
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    expect(fn () => $service->record($wallet, 'sale', '0.00'))
        ->toThrow(\RuntimeException::class);

    expect(fn () => $service->record($wallet, 'sale', '-10.00'))
        ->toThrow(\RuntimeException::class);
});

it('throws an exception when debit would result in negative balance', function () {
    $service = app(WalletTransactionService::class);
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    expect(fn () => $service->record($wallet, 'commission', '50.00'))
        ->toThrow(\RuntimeException::class, 'Insufficient wallet balance for this transaction.');

    expect($wallet->fresh()->balance)->toBe('0.00');
});

it('stores optional metadata, description and external reference', function () {
    $service = app(WalletTransactionService::class);
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '50.00', [
        'description'         => 'Order #1023',
        'external_provider'   => 'stripe',
        'external_reference'  => 'pi_12345',
        'metadata'            => ['order_id' => 1023],
        'source'              => TransactionSource::Webhook,
    ]);

    expect($transaction->store_wallet_id)->toBe($wallet->id)
        ->and($transaction->description)->toBe('Order #1023')
        ->and($transaction->external_provider)->toBe('stripe')
        ->and($transaction->external_reference)->toBe('pi_12345')
        ->and($transaction->metadata)->toBe(['order_id' => 1023])
        ->and($transaction->source)->toBe(TransactionSource::Webhook)
        ->and($transaction->category->slug)->toBe('sale')
        ->and($transaction->amount)->toBe('50.00')
        ->and($transaction->balance_after)->toBe('50.00')
        ->and($transaction->status->slug)->toBe('completed');
});

it('links a referenceable model to the transaction', function () {
    $service = app(WalletTransactionService::class);
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '50.00', [
        'referenceable' => $store,
    ]);

    expect($transaction->store_wallet_id)->toBe($wallet->id)
        ->and($transaction->referenceable_type)->toBe($store->getMorphClass())
        ->and($transaction->referenceable_id)->toBe($store->id)
        ->and($transaction->referenceable->is($store))->toBeTrue()
        ->and($transaction->category->slug)->toBe('sale');
});