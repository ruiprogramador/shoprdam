<?php

use App\Domain\Wallet\Exceptions\CannotReverseReversalException;
use App\Domain\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Domain\Wallet\Exceptions\TransactionAlreadyReversedException;
use App\Domain\Wallet\Exceptions\TransactionNotCompletedException;
use App\Domain\Wallet\Exceptions\WalletMismatchException;
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


it('creates a reversal transaction and restores the wallet balance', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );

    $reversal = $this->service->reverse(
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
        ->toBe($this->wallet->id)
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
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00');
});


it('creates a reversal for debit transactions correctly', function () {
    $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );

    $commission = $this->service->record(
        $this->wallet,
        'commission',
        '30.00'
    );


    $reversal = $this->service->reverse(
        $commission,
        'commission_refund'
    );


    expect($reversal->amount)
        ->toBe('30.00')
        ->and($reversal->balance_after)
        ->toBe('100.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('100.00');
});


it('preserves all original transaction fields when creating a reversal', function () {
    $sale = $this->service->record(
        $this->wallet,
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
    $originalAmount = $sale->amount;
    $originalCategorySlug = $sale->category->slug;
    $originalBalanceAfter = $sale->balance_after;

    $this->service->reverse(
        $sale,
        'customer_refund'
    );

    $sale = $sale->fresh(['category', 'status']);

    expect($sale->id)
        ->toBe($originalId)
        ->and($sale->description)
        ->toBe('Original order')
        ->and($sale->metadata)
        ->toBe([
            'order_id' => 123,
        ])
        ->and($sale->amount)
        ->toBe($originalAmount)
        ->and($sale->category->slug)
        ->toBe($originalCategorySlug)
        ->and($sale->balance_after)
        ->toBe($originalBalanceAfter)
        ->and($sale->status->slug)
        ->toBe('completed');
});


it('keeps its own external reference on the original transaction after it is reversed', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00',
        new WalletTransactionReference('stripe', 'original_charge_123')
    );

    $this->service->reverse(
        $sale,
        'customer_refund',
        null,
        new WalletTransactionReference('stripe', 'refund_of_charge_123')
    );

    $original = $sale->fresh();

    expect($original->external_provider)
        ->toBe('stripe')
        ->and($original->external_reference)
        ->toBe('original_charge_123');
});


it('updates wallet last_transaction_at when reversing a completed transaction', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );

    $before = $this->wallet->fresh()->last_transaction_at;

    $this->travel(1)->minutes();

    $this->service->reverse(
        $sale,
        'customer_refund'
    );

    $after = $this->wallet->fresh()->last_transaction_at;

    expect($after)
        ->not->toBeNull()
        ->and($after->greaterThan($before))
        ->toBeTrue();
});


it('uses default reversal description when none is supplied', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $reversal = $this->service->reverse(
        $sale,
        'customer_refund'
    );


    expect($reversal->description)
        ->toBe(
            "Reversal of transaction #{$sale->id}"
        );
});


it('preserves custom reversal description and metadata', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $reversal = $this->service->reverse(
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
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );

    $sale->amount = '9999.00';
    $sale->transaction_category_id = null;
    $sale->store_wallet_id = null;

    $reversal = $this->service->reverse(
        $sale,
        'customer_refund'
    );


    expect($reversal->amount)
        ->toBe('100.00')
        ->and($reversal->related_transaction_id)
        ->toBe($sale->id)
        ->and($reversal->balance_after)
        ->toBe('0.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00');
});


it('ignores dirty wallet changes when reversing', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $this->wallet->balance = '9999.00';


    $reversal = $this->service->reverse(
        $sale,
        'customer_refund'
    );


    expect($reversal->balance_after)
        ->toBe('0.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00');
});


it('throws when reversing a pending transaction', function () {
    $pending = $this->service->record(
        $this->wallet,
        'sale',
        '100.00',
        null,
        [
            'status' => 'pending',
        ]
    );


    expect(fn () =>
        $this->service->reverse(
            $pending,
            'customer_refund'
        )
    )->toThrow(
        TransactionNotCompletedException::class,
        'Only completed transactions can be reversed.'
    );


    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(1);
});


it('cannot reverse a reversal transaction', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $reversal = $this->service->reverse(
        $sale,
        'customer_refund'
    );


    expect(fn () =>
        $this->service->reverse(
            $reversal,
            'commission_refund'
        )
    )->toThrow(
        CannotReverseReversalException::class,
        'Cannot reverse a transaction that is itself a reversal.'
    );
});


it('does not allow the same transaction to be reversed twice', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $this->service->reverse(
        $sale,
        'customer_refund'
    );


    expect(fn () =>
        $this->service->reverse(
            $sale,
            'customer_refund'
        )
    )->toThrow(
        TransactionAlreadyReversedException::class,
        'This transaction has already been reversed.'
    );


    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(2);
});


