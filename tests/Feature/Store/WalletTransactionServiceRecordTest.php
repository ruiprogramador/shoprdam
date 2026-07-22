<?php

use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\Admin;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletTransactionService;

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

    $reference = new WalletTransactionReference('stripe', 'pi_12345');

    $transaction = $service->record($wallet, 'sale', '50.00', $reference, [
        'description' => 'Order #1023',
        'metadata' => ['order_id' => 1023],
        'source' => TransactionSource::Webhook,
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

    $transaction = $service->record($wallet, 'sale', '50.00', null, [
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

    $transaction = $service->record($wallet, 'sale', '100.00', null, [
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

    $reference = new WalletTransactionReference('stripe', 'pi_12345');

    $first = $service->record($wallet, 'sale', '100.00', $reference);

    $second = $service->record($wallet, 'sale', '990.00', $reference);

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

    $service->record($wallet, 'sale', '10.00', null, [
        'external_provider' => 'stripe',
    ]);

    $service->record($wallet, 'sale', '20.00', null, [
        'external_provider' => 'stripe',
    ]);

    expect($wallet->fresh()->balance)
        ->toBe('30.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);

    $service->record($wallet, 'sale', '5.00', null, [
        'external_reference' => 'pi_12345',
    ]);

    $service->record($wallet, 'sale', '15.00', null, [
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

    $transaction = $service->record($wallet, 'sale', '50.00', null, [
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

    expect(fn () => $service->record($wallet, 'sale', '100.00', null, [
        'status' => 'unknown',
    ]))->toThrow(\RuntimeException::class);
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

    $reference = new WalletTransactionReference('stripe', 'pi_pending_123');

    $first = $service->record($wallet, 'sale', '100.00', $reference, [
        'status' => 'pending',
    ]);

    $walletSnapshot = $wallet->fresh();

    $second = $service->record($wallet, 'sale', '250.00', $reference, [
        'status' => 'completed',
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

    $reference = new WalletTransactionReference('stripe', 'pi_pending_debit_123');

    $first = $service->record($wallet, 'commission', '100.00', $reference, [
        'status' => 'pending',
    ]);

    expect(fn () => $service->record($wallet, 'commission', '200.00', $reference, [
        'status' => 'completed',
    ]))->toThrow(
        \RuntimeException::class,
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

    $reference = new WalletTransactionReference('stripe', 'pi_pending_data_123');

    $first = $service->record($wallet, 'sale', '100.00', $reference, [
        'status' => 'pending',
        'description' => 'Original event',
        'metadata' => ['origin' => 'created'],
        'source' => TransactionSource::Webhook,
        'created_by' => $admin->id,
    ]);

    $second = $service->record($wallet, 'sale', '900.00', $reference, [
        'status' => 'completed',
        'description' => 'New event payload',
        'metadata' => ['origin' => 'succeeded'],
        'source' => TransactionSource::System,
        'created_by' => null,
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

    $transaction = $service->record($wallet, 'sale', '50.00', null, [
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
    $reference = new WalletTransactionReference('stripe', 'pi_duplicate_confirm');

    $service->record($wallet, 'sale', '100.00', $reference, [
        'status' => 'pending',
    ]);

    $service->record($wallet, 'sale', '100.00', $reference, [
        'status' => 'completed',
    ]);

    $service->record($wallet, 'sale', '100.00', $reference, [
        'status' => 'completed',
    ]);

    expect($wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($wallet->transactions()->count())
        ->toBe(1);
});

it('enforces a unique constraint on external_provider and external_reference at the database level', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    StoreWalletTransaction::factory()
        ->forWallet($wallet)
        ->sale()
        ->amount('100.00')
        ->create([
            'external_provider'  => 'stripe',
            'external_reference' => 'pi_unique_test',
        ]);

    expect(fn () => StoreWalletTransaction::factory()
        ->forWallet($wallet)
        ->sale()
        ->amount('50.00')
        ->create([
            'external_provider'  => 'stripe',
            'external_reference' => 'pi_unique_test',
        ])
    )->toThrow(\Illuminate\Database\QueryException::class);
});

it('recovers gracefully when a transaction for the same reference already exists at lookup time', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $reference = new WalletTransactionReference('stripe', 'pi_race_condition_123');

    $winner = StoreWalletTransaction::factory()
        ->forWallet($wallet)
        ->sale()
        ->amount('100.00')
        ->create([
            'external_provider'  => $reference->provider,
            'external_reference' => $reference->reference,
        ]);

    // Even though this transaction wasn't created via record(), the wallet's
    // balance in the test setup won't reflect it (factory doesn't touch balance).
    // What matters here is that record() detects the existing row and never
    // attempts a duplicate insert.
    $result = $service->record($wallet, 'sale', '100.00', $reference);

    expect($result->id)->toBe($winner->id)
        ->and($wallet->transactions()->count())->toBe(1);
});