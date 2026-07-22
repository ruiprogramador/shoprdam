<?php

use App\Enums\TransactionSource;
use App\Models\Admin;
use App\Models\Store;
use App\Models\TransactionStatus;
use App\Services\Wallet\WalletTransactionService;

it('confirms a pending credit transaction and increases the wallet balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', options: [
        'status' => 'pending',
    ]);

    $wallet = $wallet->fresh();

    expect($wallet->balance)->toBe('0.00');

    $confirmed = $service->confirm($transaction);

    $wallet = $wallet->fresh();
    $transaction = $transaction->fresh();

    expect($confirmed->id)
        ->toBe($transaction->id)
        ->and($confirmed->amount)
        ->toBe('100.00')
        ->and($confirmed->status->slug)
        ->toBe('completed')
        ->and($confirmed->balance_after)
        ->toBe('100.00')
        ->and($wallet->balance)
        ->toBe('100.00')
        ->and($wallet->last_transaction_at)
        ->not->toBeNull()
        ->and($transaction->status->slug)
        ->toBe('completed')
        ->and($transaction->balance_after)
        ->toBe('100.00');
});


it('confirms a pending debit transaction and decreases the wallet balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record($wallet, 'sale', '100.00');

    $transaction = $service->record($wallet, 'commission', '30.00', options: [
        'status' => 'pending',
    ]);

    expect($wallet->fresh()->balance)
        ->toBe('100.00');

    $confirmed = $service->confirm($transaction);

    $wallet = $wallet->fresh();

    expect($confirmed->status->slug)
        ->toBe('completed')
        ->and($confirmed->balance_after)
        ->toBe('70.00')
        ->and($wallet->balance)
        ->toBe('70.00')
        ->and($wallet->last_transaction_at)
        ->not->toBeNull();
});


it('confirms a pending debit transaction that reduces the balance to zero', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record($wallet, 'sale', '40.00');

    $transaction = $service->record($wallet, 'commission', '40.00', options: [
        'status' => 'pending',
    ]);

    $confirmed = $service->confirm($transaction);

    $wallet = $wallet->fresh();
    $transaction = $transaction->fresh();

    expect($confirmed->status->slug)
        ->toBe('completed')
        ->and($confirmed->balance_after)
        ->toBe('0.00')
        ->and($wallet->balance)
        ->toBe('0.00')
        ->and($transaction->balance_after)
        ->toBe('0.00');
});


it('confirms a pending transaction loaded from database ignoring stale model state', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '75.00', options: [
        'status' => 'pending',
    ]);

    $staleTransaction = $transaction;

    $freshTransaction = $transaction->fresh();

    expect($freshTransaction->status->slug)
        ->toBe('pending');

    $confirmed = $service->confirm($staleTransaction);

    expect($confirmed->status->slug)
        ->toBe('completed')
        ->and($confirmed->amount)
        ->toBe('75.00')
        ->and($wallet->fresh()->balance)
        ->toBe('75.00');
});


it('throws an exception when confirming a non-pending transaction', function (string $statusSlug) {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', options: [
        'status' => $statusSlug,
    ]);

    $walletSnapshot = $wallet->fresh();
    $transactionSnapshot = $transaction->fresh();

    expect(fn () => $service->confirm($transaction))
        ->toThrow(
            RuntimeException::class,
            'Only pending transactions can be confirmed.'
        );

    expect($wallet->fresh()->balance)
        ->toBe($walletSnapshot->balance)
        ->and($transaction->fresh()->status->slug)
        ->toBe($transactionSnapshot->status->slug);

})->with([
    'completed',
    'failed',
]);


it('throws an exception when confirming would result in negative balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'commission', '50.00', options: [
        'status' => 'pending',
    ]);

    $snapshot = $transaction->fresh();

    expect(fn () => $service->confirm($transaction))
        ->toThrow(
            RuntimeException::class,
            'Insufficient wallet balance to confirm this transaction.'
        );

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->fresh()->last_transaction_at)
        ->toBeNull()
        ->and($transaction->fresh()->status->slug)
        ->toBe('pending')
        ->and($transaction->fresh()->balance_after)
        ->toBe($snapshot->balance_after);
});

