<?php

use App\Domain\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Domain\Wallet\Exceptions\InvalidTransactionAmountException;
use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\Admin;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletTransactionService;

beforeEach(function () {
    $this->service = app(WalletTransactionService::class);
    $this->store = Store::factory()->create();
    $this->wallet = $this->store->wallets()->first();
});


it('records a credit transaction and increases the wallet balance', function () {
    $transaction = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );

    $wallet = $this->wallet->fresh();

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
    $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );

    $transaction = $this->service->record(
        $this->wallet,
        'commission',
        '10.00'
    );

    expect($transaction->category->slug)
        ->toBe('commission')
        ->and($transaction->amount)
        ->toBe('10.00')
        ->and($transaction->balance_after)
        ->toBe('90.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('90.00')
        ->and($transaction->status->slug)
        ->toBe('completed');
});


it('rejects zero and negative amounts', function () {
    expect(fn () => $this->service->record(
        $this->wallet,
        'sale',
        '0.00'
    ))
        ->toThrow(InvalidTransactionAmountException::class);

    expect(fn () => $this->service->record(
        $this->wallet,
        'sale',
        '-10.00'
    ))
        ->toThrow(InvalidTransactionAmountException::class);

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00');
});


it('rejects debit transactions that exceed wallet balance', function () {
    expect(fn () => $this->service->record(
        $this->wallet,
        'commission',
        '50.00'
    ))
        ->toThrow(
            InsufficientWalletBalanceException::class,
            'Insufficient wallet balance for this transaction.'
        );

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(0);
});


it('supports decimal precision correctly', function () {
    $transaction = $this->service->record(
        $this->wallet,
        'sale',
        '10.55'
    );

    $this->service->record(
        $this->wallet,
        'commission',
        '0.55'
    );

    expect($transaction->amount)
        ->toBe('10.55')
        ->and($this->wallet->fresh()->balance)
        ->toBe('10.00');
});


