<?php

use App\Enums\TransactionSource;
use App\Models\Admin;
use App\Models\Store;
use App\Services\Wallet\WalletTransactionService;


it('marks a pending transaction as failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', options: [
        'status' => 'pending',
    ]);

    $failed = $service->markFailed(
        $transaction,
        'Card declined'
    );

    $failed = $failed->fresh();

    expect($failed->id)
        ->toBe($transaction->id)
        ->and($failed->store_wallet_id)
        ->toBe($wallet->id)
        ->and($failed->status->slug)
        ->toBe('failed')
        ->and($failed->isFailed())
        ->toBeTrue()
        ->and($failed->description)
        ->toContain('Card declined');
});


it('does not affect wallet balance when marking a transaction as failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record($wallet, 'sale', '50.00');

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00',
        options: [
            'status' => 'pending',
        ]
    );

    $balanceBefore = $wallet->fresh()->balance;

    $service->markFailed($transaction);

    expect($wallet->fresh()->balance)
        ->toBe($balanceBefore);
});


it('appends failure reason to an existing description', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', options: [
        'status' => 'pending',
        'description' => 'Order #1023',
    ]);

    $failed = $service->markFailed(
        $transaction,
        'Card declined'
    );

    expect($failed->description)
        ->toBe('Order #1023 | Card declined');
});


it('keeps description unchanged when failure reason is empty', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', options: [
        'status' => 'pending',
        'description' => 'Order #1023',
    ]);

    $failed = $service->markFailed($transaction);

    expect($failed->description)
        ->toBe('Order #1023')
        ->and($failed->status->slug)
        ->toBe('failed');
});


it('throws when marking a non pending transaction as failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00'
    );

    expect(fn () => $service->markFailed($transaction))
        ->toThrow(
            RuntimeException::class,
            'Only pending transactions can be marked as failed.'
        );
});


it('throws when marking an already failed transaction as failed again', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00',
        options: [
            'status' => 'pending',
        ]
    );

    $service->markFailed($transaction);

    expect(fn () => $service->markFailed($transaction))
        ->toThrow(
            RuntimeException::class,
            'Only pending transactions can be marked as failed.'
        );
});


it('ignores dirty in-memory transaction changes when marking failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00',
        options: [
            'status' => 'pending',
            'description' => 'Original description',
        ]
    );

    $transaction->amount = '9999.00';
    $transaction->description = 'Fake description';

    $failed = $service->markFailed(
        $transaction,
        'Card declined'
    );

    $failed = $failed->fresh();

    expect($failed->amount)
        ->toBe('100.00')
        ->and($failed->description)
        ->toBe('Original description | Card declined')
        ->and($failed->status->slug)
        ->toBe('failed');
});


it('preserves metadata ownership and source when marking failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $admin = Admin::factory()->create();

    $transaction = $service->record($wallet, 'sale', '100.00', options: [
        'status' => 'pending',
        'metadata' => [
            'order_id' => 123,
        ],
        'created_by' => $admin->id,
        'source' => TransactionSource::Webhook,
    ]);

    $failed = $service->markFailed(
        $transaction,
        'Declined'
    );

    $failed = $failed->fresh();

    expect($failed->metadata)
        ->toBe([
            'order_id' => 123,
        ])
        ->and($failed->created_by)
        ->toBe($admin->id)
        ->and($failed->source)
        ->toBe(TransactionSource::Webhook)
        ->and($failed->createdBy->is($admin))
        ->toBeTrue();
});

it('preserves external reference when marking failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record($wallet, 'sale', '100.00', options: [
        'status' => 'pending',
        'external_provider' => 'stripe',
        'external_reference' => 'pi_failed_123',
    ]);

    $failed = $service->markFailed(
        $transaction,
        'Card declined'
    );

    $failed = $failed->fresh();

    expect($failed->status->slug)
        ->toBe('failed')
        ->and($failed->external_provider)
        ->toBe('stripe')
        ->and($failed->external_reference)
        ->toBe('pi_failed_123');
});


it('preserves balance_after when marking a pending transaction as failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record($wallet, 'sale', '50.00');

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00',
        options: [
            'status' => 'pending',
        ]
    );

    expect($transaction->balance_after)
        ->toBe('50.00');

    $failed = $service->markFailed($transaction);

    expect($failed->balance_after)
        ->toBe('50.00')
        ->and($wallet->fresh()->balance)
        ->toBe('50.00');
});


it('does not update wallet last transaction timestamp when marking failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record($wallet, 'sale', '50.00');

    $walletBefore = $wallet->fresh();

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00',
        options: [
            'status' => 'pending',
        ]
    );

    $service->markFailed($transaction);

    $walletAfter = $wallet->fresh();

    expect($walletAfter->balance)
        ->toBe($walletBefore->balance)
        ->and($walletAfter->last_transaction_at)
        ->toBe($walletBefore->last_transaction_at);
});


it('ignores dirty wallet changes when marking failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00',
        options: [
            'status' => 'pending',
        ]
    );

    $wallet->balance = '9999.00';

    $failed = $service->markFailed($transaction);

    expect($failed->status->slug)
        ->toBe('failed')
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});


it('marks a pending transaction loaded from database as failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00',
        options: [
            'status' => 'pending',
        ]
    );

    $staleTransaction = $transaction;

    $freshTransaction = $transaction->fresh();

    expect($freshTransaction->status->slug)
        ->toBe('pending');

    $failed = $service->markFailed(
        $staleTransaction,
        'Gateway timeout'
    );

    expect($failed->status->slug)
        ->toBe('failed')
        ->and($failed->description)
        ->toContain('Gateway timeout');
});


it('does not mutate transaction amount when marking failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '125.50',
        options: [
            'status' => 'pending',
        ]
    );

    $amountBefore = $transaction->amount;

    $failed = $service->markFailed(
        $transaction,
        'Payment rejected'
    );

    expect($failed->amount)
        ->toBe($amountBefore);
});


it('keeps failed transaction data after refreshing model', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '80.00',
        options: [
            'status' => 'pending',
            'metadata' => [
                'attempt' => 1,
            ],
        ]
    );

    $service->markFailed(
        $transaction,
        'Timeout'
    );

    $fresh = $transaction->fresh();

    expect($fresh->status->slug)
        ->toBe('failed')
        ->and($fresh->amount)
        ->toBe('80.00')
        ->and($fresh->metadata)
        ->toBe([
            'attempt' => 1,
        ]);
});


it('does not create additional transactions when marking failed', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00',
        options: [
            'status' => 'pending',
        ]
    );

    $countBefore = $wallet->transactions()->count();

    $service->markFailed($transaction);

    expect($wallet->transactions()->count())
        ->toBe($countBefore)
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});


it('keeps wallet state unchanged when marking failed twice concurrently', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00',
        options: [
            'status' => 'pending',
        ]
    );

    $service->markFailed(
        $transaction,
        'First attempt'
    );

    expect(fn () => $service->markFailed(
        $transaction,
        'Second attempt'
    ))
        ->toThrow(
            RuntimeException::class,
            'Only pending transactions can be marked as failed.'
        );

    expect($transaction->fresh()->status->slug)
        ->toBe('failed')
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});