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

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00'
    );

    $wallet = $wallet->fresh();

    expect($transaction->amount)
        ->toBe('100.00')
        ->and($transaction->balance_after)
        ->toBe('100.00')
        ->and($transaction->store_wallet_id)
        ->toBe($wallet->id)
        ->and($transaction->category->slug)
        ->toBe('sale')
        ->and($transaction->status->slug)
        ->toBe('completed')
        ->and($wallet->balance)
        ->toBe('100.00')
        ->and($transaction->source)
        ->toBe(TransactionSource::System)
        ->and($wallet->last_transaction_at)
        ->not->toBeNull()
        ->and($wallet->transactions()->count())
        ->toBe(1);
});


it('records a debit transaction and decreases the wallet balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record(
        $wallet,
        'sale',
        '100.00'
    );

    $transaction = $service->record(
        $wallet,
        'commission',
        '10.00'
    );

    expect($transaction->category->slug)
        ->toBe('commission')
        ->and($transaction->amount)
        ->toBe('10.00')
        ->and($transaction->balance_after)
        ->toBe('90.00')
        ->and($wallet->fresh()->balance)
        ->toBe('90.00')
        ->and($transaction->status->slug)
        ->toBe('completed');
});


it('rejects zero and negative amounts', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    expect(fn () => $service->record(
        $wallet,
        'sale',
        '0.00'
    ))
        ->toThrow(RuntimeException::class);

    expect(fn () => $service->record(
        $wallet,
        'sale',
        '-10.00'
    ))
        ->toThrow(RuntimeException::class);

    expect($wallet->fresh()->balance)
        ->toBe('0.00');
});


it('rejects debit transactions that exceed wallet balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    expect(fn () => $service->record(
        $wallet,
        'commission',
        '50.00'
    ))
        ->toThrow(
            RuntimeException::class,
            'Insufficient wallet balance for this transaction.'
        );

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(0);
});


it('supports decimal precision correctly', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '10.55'
    );

    $service->record(
        $wallet,
        'commission',
        '0.55'
    );

    expect($transaction->amount)
        ->toBe('10.55')
        ->and($wallet->fresh()->balance)
        ->toBe('10.00');
});


it('stores optional metadata description and external reference', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $reference = new WalletTransactionReference(
        'stripe',
        'pi_12345'
    );

    $transaction = $service->record(
        $wallet,
        'sale',
        '50.00',
        $reference,
        [
            'description' => 'Order #1023',
            'metadata' => [
                'order_id' => 1023,
            ],
            'source' => TransactionSource::Webhook,
        ]
    );

    expect($transaction->description)
        ->toBe('Order #1023')
        ->and($transaction->external_provider)
        ->toBe('stripe')
        ->and($transaction->external_reference)
        ->toBe('pi_12345')
        ->and($transaction->metadata)
        ->toBe([
            'order_id' => 1023,
        ])
        ->and($transaction->source)
        ->toBe(TransactionSource::Webhook);
});


it('links referenceable models correctly', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '50.00',
        null,
        [
            'referenceable' => $store,
        ]
    );

    expect($transaction->referenceable_type)
        ->toBe($store->getMorphClass())
        ->and($transaction->referenceable_id)
        ->toBe($store->id)
        ->and($transaction->referenceable->is($store))
        ->toBeTrue();
});

it('records pending transactions without affecting wallet balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $lastTransactionAt = $wallet->fresh()->last_transaction_at;

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00',
        null,
        [
            'status' => 'pending',
        ]
    );

    $wallet = $wallet->fresh();

    expect($transaction->status->slug)
        ->toBe('pending')
        ->and($transaction->amount)
        ->toBe('100.00')
        ->and($transaction->balance_after)
        ->toBe('0.00')
        ->and($wallet->balance)
        ->toBe('0.00')
        ->and($wallet->last_transaction_at)
        ->toBe($lastTransactionAt);
});


it('ignores dirty wallet changes when recording a transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $wallet->balance = '9999.00';

    $transaction = $service->record(
        $wallet,
        'sale',
        '25.00'
    );

    expect($transaction->balance_after)
        ->toBe('25.00')
        ->and($wallet->fresh()->balance)
        ->toBe('25.00')
        ->and($wallet->fresh()->last_transaction_at)
        ->not->toBeNull();
});