it('stores optional metadata description and external reference', function () {
    $reference = new WalletTransactionReference(
        'stripe',
        'pi_12345'
    );

    $transaction = $this->service->record(
        $this->wallet,
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
    $transaction = $this->service->record(
        $this->wallet,
        'sale',
        '50.00',
        null,
        [
            'referenceable' => $this->store,
        ]
    );

    expect($transaction->referenceable_type)
        ->toBe($this->store->getMorphClass())
        ->and($transaction->referenceable_id)
        ->toBe($this->store->id)
        ->and($transaction->referenceable->is($this->store))
        ->toBeTrue();
});

it('records pending transactions without affecting wallet balance', function () {
    $lastTransactionAt = $this->wallet->fresh()->last_transaction_at;

    $transaction = $this->service->record(
        $this->wallet,
        'sale',
        '100.00',
        null,
        [
            'status' => 'pending',
        ]
    );

    $wallet = $this->wallet->fresh();

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
    $this->wallet->balance = '9999.00';

    $transaction = $this->service->record(
        $this->wallet,
        'sale',
        '25.00'
    );

    expect($transaction->balance_after)
        ->toBe('25.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('25.00')
        ->and($this->wallet->fresh()->last_transaction_at)
        ->not->toBeNull();
});


it('ignores dirty wallet timestamps when recording a transaction', function () {
    $this->wallet->last_transaction_at = now()->addYear();

    $transaction = $this->service->record(
        $this->wallet,
        'sale',
        '50.00'
    );

    expect($transaction->balance_after)
        ->toBe('50.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('50.00')
        ->and($this->wallet->fresh()->last_transaction_at)
        ->not->toBeNull();
});


it('returns existing transaction for duplicated external reference', function () {
    $reference = new WalletTransactionReference(
        'stripe',
        'pi_duplicate'
    );

    $first = $this->service->record(
        $this->wallet,
        'sale',
        '100.00',
        $reference
    );

    $second = $this->service->record(
        $this->wallet,
        'sale',
        '999.00',
        $reference
    );

    expect($second->id)
        ->toBe($first->id)
        ->and($second->amount)
        ->toBe('100.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(1);
});


it('ignores external_provider and external_reference options since only the reference parameter drives idempotency', function () {
    $first = $this->service->record(
        $this->wallet,
        'sale',
        '10.00',
        null,
        [
            'external_provider' => 'stripe',
        ]
    );

    $second = $this->service->record(
        $this->wallet,
        'sale',
        '20.00',
        null,
        [
            'external_reference' => 'pi_123',
        ]
    );

    expect($this->wallet->fresh()->balance)
        ->toBe('30.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(2)
        ->and($first->external_provider)
        ->toBeNull()
        ->and($first->external_reference)
        ->toBeNull()
        ->and($second->external_provider)
        ->toBeNull()
        ->and($second->external_reference)
        ->toBeNull();
});


it('promotes an existing pending transaction with same external reference', function () {
    $reference = new WalletTransactionReference(
        'stripe',
        'pi_pending_123'
    );

    $first = $this->service->record(
        $this->wallet,
        'sale',
        '100.00',
        $reference,
        [
            'status' => 'pending',
        ]
    );

    $second = $this->service->record(
        $this->wallet,
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
        ->and($this->wallet->fresh()->balance)
        ->toBe('100.00');
});


it('does not overwrite original payload when promoting pending transaction', function () {
    $admin = Admin::factory()->create();

    $reference = new WalletTransactionReference(
        'stripe',
        'pi_payload_lock'
    );

    $first = $this->service->record(
        $this->wallet,
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

    $second = $this->service->record(
        $this->wallet,
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
    $this->service->record(
        $this->wallet,
        'sale',
        '50.00'
    );

    $reference = new WalletTransactionReference(
        'stripe',
        'pi_pending_debit'
    );

    $transaction = $this->service->record(
        $this->wallet,
        'commission',
        '100.00',
        $reference,
        [
            'status' => 'pending',
        ]
    );

    expect(fn () => $this->service->record(
        $this->wallet,
        'commission',
        '100.00',
        $reference,
        [
            'status' => 'completed',
        ]
    ))
        ->toThrow(
            InsufficientWalletBalanceException::class,
            'Insufficient wallet balance for this transaction.'
        );

    expect($transaction->fresh()->status->slug)
        ->toBe('pending')
        ->and($this->wallet->fresh()->balance)
        ->toBe('50.00');
});

it('stores the transaction creator and keeps the relationship', function () {
    $admin = Admin::factory()->create();

    $transaction = $this->service->record(
        $this->wallet,
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
    $transaction = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );

    expect($transaction->source)
        ->toBe(TransactionSource::System);
});


it('throws when using an unknown transaction status without mutating wallet', function () {
    $snapshot = $this->wallet->fresh();

    expect(fn () =>
        $this->service->record(
            $this->wallet,
            'sale',
            '100.00',
            null,
            [
                'status' => 'unknown_status',
            ]
        )
    )->toThrow(RuntimeException::class);


    expect($this->wallet->fresh()->balance)
        ->toBe($snapshot->balance)
        ->and($this->wallet->transactions()->count())
        ->toBe(0)
        ->and($this->wallet->fresh()->last_transaction_at)
        ->toBe($snapshot->last_transaction_at);
});


it('throws when using an unknown transaction category without mutating wallet', function () {
    expect(fn () =>
        $this->service->record(
            $this->wallet,
            'invalid-category',
            '100.00'
        )
    )->toThrow(RuntimeException::class);


    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(0)
        ->and($this->wallet->fresh()->last_transaction_at)
        ->toBeNull();
});


it('keeps independent pending transactions isolated', function () {
    $first = $this->service->record(
        $this->wallet,
        'sale',
        '100.00',
        null,
        [
            'status' => 'pending',
        ]
    );

    $second = $this->service->record(
        $this->wallet,
        'sale',
        '50.00',
        null,
        [
            'status' => 'pending',
        ]
    );

    expect($first->id)
        ->not->toBe($second->id)
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(2);
});


it('does not mutate original model instance after recording', function () {
    $this->wallet->balance = '9999.00';

    $transaction = $this->service->record(
        $this->wallet,
        'sale',
        '25.00'
    );

    expect($transaction->balance_after)
        ->toBe('25.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('25.00');
});


it('handles duplicate external references safely at database level', function () {
    StoreWalletTransaction::factory()
        ->forWallet($this->wallet)
        ->sale()
        ->amount('100.00')
        ->create([
            'external_provider' => 'stripe',
            'external_reference' => 'pi_unique_reference',
        ]);


    expect(fn () =>
        StoreWalletTransaction::factory()
            ->forWallet($this->wallet)
            ->sale()
            ->amount('50.00')
            ->create([
                'external_provider' => 'stripe',
                'external_reference' => 'pi_unique_reference',
            ])
    )->toThrow(\Illuminate\Database\QueryException::class);
});


it('returns an existing transaction when external reference already exists before recording', function () {
    $reference = new WalletTransactionReference(
        'stripe',
        'race_reference'
    );


    $existing = StoreWalletTransaction::factory()
        ->forWallet($this->wallet)
        ->sale()
        ->amount('100.00')
        ->create([
            'external_provider' => $reference->provider,
            'external_reference' => $reference->reference,
        ]);


    $result = $this->service->record(
        $this->wallet,
        'sale',
        '500.00',
        $reference
    );


    expect($result->id)
        ->toBe($existing->id)
        ->and($this->wallet->transactions()->count())
        ->toBe(1);
});


it('records multiple transactions sequentially keeping balances correct', function () {
    $first = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );

    $second = $this->service->record(
        $this->wallet,
        'sale',
        '50.00'
    );

    $third = $this->service->record(
        $this->wallet,
        'commission',
        '30.00'
    );


    expect($first->balance_after)
        ->toBe('100.00')
        ->and($second->balance_after)
        ->toBe('150.00')
        ->and($third->balance_after)
        ->toBe('120.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('120.00');
});