it('does not allow reversal from another wallet', function () {
    $storeA = Store::factory()->create();
    $walletA = $storeA->wallets()->first();


    $storeB = Store::factory()->create();
    $walletB = $storeB->wallets()->first();


    $sale = $this->service->record(
        $walletA,
        'sale',
        '100.00'
    );


    expect(fn () =>
        $this->service->reverse(
            $sale,
            'customer_refund',
            wallet: $walletB
        )
    )->toThrow(
        WalletMismatchException::class,
        'Cannot reverse a transaction using a different wallet.'
    );


    expect($walletA->fresh()->balance)
        ->toBe('100.00')
        ->and($walletB->fresh()->balance)
        ->toBe('0.00')
        ->and($walletA->transactions()->count())
        ->toBe(1);
});


it('throws when reversing would result in negative wallet balance', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );

    $this->service->record(
        $this->wallet,
        'commission',
        '80.00'
    );

    expect($this->wallet->fresh()->balance)
        ->toBe('20.00');

    $snapshot = $sale->fresh();

    expect(fn () =>
        $this->service->reverse(
            $sale,
            'customer_refund'
        )
    )->toThrow(
        InsufficientWalletBalanceException::class,
        'Insufficient wallet balance for this transaction.'
    );

    expect($this->wallet->fresh()->balance)
        ->toBe('20.00')
        ->and($sale->fresh()->status->slug)
        ->toBe($snapshot->status->slug)
        ->and($this->wallet->transactions()->count())
        ->toBe(2);
});


it('keeps original reversal payload when duplicate external reference completes later', function () {
    $admin = Admin::factory()->create();


    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $reference = new WalletTransactionReference(
        'stripe',
        'refund_duplicate_payload'
    );


    $first = $this->service->reverse(
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


    $second = $this->service->reverse(
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
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $reference = new WalletTransactionReference(
        'stripe',
        'refund_existing_reference'
    );


    $first = $this->service->reverse(
        $sale,
        'customer_refund',
        null,
        $reference
    );


    $second = $this->service->reverse(
        $sale,
        'customer_refund',
        null,
        $reference
    );


    expect($second->id)
        ->toBe($first->id)
        ->and($second->related_transaction_id)
        ->toBe($sale->id)
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(2);
});


it('promotes a pending reversal to completed without creating another transaction', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $reference = new WalletTransactionReference(
        'stripe',
        'refund_pending_promote'
    );


    $pending = $this->service->reverse(
        $sale,
        'customer_refund',
        null,
        $reference,
        [
            'status' => 'pending',
        ]
    );


    expect($this->wallet->fresh()->balance)
        ->toBe('100.00');


    $completed = $this->service->reverse(
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
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(2);
});


it('preserves external reference information on reversal', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $reference = new WalletTransactionReference(
        'stripe',
        'refund_external_123'
    );


    $reversal = $this->service->reverse(
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
    $admin = Admin::factory()->create();


    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $reversal = $this->service->reverse(
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
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $reversal = $this->service->reverse(
        $sale,
        'customer_refund'
    );


    expect($reversal->source)
        ->toBe(TransactionSource::System);
});


it('handles decimal amounts correctly when reversing', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '99.99'
    );


    $reversal = $this->service->reverse(
        $sale,
        'customer_refund'
    );


    expect($reversal->amount)
        ->toBe('99.99')
        ->and($reversal->balance_after)
        ->toBe('0.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00');
});


it('does not create duplicate reversal rows during race condition lookup', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $reference = new WalletTransactionReference(
        'stripe',
        'refund_race_condition'
    );


    $existing = StoreWalletTransaction::factory()
        ->forWallet($this->wallet)
        ->customerRefund()
        ->amount('100.00')
        ->create([
            'related_transaction_id' => $sale->id,
            'external_provider' => $reference->provider,
            'external_reference' => $reference->reference,
        ]);


    $result = $this->service->reverse(
        $sale,
        'customer_refund',
        null,
        $reference
    );


    expect($result->id)
        ->toBe($existing->id);
});


it('does not allow two different reversal references for the same transaction', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );


    $this->service->reverse(
        $sale,
        'customer_refund',
        null,
        new WalletTransactionReference(
            'stripe',
            'refund_one'
        )
    );


    expect(fn () =>
        $this->service->reverse(
            $sale,
            'customer_refund',
            null,
            new WalletTransactionReference(
                'stripe',
                'refund_two'
            )
        )
    )->toThrow(
        TransactionAlreadyReversedException::class,
        'This transaction has already been reversed.'
    );


    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(2);
});


it('keeps the wallet balance correct when reversing one transaction and confirming another pending one', function () {
    $sale = $this->service->record(
        $this->wallet,
        'sale',
        '100.00'
    );

    $pendingSale = $this->service->record(
        $this->wallet,
        'sale',
        '40.00',
        options: [
            'status' => 'pending',
        ]
    );

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00');

    $this->service->reverse($sale, 'customer_refund');

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00');

    $this->service->confirm($pendingSale);

    expect($this->wallet->fresh()->balance)
        ->toBe('40.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(3);
});