it('throws an exception when confirming an already confirmed transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', options: [
        'status' => 'pending',
    ]);

    $service->confirm($transaction);

    expect(fn () => $service->confirm($transaction))
        ->toThrow(
            RuntimeException::class,
            'Only pending transactions can be confirmed.'
        );

    expect($wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($wallet->transactions()->count())
        ->toBe(1);
});


it('ignores dirty in-memory changes when confirming a pending transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '25.00', options: [
        'status' => 'pending',
    ]);

    $transaction->amount = '999.00';
    $transaction->transaction_status_id = TransactionStatus::bySlugOrFail('completed')->id;

    $wallet->balance = '9999.00';

    $confirmed = $service->confirm($transaction);

    expect($confirmed->amount)
        ->toBe('25.00')
        ->and($confirmed->status->slug)
        ->toBe('completed')
        ->and($confirmed->balance_after)
        ->toBe('25.00')
        ->and($wallet->fresh()->balance)
        ->toBe('25.00')
        ->and($confirmed->fresh()->amount)
        ->toBe('25.00');
});


it('preserves external reference when confirming a transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00',
        null,
        [
            'status' => 'pending',
            'external_provider' => 'stripe',
            'external_reference' => 'pi_confirm_123',
        ]
    );

    $confirmed = $service->confirm($transaction);

    $confirmed = $confirmed->fresh();

    expect($confirmed->status->slug)
        ->toBe('completed')
        ->and($confirmed->external_provider)
        ->toBe('stripe')
        ->and($confirmed->external_reference)
        ->toBe('pi_confirm_123');
});


it('preserves descriptive transaction data when confirming', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', options: [
        'status' => 'pending',
        'description' => 'Order #123',
        'metadata' => [
            'order_id' => 123,
        ],
    ]);

    $confirmed = $service->confirm($transaction);

    $confirmed = $confirmed->fresh();

    expect($confirmed->description)
        ->toBe('Order #123')
        ->and($confirmed->metadata)
        ->toBe([
            'order_id' => 123,
        ]);
});


it('preserves transaction ownership and source when confirming', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $admin = Admin::factory()->create();

    $transaction = $service->record($wallet, 'sale', '100.00', options: [
        'status' => 'pending',
        'source' => TransactionSource::Webhook,
        'created_by' => $admin->id,
    ]);

    $transaction->source = TransactionSource::System;
    $transaction->created_by = null;

    $confirmed = $service->confirm($transaction);

    $confirmed = $confirmed->fresh();

    expect($confirmed->source)
        ->toBe(TransactionSource::Webhook)
        ->and($confirmed->created_by)
        ->toBe($admin->id)
        ->and($confirmed->createdBy->is($admin))
        ->toBeTrue();
});


it('confirms two independent pending transactions correctly', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $first = $service->record(
        $wallet,
        'sale',
        '100.00',
        options: [
            'status' => 'pending',
        ]
    );

    $second = $service->record(
        $wallet,
        'sale',
        '50.00',
        options: [
            'status' => 'pending',
        ]
    );

    expect($wallet->fresh()->balance)
        ->toBe('0.00');

    $service->confirm($first);

    expect($wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($first->fresh()->balance_after)
        ->toBe('100.00');

    $service->confirm($second);

    expect($wallet->fresh()->balance)
        ->toBe('150.00')
        ->and($second->fresh()->balance_after)
        ->toBe('150.00');
});


it('does not mutate wallet when confirmation fails', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'commission',
        '100.00',
        options: [
            'status' => 'pending',
        ]
    );

    $walletBefore = $wallet->fresh();

    expect(fn () => $service->confirm($transaction))
        ->toThrow(RuntimeException::class);

    $walletAfter = $wallet->fresh();

    expect($walletAfter->balance)
        ->toBe($walletBefore->balance)
        ->and($walletAfter->last_transaction_at)
        ->toBe($walletBefore->last_transaction_at)
        ->and($transaction->fresh()->status->slug)
        ->toBe('pending');
});


it('updates last_transaction_at when confirming a pending transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $before = $wallet->fresh()->last_transaction_at;

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00',
        options: [
            'status' => 'pending',
        ]
    );

    $service->confirm($transaction);

    $wallet = $wallet->fresh();

    expect($wallet->last_transaction_at)
        ->not->toBeNull()
        ->and($wallet->last_transaction_at)
        ->not->toBe($before);
});