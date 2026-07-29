<?php

use App\Domain\Wallet\Exceptions\TransactionNotPendingException;
use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\Admin;

beforeEach(function () {
    $this->service = walletService();
    [$this->store, $this->wallet] = createStoreWithWallet();
});

it('marks a pending transaction as failed', function () {
    $transaction = recordPendingTransaction('sale', '100.00');

    $failed = $this->service->markFailed(
        $transaction,
        'Card declined'
    );

    $failed = $failed->fresh();

    expect($failed->id)
        ->toBe($transaction->id)
        ->and($failed->store_wallet_id)
        ->toBe($this->wallet->id)
        ->and($failed->status->slug)
        ->toBe('failed')
        ->and($failed->isFailed())
        ->toBeTrue()
        ->and($failed->description)
        ->toContain('Card declined');
});

it('does not affect wallet balance when marking a transaction as failed', function () {
    recordTransaction('sale', '50.00');

    $transaction = recordPendingTransaction('sale', '100.00');

    $balanceBefore = $this->wallet->fresh()->balance;

    $this->service->markFailed($transaction);

    expect($this->wallet->fresh()->balance)
        ->toBe($balanceBefore);
});

it('appends failure reason to an existing description', function () {
    $transaction = $this->service->record($this->wallet, 'sale', '100.00', options: [
        'status' => 'pending',
        'description' => 'Order #1023',
    ]);

    $failed = $this->service->markFailed(
        $transaction,
        'Card declined'
    );

    expect($failed->description)
        ->toBe('Order #1023 | Card declined');
});

it('keeps description unchanged when failure reason is empty', function () {
    $transaction = $this->service->record($this->wallet, 'sale', '100.00', options: [
        'status' => 'pending',
        'description' => 'Order #1023',
    ]);

    $failed = $this->service->markFailed($transaction);

    expect($failed->description)
        ->toBe('Order #1023')
        ->and($failed->status->slug)
        ->toBe('failed');
});

it('throws when marking a non pending transaction as failed', function () {
    $transaction = recordTransaction('sale', '100.00');

    expect(fn () => $this->service->markFailed($transaction))
        ->toThrow(
            TransactionNotPendingException::class,
            'Only pending transactions can be marked as failed.'
        );
});

it('throws when marking an already failed transaction as failed again', function () {
    $transaction = recordPendingTransaction('sale', '100.00');

    $this->service->markFailed($transaction);

    expect(fn () => $this->service->markFailed($transaction))
        ->toThrow(
            TransactionNotPendingException::class,
            'Only pending transactions can be marked as failed.'
        );
});

it('ignores dirty in-memory transaction changes when marking failed', function () {
    $transaction = $this->service->record(
        $this->wallet,
        'sale',
        '100.00',
        options: [
            'status' => 'pending',
            'description' => 'Original description',
        ]
    );

    $transaction->amount = '9999.00';
    $transaction->description = 'Fake description';

    $failed = $this->service->markFailed(
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
    $admin = Admin::factory()->create();

    $transaction = $this->service->record($this->wallet, 'sale', '100.00', options: [
        'status' => 'pending',
        'metadata' => [
            'order_id' => 123,
        ],
        'created_by' => $admin->id,
        'source' => TransactionSource::Webhook,
    ]);

    $failed = $this->service->markFailed(
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
    $transaction = $this->service->record(
        $this->wallet,
        'sale',
        '100.00',
        new WalletTransactionReference('stripe', 'pi_failed_123'),
        [
            'status' => 'pending',
        ]
    );

    $failed = $this->service->markFailed(
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
    recordTransaction('sale', '50.00');

    $transaction = recordPendingTransaction('sale', '100.00');

    expect($transaction->balance_after)
        ->toBe('50.00');

    $failed = $this->service->markFailed($transaction);

    expect($failed->balance_after)
        ->toBe('50.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('50.00');
});

it('does not update wallet last transaction timestamp when marking failed', function () {
    recordTransaction('sale', '50.00');

    $walletBefore = $this->wallet->fresh();

    $transaction = recordPendingTransaction('sale', '100.00');

    $this->service->markFailed($transaction);

    expectWalletUnchanged($walletBefore);
});

it('updates the timestamp but not the creation date when marking failed', function () {
    $transaction = recordPendingTransaction('sale', '100.00');

    $createdAt = $transaction->created_at;

    $this->travel(1)->minutes();

    $failed = $this->service->markFailed($transaction);

    expect($failed->created_at)
        ->toEqual($createdAt)
        ->and($failed->updated_at)
        ->not->toEqual($createdAt)
        ->and($failed->updated_at->greaterThan($createdAt))
        ->toBeTrue();
});

it('ignores dirty wallet changes when marking failed', function () {
    $transaction = recordPendingTransaction('sale', '100.00');

    $this->wallet->balance = '9999.00';

    $failed = $this->service->markFailed($transaction);

    expect($failed->status->slug)
        ->toBe('failed')
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00');
});

it('marks a pending transaction loaded from database as failed', function () {
    $transaction = recordPendingTransaction('sale', '100.00');

    $staleTransaction = $transaction;

    $freshTransaction = $transaction->fresh();

    expect($freshTransaction->status->slug)
        ->toBe('pending');

    $failed = $this->service->markFailed(
        $staleTransaction,
        'Gateway timeout'
    );

    expect($failed->status->slug)
        ->toBe('failed')
        ->and($failed->description)
        ->toContain('Gateway timeout');
});

it('does not mutate transaction amount when marking failed', function () {
    $transaction = recordPendingTransaction('sale', '125.50');

    $amountBefore = $transaction->amount;

    $failed = $this->service->markFailed(
        $transaction,
        'Payment rejected'
    );

    expect($failed->amount)
        ->toBe($amountBefore);
});

it('preserves related_transaction_id when marking a pending reversal as failed', function () {
    $sale = recordTransaction('sale', '100.00');

    $pendingReversal = $this->service->reverse(
        $sale,
        'customer_refund',
        options: ['status' => 'pending']
    );

    $failed = $this->service->markFailed(
        $pendingReversal,
        'Refund rejected by processor'
    );

    expect($failed->related_transaction_id)
        ->toBe($sale->id)
        ->and($failed->status->slug)
        ->toBe('failed');
});

it('keeps failed transaction data after refreshing model', function () {
    $transaction = $this->service->record(
        $this->wallet,
        'sale',
        '80.00',
        options: [
            'status' => 'pending',
            'metadata' => [
                'attempt' => 1,
            ],
        ]
    );

    $this->service->markFailed(
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
    $transaction = recordPendingTransaction('sale', '100.00');

    $countBefore = $this->wallet->transactions()->count();

    $this->service->markFailed($transaction);

    expect($this->wallet->transactions()->count())
        ->toBe($countBefore)
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00');
});

it('keeps wallet state unchanged when marking failed twice concurrently', function () {
    $transaction = recordPendingTransaction('sale', '100.00');

    $this->service->markFailed(
        $transaction,
        'First attempt'
    );

    expect(fn () => $this->service->markFailed(
        $transaction,
        'Second attempt'
    ))
        ->toThrow(
            TransactionNotPendingException::class,
            'Only pending transactions can be marked as failed.'
        );

    expect($transaction->fresh()->status->slug)
        ->toBe('failed')
        ->and($this->wallet->fresh()->balance)
        ->toBe('0.00');
});
