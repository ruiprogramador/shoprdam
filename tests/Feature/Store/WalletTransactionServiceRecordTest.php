<?php

use App\Models\Store;
use App\Services\Wallet\WalletTransactionService;
use App\Enums\TransactionSource;

it('records a credit transaction and increases the wallet balance', function () {
    $service = app(WalletTransactionService::class);
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00');

    $wallet = $wallet->fresh();

    expect($transaction->amount)->toBe('100.00')
        ->and($transaction->balance_after)->toBe('100.00')
        ->and($transaction->store_wallet_id)->toBe($wallet->id)
        ->and($transaction->category->slug)->toBe('sale')
        ->and($transaction->status->slug)->toBe('completed')
        ->and($wallet->balance)->toBe('100.00')
        ->and($transaction->source)->toBe(TransactionSource::System)
        ->and($wallet->last_transaction_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
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
        ->and($transaction->status->slug)->toBe('completed')
        ->and($transaction->source)->toBe(TransactionSource::System);
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

    expect($wallet->fresh()->balance)->toBe('0.00')
        ->and($wallet->transactions()->count())->toBe(0);
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
        ->and($transaction->category->slug)->toBe('sale');
});

it('records a pending transaction without affecting the wallet balance or last transaction timestamp', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $lastTransactionAtBefore = $wallet->fresh()->last_transaction_at;

    $transaction = $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
    ]);

    $wallet = $wallet->fresh()->loadCount('transactions');

    expect($transaction->status->slug)
        ->toBe('pending')
        ->and($transaction->amount)
        ->toBe('100.00')
        ->and($transaction->balance_after)
        ->toBe('0.00')
        ->and($wallet->balance)
        ->toBe('0.00')
        ->and($wallet->transactions_count)
        ->toBe(1)
        ->and($wallet->last_transaction_at)
        ->toBe($lastTransactionAtBefore);
});

it('returns existing transaction for duplicated external reference', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $first = $service->record($wallet, 'sale', '100.00', [
        'external_provider' => 'stripe',
        'external_reference' => 'pi_12345',
    ]);

    $second = $service->record($wallet, 'sale', '990.00', [
        'external_provider' => 'stripe',
        'external_reference' => 'pi_12345',
    ]);

    expect($second->id)
        ->toBe($first->id)
        ->and($second->amount)
        ->toBe('100.00')
        ->and($second->external_provider)
        ->toBe('stripe')
        ->and($second->external_reference)
        ->toBe('pi_12345')
        ->and($wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($wallet->transactions()->count())
        ->toBe(1);
});

it('does not apply idempotency when only one external identifier is provided', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    // Same provider, no external reference
    $service->record($wallet, 'sale', '10.00', [
        'external_provider' => 'stripe',
    ]);

    $service->record($wallet, 'sale', '20.00', [
        'external_provider' => 'stripe',
    ]);

    expect($wallet->fresh()->balance)
        ->toBe('30.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);

    // Same reference, no provider
    $service->record($wallet, 'sale', '5.00', [
        'external_reference' => 'pi_12345',
    ]);

    $service->record($wallet, 'sale', '15.00', [
        'external_reference' => 'pi_12345',
    ]);

    expect($wallet->fresh()->balance)
        ->toBe('50.00')
        ->and($wallet->transactions()->count())
        ->toBe(4);
});

it('stores the creator of the transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '50.00', [
        'created_by' => 123,
    ]);

    expect($transaction->created_by)
        ->toBe(123)
        ->and($transaction->source)
        ->toBe(TransactionSource::System)
        ->and($transaction->status->slug)
        ->toBe('completed')
        ->and($wallet->fresh()->balance)
        ->toBe('50.00');
});

it('throws when using an unknown transaction status', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    expect(fn () => $service->record($wallet, 'sale', '100.00', [
        'status' => 'unknown',
    ]))
        ->toThrow(\RuntimeException::class);
});