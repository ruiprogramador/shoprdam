<?php

use App\Models\Store;
use App\Services\Wallet\WalletTransactionService;
use App\Enums\TransactionSource;
use App\Models\Admin;

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
        ->and($wallet->last_transaction_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($wallet->transactions()->count())->toBe(1);
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
        ->and($transaction->source)->toBe(TransactionSource::System)
        ->and($wallet->transactions()->count())->toBe(2);
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
        ->and($transaction->referenceable->is($store))->toBeTrue()
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
        ->toBe($lastTransactionAtBefore)
        ->and($transaction->source)
        ->toBe(TransactionSource::System);
});

it('ignores dirty in-memory wallet changes when recording a transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $wallet->balance = '9999.00';

    $transaction = $service->record($wallet, 'sale', '25.00');

    expect($transaction->balance_after)->toBe('25.00')
        ->and($transaction->status->slug)->toBe('completed')
        ->and($wallet->fresh()->balance)->toBe('25.00')
        ->and($wallet->fresh()->last_transaction_at)->not->toBeNull();
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
        ->toBe(1)
        ->and($second->status->slug)
        ->toBe('completed');
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
    $admin = Admin::factory()->create();

    $transaction = $service->record($wallet, 'sale', '50.00', [
        'created_by' => $admin->id,
    ]);

    expect($transaction->fresh()->created_by)
        ->toBe($admin->id)
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

it('throws when using an unknown transaction category', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $walletSnapshot = $wallet->fresh();

    expect(fn () => $service->record($wallet, 'drop-table', '10.00'))
        ->toThrow(\RuntimeException::class);

    expect($wallet->fresh()->balance)->toBe($walletSnapshot->balance)
        ->and($wallet->transactions()->count())->toBe(0)
        ->and($wallet->fresh()->last_transaction_at)->toBe($walletSnapshot->last_transaction_at);
});

it('promotes existing pending transaction to completed for duplicated external reference', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $first = $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
        'external_provider' => 'stripe',
        'external_reference' => 'pi_pending_123',
    ]);

    $walletSnapshot = $wallet->fresh();

    $second = $service->record($wallet, 'sale', '250.00', [
        'status' => 'completed',
        'external_provider' => 'stripe',
        'external_reference' => 'pi_pending_123',
    ]);

    expect($second->id)->toBe($first->id)
        ->and($second->status->slug)->toBe('completed')
        ->and($second->balance_after)->toBe('100.00')
        ->and($wallet->fresh()->balance)->toBe('100.00')
        ->and($wallet->fresh()->last_transaction_at)->not->toBeNull()
        ->and($wallet->transactions()->count())->toBe(1)
        ->and($first->fresh()->status->slug)->toBe('completed')
        ->and($first->fresh()->balance_after)->toBe('100.00')
        ->and($walletSnapshot->balance)->toBe('0.00');
});

it('fails promoting pending debit transaction when wallet has insufficient balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record($wallet, 'sale', '50.00');

    $first = $service->record($wallet, 'commission', '100.00', [
        'status' => 'pending',
        'external_provider' => 'stripe',
        'external_reference' => 'pi_pending_debit_123',
    ]);

    expect(fn () => $service->record($wallet, 'commission', '200.00', [
        'status' => 'completed',
        'external_provider' => 'stripe',
        'external_reference' => 'pi_pending_debit_123',
    ]))
        ->toThrow(
            RuntimeException::class,
            'Insufficient wallet balance for this transaction.'
        );

    expect($wallet->fresh()->balance)
        ->toBe('50.00')
        ->and($first->fresh()->status->slug)
        ->toBe('pending');
});

it('does not overwrite original data when promoting pending transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $admin = Admin::factory()->create();

    $first = $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
        'description' => 'Original event',
        'metadata' => ['origin' => 'created'],
        'source' => TransactionSource::Webhook,
        'created_by' => $admin->id,
        'external_provider' => 'stripe',
        'external_reference' => 'pi_pending_data_123',
    ]);

    $second = $service->record($wallet, 'sale', '900.00', [
        'status' => 'completed',
        'description' => 'New event payload',
        'metadata' => ['origin' => 'succeeded'],
        'source' => TransactionSource::System,
        'created_by' => null,
        'external_provider' => 'stripe',
        'external_reference' => 'pi_pending_data_123',
    ]);

    expect($second->id)->toBe($first->id)
        ->and($second->status->slug)->toBe('completed')
        ->and($second->amount)->toBe('100.00')
        ->and($second->description)->toBe('Original event')
        ->and($second->metadata)->toBe(['origin' => 'created'])
        ->and($second->source)->toBe(TransactionSource::Webhook)
        ->and($second->created_by)->toBe($admin->id)
        ->and($wallet->fresh()->balance)->toBe('100.00');
});

it('links the creator relationship', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $admin = Admin::factory()->create();

    $transaction = $service->record($wallet, 'sale', '50.00', [
        'created_by' => $admin->id,
    ]);

    $freshTransaction = $transaction->fresh();

    expect($freshTransaction->createdBy)
        ->toBeInstanceOf(Admin::class)
        ->and($freshTransaction->createdBy->is($admin))
        ->toBeTrue();
});

it('does not apply promotion twice for duplicated external reference', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
        'external_provider' => 'stripe',
        'external_reference' => 'pi_duplicate_confirm',
    ]);

    $service->record($wallet, 'sale', '100.00', [
        'status' => 'completed',
        'external_provider' => 'stripe',
        'external_reference' => 'pi_duplicate_confirm',
    ]);

    $service->record($wallet, 'sale', '100.00', [
        'status' => 'completed',
        'external_provider' => 'stripe',
        'external_reference' => 'pi_duplicate_confirm',
    ]);

    expect($wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($wallet->transactions()->count())
        ->toBe(1);
});