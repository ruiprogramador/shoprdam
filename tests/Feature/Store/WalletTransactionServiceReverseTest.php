<?php

use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\Admin;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletTransactionService;


it('creates a reversal transaction and restores the wallet balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );

    $reversal = $service->reverse(
        $sale,
        'customer_refund'
    );

    $reversal = $reversal->fresh([
        'category',
        'status',
        'relatedTransaction',
    ]);

    expect($reversal->id)
        ->not->toBe($sale->id)
        ->and($reversal->store_wallet_id)
        ->toBe($wallet->id)
        ->and($reversal->amount)
        ->toBe('100.00')
        ->and($reversal->balance_after)
        ->toBe('0.00')
        ->and($reversal->related_transaction_id)
        ->toBe($sale->id)
        ->and($reversal->relatedTransaction->is($sale))
        ->toBeTrue()
        ->and($reversal->status->slug)
        ->toBe('completed')
        ->and($reversal->isReversal())
        ->toBeTrue()
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});


it('creates a reversal for debit transactions correctly', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $service->record(
        $wallet,
        'sale',
        '100.00'
    );

    $commission = $service->record(
        $wallet,
        'commission',
        '30.00'
    );


    $reversal = $service->reverse(
        $commission,
        'commission_refund'
    );


    expect($reversal->amount)
        ->toBe('30.00')
        ->and($reversal->balance_after)
        ->toBe('100.00')
        ->and($wallet->fresh()->balance)
        ->toBe('100.00');
});


it('preserves original transaction data when creating reversal', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00',
        null,
        [
            'description' => 'Original sale',
            'metadata' => [
                'order_id' => 123,
            ],
        ]
    );


    $service->reverse(
        $sale,
        'customer_refund'
    );


    $sale = $sale->fresh();


    expect($sale->description)
        ->toBe('Original sale')
        ->and($sale->metadata)
        ->toBe([
            'order_id' => 123,
        ])
        ->and($sale->status->slug)
        ->toBe('completed');
});


it('uses default reversal description when none is supplied', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $reversal = $service->reverse(
        $sale,
        'customer_refund'
    );


    expect($reversal->description)
        ->toBe(
            "Reversal of transaction #{$sale->id}"
        );
});


it('preserves custom reversal description and metadata', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $reversal = $service->reverse(
        $sale,
        'customer_refund',
        'Manual refund',
        null,
        [
            'metadata' => [
                'reason' => 'duplicate',
            ],
        ]
    );


    expect($reversal->description)
        ->toBe('Manual refund')
        ->and($reversal->metadata)
        ->toBe([
            'reason' => 'duplicate',
        ]);
});

it('ignores dirty in-memory transaction changes when reversing', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );

    $sale->amount = '9999.00';
    $sale->transaction_category_id = null;
    $sale->store_wallet_id = null;

    $reversal = $service->reverse(
        $sale,
        'customer_refund'
    );


    expect($reversal->amount)
        ->toBe('100.00')
        ->and($reversal->related_transaction_id)
        ->toBe($sale->id)
        ->and($reversal->balance_after)
        ->toBe('0.00')
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});


it('ignores dirty wallet changes when reversing', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $wallet->balance = '9999.00';


    $reversal = $service->reverse(
        $sale,
        'customer_refund'
    );


    expect($reversal->balance_after)
        ->toBe('0.00')
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});


it('throws when reversing a pending transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $pending = $service->record(
        $wallet,
        'sale',
        '100.00',
        null,
        [
            'status' => 'pending',
        ]
    );


    expect(fn () =>
        $service->reverse(
            $pending,
            'customer_refund'
        )
    )->toThrow(
        RuntimeException::class,
        'Only completed transactions can be reversed.'
    );


    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(1);
});


it('cannot reverse a reversal transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $reversal = $service->reverse(
        $sale,
        'customer_refund'
    );


    expect(fn () =>
        $service->reverse(
            $reversal,
            'commission_refund'
        )
    )->toThrow(
        RuntimeException::class,
        'Cannot reverse a transaction that is itself a reversal.'
    );
});


it('does not allow the same transaction to be reversed twice', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $service->reverse(
        $sale,
        'customer_refund'
    );


    expect(fn () =>
        $service->reverse(
            $sale,
            'customer_refund'
        )
    )->toThrow(
        RuntimeException::class,
        'This transaction has already been reversed.'
    );


    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);
});


it('does not allow reversal from another wallet', function () {
    $service = app(WalletTransactionService::class);

    $storeA = Store::factory()->create();
    $walletA = $storeA->wallets()->first();


    $storeB = Store::factory()->create();
    $walletB = $storeB->wallets()->first();


    $sale = $service->record(
        $walletA,
        'sale',
        '100.00'
    );


    expect(fn () =>
        $service->reverse(
            $sale,
            'customer_refund',
            null,
            null,
            [
                'wallet' => $walletB,
            ]
        )
    )->toThrow(RuntimeException::class);


    expect($walletA->fresh()->balance)
        ->toBe('100.00')
        ->and($walletB->fresh()->balance)
        ->toBe('0.00');
});


