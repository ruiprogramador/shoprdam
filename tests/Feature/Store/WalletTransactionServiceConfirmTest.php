<?php

use App\Enums\TransactionSource;
use App\Models\Admin;
use App\Models\Store;
use App\Services\Wallet\WalletTransactionService;
use App\Models\TransactionStatus;

it('confirms a pending credit transaction and increases the wallet balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
    ]);

    expect($wallet->fresh()->balance)->toBe('0.00');

    $confirmed = $service->confirm($transaction);

    expect($confirmed->id)->toBe($transaction->id)
        ->and($confirmed->amount)->toBe('100.00')
        ->and($confirmed->status->slug)->toBe('completed')
        ->and($confirmed->balance_after)->toBe('100.00')
        ->and($wallet->fresh()->balance)->toBe('100.00')
        ->and($wallet->fresh()->last_transaction_at)->not->toBeNull()
        ->and($transaction->fresh()->status->slug)->toBe('completed')
        ->and($transaction->fresh()->balance_after)->toBe('100.00');
});

it('confirms a pending debit transaction and decreases the wallet balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record($wallet, 'sale', '100.00');

    $transaction = $service->record($wallet, 'commission', '30.00', [
        'status' => 'pending',
    ]);

    expect($wallet->fresh()->balance)->toBe('100.00');

    $confirmed = $service->confirm($transaction);

    expect($confirmed->id)->toBe($transaction->id)
    ->and($confirmed->amount)->toBe('30.00')
    ->and($confirmed->status->slug)->toBe('completed')
    ->and($confirmed->balance_after)->toBe('70.00')
    ->and($wallet->fresh()->balance)->toBe('70.00')
    ->and($wallet->fresh()->last_transaction_at)->not->toBeNull()
    ->and($transaction->fresh()->status->slug)->toBe('completed')
    ->and($transaction->fresh()->balance_after)->toBe('70.00');
});

it('confirms a pending debit transaction that reduces the balance to zero', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record($wallet, 'sale', '40.00');

    $transaction = $service->record($wallet, 'commission', '40.00', [
        'status' => 'pending',
    ]);

    $confirmed = $service->confirm($transaction);

    expect($confirmed->status->slug)->toBe('completed')
        ->and($confirmed->balance_after)->toBe('0.00')
        ->and($wallet->fresh()->balance)->toBe('0.00')
        ->and($wallet->fresh()->last_transaction_at)->not->toBeNull()
        ->and($transaction->fresh()->status->slug)->toBe('completed')
        ->and($transaction->fresh()->balance_after)->toBe('0.00');
});
it('throws an exception when confirming a non-pending transaction', function (string $statusSlug) {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', [
        'status' => $statusSlug,
    ]);
    $walletSnapshot = $wallet->fresh();
    $transactionSnapshot = $transaction->fresh();

    expect(fn () => $service->confirm($transaction))
        ->toThrow(
            \RuntimeException::class,
            'Only pending transactions can be confirmed.'
        );

    expect($wallet->fresh()->balance)->toBe($walletSnapshot->balance)
        ->and($wallet->fresh()->last_transaction_at?->toDateTimeString())->toBe($walletSnapshot->last_transaction_at?->toDateTimeString())
        ->and($transaction->fresh()->status->slug)->toBe($transactionSnapshot->status->slug)
        ->and($transaction->fresh()->balance_after)->toBe($transactionSnapshot->balance_after);
    })->with(['completed', 'failed']);

it('throws an exception when confirming would result in negative balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $pendingDebit = $service->record($wallet, 'commission', '50.00', [
        'status' => 'pending',
    ]);
    $pendingSnapshot = $pendingDebit->fresh();

    // Balance is still 0.00 because pending doesn't affect it,
    // so confirming this debit should fail
    expect(fn () => $service->confirm($pendingDebit))
        ->toThrow(
            \RuntimeException::class,
            'Insufficient wallet balance to confirm this transaction.'
        );

    expect($wallet->fresh()->balance)->toBe('0.00')
        ->and($wallet->fresh()->last_transaction_at)->toBeNull()
        ->and($pendingDebit->fresh()->status->slug)->toBe('pending')
        ->and($pendingDebit->fresh()->balance_after)->toBe($pendingSnapshot->balance_after);
});

it('throws an exception when confirming an already confirmed transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
    ]);

    $service->confirm($transaction);

    expect(fn () => $service->confirm($transaction))
        ->toThrow(
            \RuntimeException::class,
            'Only pending transactions can be confirmed.'
        );
});

it('ignores dirty in-memory changes when confirming a pending transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '25.00', [
        'status' => 'pending',
    ]);

    $transaction->amount = '999.00';
    $transaction->transaction_status_id = TransactionStatus::bySlugOrFail('completed')->id;
    $wallet->balance = '9999.00';

    $confirmed = $service->confirm($transaction);

    expect($confirmed->amount)->toBe('25.00')
        ->and($confirmed->status->slug)->toBe('completed')
        ->and($confirmed->balance_after)->toBe('25.00')
        ->and($wallet->fresh()->balance)->toBe('25.00')
        ->and($confirmed->fresh()->amount)->toBe('25.00');
});

it('confirms two independent pending transactions correctly', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $first = $service->record($wallet, 'sale', '100.00', ['status' => 'pending']);
    $second = $service->record($wallet, 'sale', '50.00', ['status' => 'pending']);

    expect($wallet->fresh()->balance)->toBe('0.00');

    $service->confirm($first);

    expect($first->fresh()->status->slug)->toBe('completed')
        ->and($first->fresh()->balance_after)->toBe('100.00')
        ->and($wallet->fresh()->balance)->toBe('100.00');

    $service->confirm($second);

    expect($second->fresh()->status->slug)->toBe('completed')
        ->and($second->fresh()->balance_after)->toBe('150.00')
        ->and($wallet->fresh()->balance)->toBe('150.00');
});

it('preserves descriptive transaction data when confirming', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
        'description' => 'Order #123',
        'metadata' => ['order_id' => 123],
    ]);

    $confirmed = $service->confirm($transaction);

    expect($confirmed->id)->toBe($transaction->id)
        ->and($confirmed->status->slug)->toBe('completed')
        ->and($confirmed->balance_after)->toBe('100.00')
        ->and($confirmed->description)
        ->toBe('Order #123')
        ->and($confirmed->metadata)
        ->toBe(['order_id' => 123])
        ->and($wallet->fresh()->balance)->toBe('100.00')
        ->and($transaction->fresh()->description)->toBe('Order #123')
        ->and($transaction->fresh()->metadata)->toBe(['order_id' => 123]);
});

it('preserves transaction ownership and source when confirming', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $admin = Admin::factory()->create();

    $transaction = $service->record($wallet, 'sale', '100.00', [
        'status' => 'pending',
        'source' => TransactionSource::Webhook,
        'created_by' => $admin->id,
    ]);

    $transaction->source = TransactionSource::System;
    $transaction->created_by = null;
    $wallet->balance = '9999.00';

    $confirmed = $service->confirm($transaction);

    expect($confirmed->id)->toBe($transaction->id)
        ->and($confirmed->status->slug)->toBe('completed')
        ->and($confirmed->balance_after)->toBe('100.00')
        ->and($confirmed->source)->toBe(TransactionSource::Webhook)
        ->and($confirmed->created_by)->toBe($admin->id)
        ->and($confirmed->createdBy->is($admin))->toBeTrue()
        ->and($wallet->fresh()->balance)->toBe('100.00')
        ->and($transaction->fresh()->source)->toBe(TransactionSource::Webhook)
        ->and($transaction->fresh()->created_by)->toBe($admin->id);
});