it('ignores dirty wallet timestamps when recording a transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $wallet->last_transaction_at = now()->addYear();

    $transaction = $service->record(
        $wallet,
        'sale',
        '50.00'
    );

    expect($transaction->balance_after)
        ->toBe('50.00')
        ->and($wallet->fresh()->balance)
        ->toBe('50.00')
        ->and($wallet->fresh()->last_transaction_at)
        ->not->toBeNull();
});


it('returns existing transaction for duplicated external reference', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $reference = new WalletTransactionReference(
        'stripe',
        'pi_duplicate'
    );

    $first = $service->record(
        $wallet,
        'sale',
        '100.00',
        $reference
    );

    $second = $service->record(
        $wallet,
        'sale',
        '999.00',
        $reference
    );

    expect($second->id)
        ->toBe($first->id)
        ->and($second->amount)
        ->toBe('100.00')
        ->and($wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($wallet->transactions()->count())
        ->toBe(1);
});


it('does not apply idempotency when there is no complete external reference', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record(
        $wallet,
        'sale',
        '10.00',
        null,
        [
            'external_provider' => 'stripe',
        ]
    );

    $service->record(
        $wallet,
        'sale',
        '20.00',
        null,
        [
            'external_reference' => 'pi_123',
        ]
    );

    expect($wallet->fresh()->balance)
        ->toBe('30.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);
});


it('promotes an existing pending transaction with same external reference', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $reference = new WalletTransactionReference(
        'stripe',
        'pi_pending_123'
    );

    $first = $service->record(
        $wallet,
        'sale',
        '100.00',
        $reference,
        [
            'status' => 'pending',
        ]
    );

    $second = $service->record(
        $wallet,
        'sale',
        '250.00',
        $reference,
        [
            'status' => 'completed',
        ]
    );

    $transaction = $second->fresh();

    expect($transaction->id)
        ->toBe($first->id)
        ->and($transaction->status->slug)
        ->toBe('completed')
        ->and($transaction->amount)
        ->toBe('100.00')
        ->and($transaction->balance_after)
        ->toBe('100.00')
        ->and($wallet->fresh()->balance)
        ->toBe('100.00');
});


it('does not overwrite original payload when promoting pending transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $admin = Admin::factory()->create();

    $reference = new WalletTransactionReference(
        'stripe',
        'pi_payload_lock'
    );

    $first = $service->record(
        $wallet,
        'sale',
        '100.00',
        $reference,
        [
            'status' => 'pending',
            'description' => 'Original payload',
            'metadata' => [
                'origin' => 'first',
            ],
            'source' => TransactionSource::Webhook,
            'created_by' => $admin->id,
        ]
    );

    $second = $service->record(
        $wallet,
        'sale',
        '900.00',
        $reference,
        [
            'status' => 'completed',
            'description' => 'Fake overwrite',
            'metadata' => [
                'origin' => 'second',
            ],
            'source' => TransactionSource::System,
        ]
    );

    $transaction = $second->fresh();

    expect($transaction->id)
        ->toBe($first->id)
        ->and($transaction->amount)
        ->toBe('100.00')
        ->and($transaction->description)
        ->toBe('Original payload')
        ->and($transaction->metadata)
        ->toBe([
            'origin' => 'first',
        ])
        ->and($transaction->source)
        ->toBe(TransactionSource::Webhook)
        ->and($transaction->created_by)
        ->toBe($admin->id);
});


it('fails promoting pending debit transaction when balance is insufficient', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record(
        $wallet,
        'sale',
        '50.00'
    );

    $reference = new WalletTransactionReference(
        'stripe',
        'pi_pending_debit'
    );

    $transaction = $service->record(
        $wallet,
        'commission',
        '100.00',
        $reference,
        [
            'status' => 'pending',
        ]
    );

    expect(fn () => $service->record(
        $wallet,
        'commission',
        '100.00',
        $reference,
        [
            'status' => 'completed',
        ]
    ))
        ->toThrow(
            RuntimeException::class,
            'Insufficient wallet balance for this transaction.'
        );

    expect($transaction->fresh()->status->slug)
        ->toBe('pending')
        ->and($wallet->fresh()->balance)
        ->toBe('50.00');
});