it('keeps original reversal payload when duplicate external reference completes later', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $admin = Admin::factory()->create();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $reference = new WalletTransactionReference(
        'stripe',
        'refund_duplicate_payload'
    );


    $first = $service->reverse(
        $sale,
        'customer_refund',
        null,
        $reference,
        [
            'status' => 'pending',
            'description' => 'Refund started',
            'metadata' => [
                'state' => 'pending',
            ],
            'source' => TransactionSource::Webhook,
            'created_by' => $admin->id,
        ]
    );


    $second = $service->reverse(
        $sale,
        'customer_refund',
        null,
        $reference,
        [
            'status' => 'completed',
            'description' => 'Refund completed',
            'metadata' => [
                'state' => 'completed',
            ],
            'source' => TransactionSource::System,
        ]
    );


    $transaction = $second->fresh();


    expect($transaction->id)
        ->toBe($first->id)
        ->and($transaction->status->slug)
        ->toBe('completed')
        ->and($transaction->description)
        ->toBe('Refund started')
        ->and($transaction->metadata)
        ->toBe([
            'state' => 'pending',
        ])
        ->and($transaction->source)
        ->toBe(TransactionSource::Webhook)
        ->and($transaction->created_by)
        ->toBe($admin->id);
});

it('returns existing reversal when duplicated external reference is provided', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $reference = new WalletTransactionReference(
        'stripe',
        'refund_existing_reference'
    );


    $first = $service->reverse(
        $sale,
        'customer_refund',
        null,
        $reference
    );


    $second = $service->reverse(
        $sale,
        'customer_refund',
        null,
        $reference
    );


    expect($second->id)
        ->toBe($first->id)
        ->and($second->related_transaction_id)
        ->toBe($sale->id)
        ->and($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);
});


it('promotes a pending reversal to completed without creating another transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $reference = new WalletTransactionReference(
        'stripe',
        'refund_pending_promote'
    );


    $pending = $service->reverse(
        $sale,
        'customer_refund',
        null,
        $reference,
        [
            'status' => 'pending',
        ]
    );


    expect($wallet->fresh()->balance)
        ->toBe('100.00');


    $completed = $service->reverse(
        $sale,
        'customer_refund',
        null,
        $reference,
        [
            'status' => 'completed',
        ]
    );


    expect($completed->id)
        ->toBe($pending->id)
        ->and($completed->status->slug)
        ->toBe('completed')
        ->and($completed->balance_after)
        ->toBe('0.00')
        ->and($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);
});


it('preserves external reference information on reversal', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $reference = new WalletTransactionReference(
        'stripe',
        'refund_external_123'
    );


    $reversal = $service->reverse(
        $sale,
        'customer_refund',
        null,
        $reference
    );


    expect($reversal->external_provider)
        ->toBe('stripe')
        ->and($reversal->external_reference)
        ->toBe('refund_external_123');
});


it('stores reversal creator and source correctly', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $admin = Admin::factory()->create();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $reversal = $service->reverse(
        $sale,
        'customer_refund',
        null,
        null,
        [
            'created_by' => $admin->id,
            'source' => TransactionSource::Webhook,
        ]
    );


    $reversal = $reversal->fresh();


    expect($reversal->created_by)
        ->toBe($admin->id)
        ->and($reversal->createdBy->is($admin))
        ->toBeTrue()
        ->and($reversal->source)
        ->toBe(TransactionSource::Webhook);
});


it('uses system source by default for reversal', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $reversal = $service->reverse(
        $sale,
        'customer_refund'
    );


    expect($reversal->source)
        ->toBe(TransactionSource::System);
});


it('does not mutate original transaction fields when reversing', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00',
        null,
        [
            'description' => 'Original order',
            'metadata' => [
                'order_id' => 123,
            ],
        ]
    );


    $originalId = $sale->id;
    $originalDescription = $sale->description;


    $service->reverse(
        $sale,
        'customer_refund'
    );


    $sale = $sale->fresh();


    expect($sale->id)
        ->toBe($originalId)
        ->and($sale->description)
        ->toBe($originalDescription)
        ->and($sale->status->slug)
        ->toBe('completed');
});


it('handles decimal amounts correctly when reversing', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '99.99'
    );


    $reversal = $service->reverse(
        $sale,
        'customer_refund'
    );


    expect($reversal->amount)
        ->toBe('99.99')
        ->and($reversal->balance_after)
        ->toBe('0.00')
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});


it('does not create duplicate reversal rows during race condition lookup', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $reference = new WalletTransactionReference(
        'stripe',
        'refund_race_condition'
    );


    $existing = StoreWalletTransaction::factory()
        ->forWallet($wallet)
        ->customerRefund()
        ->amount('100.00')
        ->create([
            'related_transaction_id' => $sale->id,
            'external_provider' => $reference->provider,
            'external_reference' => $reference->reference,
        ]);


    $result = $service->reverse(
        $sale,
        'customer_refund',
        null,
        $reference
    );


    expect($result->id)
        ->toBe($existing->id);
});


it('does not allow two different reversal references for the same transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();


    $sale = $service->record(
        $wallet,
        'sale',
        '100.00'
    );


    $service->reverse(
        $sale,
        'customer_refund',
        null,
        new WalletTransactionReference(
            'stripe',
            'refund_one'
        )
    );


    expect(fn () =>
        $service->reverse(
            $sale,
            'customer_refund',
            null,
            new WalletTransactionReference(
                'stripe',
                'refund_two'
            )
        )
    )->toThrow(
        RuntimeException::class,
        'This transaction has already been reversed.'
    );


    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);
});