it('stores the transaction creator and keeps the relationship', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $admin = Admin::factory()->create();

    $transaction = $service->record(
        $wallet,
        'sale',
        '50.00',
        null,
        [
            'created_by' => $admin->id,
        ]
    );

    $transaction = $transaction->fresh();

    expect($transaction->created_by)
        ->toBe($admin->id)
        ->and($transaction->createdBy)
        ->toBeInstanceOf(Admin::class)
        ->and($transaction->createdBy->is($admin))
        ->toBeTrue();
});


it('uses system source by default', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $transaction = $service->record(
        $wallet,
        'sale',
        '100.00'
    );

    expect($transaction->source)
        ->toBe(TransactionSource::System);
});


it('throws when using an unknown transaction status without mutating wallet', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $snapshot = $wallet->fresh();

    expect(fn () =>
        $service->record(
            $wallet,
            'sale',
            '100.00',
            null,
            [
                'status' => 'unknown_status',
            ]
        )
    )->toThrow(RuntimeException::class);


    expect($wallet->fresh()->balance)
        ->toBe($snapshot->balance)
        ->and($wallet->transactions()->count())
        ->toBe(0)
        ->and($wallet->fresh()->last_transaction_at)
        ->toBe($snapshot->last_transaction_at);
});


it('throws when using an unknown transaction category without mutating wallet', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    expect(fn () =>
        $service->record(
            $wallet,
            'invalid-category',
            '100.00'
        )
    )->toThrow(RuntimeException::class);


    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(0)
        ->and($wallet->fresh()->last_transaction_at)
        ->toBeNull();
});


it('keeps independent pending transactions isolated', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $first = $service->record(
        $wallet,
        'sale',
        '100.00',
        null,
        [
            'status' => 'pending',
        ]
    );

    $second = $service->record(
        $wallet,
        'sale',
        '50.00',
        null,
        [
            'status' => 'pending',
        ]
    );

    expect($first->id)
        ->not->toBe($second->id)
        ->and($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);
});


it('does not mutate original model instance after recording', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $wallet->balance = '9999.00';

    $transaction = $service->record(
        $wallet,
        'sale',
        '25.00'
    );

    expect($transaction->balance_after)
        ->toBe('25.00')
        ->and($wallet->fresh()->balance)
        ->toBe('25.00');
});


it('handles duplicate external references safely at database level', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    StoreWalletTransaction::factory()
        ->forWallet($wallet)
        ->sale()
        ->amount('100.00')
        ->create([
            'external_provider' => 'stripe',
            'external_reference' => 'pi_unique_reference',
        ]);


    expect(fn () =>
        StoreWalletTransaction::factory()
            ->forWallet($wallet)
            ->sale()
            ->amount('50.00')
            ->create([
                'external_provider' => 'stripe',
                'external_reference' => 'pi_unique_reference',
            ])
    )->toThrow(\Illuminate\Database\QueryException::class);
});


it('returns an existing transaction when external reference already exists before recording', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $reference = new WalletTransactionReference(
        'stripe',
        'race_reference'
    );


    $existing = StoreWalletTransaction::factory()
        ->forWallet($wallet)
        ->sale()
        ->amount('100.00')
        ->create([
            'external_provider' => $reference->provider,
            'external_reference' => $reference->reference,
        ]);


    $result = $service->record(
        $wallet,
        'sale',
        '500.00',
        $reference
    );


    expect($result->id)
        ->toBe($existing->id)
        ->and($wallet->transactions()->count())
        ->toBe(1);
});


it('records multiple transactions sequentially keeping balances correct', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $first = $service->record(
        $wallet,
        'sale',
        '100.00'
    );

    $second = $service->record(
        $wallet,
        'sale',
        '50.00'
    );

    $third = $service->record(
        $wallet,
        'commission',
        '30.00'
    );


    expect($first->balance_after)
        ->toBe('100.00')
        ->and($second->balance_after)
        ->toBe('150.00')
        ->and($third->balance_after)
        ->toBe('120.00')
        ->and($wallet->fresh()->balance)
        ->toBe('120.00');
});