<?php

use App\Domain\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Domain\Wallet\Exceptions\TransactionNotPendingException;
use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\Admin;
use App\Models\StoreWallet;
use App\Models\TransactionStatus;

beforeEach(function () {
    $this->service = walletService();
    [$this->store, $this->wallet] = createStoreWithWallet();
});

it('confirms a pending credit transaction and increases the wallet balance', function () {
    $transaction = recordPendingTransaction('sale', '100.00');

    $wallet = $this->wallet->fresh();

    expect($wallet->balance)->toBe('0.00');

    $confirmed = $this->service->confirm($transaction);

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
    recordTransaction('sale', '100.00');

    $transaction = recordPendingTransaction('commission', '30.00');

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00');

    $confirmed = $this->service->confirm($transaction);

    $wallet = $this->wallet->fresh();

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
    recordTransaction('sale', '40.00');

    $transaction = recordPendingTransaction('commission', '40.00');

    $confirmed = $this->service->confirm($transaction);

    $wallet = $this->wallet->fresh();
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
    $transaction = recordPendingTransaction('sale', '75.00');

    $staleTransaction = $transaction;

    $freshTransaction = $transaction->fresh();

    expect($freshTransaction->status->slug)
        ->toBe('pending');

    $confirmed = $this->service->confirm($staleTransaction);

    expect($confirmed->status->slug)
        ->toBe('completed')
        ->and($confirmed->amount)
        ->toBe('75.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('75.00');
});

it('throws an exception when confirming a non-pending transaction', function (string $statusSlug) {
    $transaction = $this->service->record($this->wallet, 'sale', '100.00', options: [
        'status' => $statusSlug,
    ]);

    $walletSnapshot = $this->wallet->fresh();
    $transactionSnapshot = $transaction->fresh();

    expect(fn () => $this->service->confirm($transaction))
        ->toThrow(
            TransactionNotPendingException::class,
            'Only pending transactions can be confirmed.'
        );

    expect($this->wallet->fresh()->balance)
        ->toBe($walletSnapshot->balance)
        ->and($transaction->fresh()->status->slug)
        ->toBe($transactionSnapshot->status->slug);

})->with([
    'completed',
    'failed',
]);

it('throws an exception when confirming would result in negative balance', function () {
    $transaction = recordPendingTransaction('commission', '50.00');

    $snapshot = $transaction->fresh();

    expect(fn () => $this->service->confirm($transaction))
        ->toThrow(
            InsufficientWalletBalanceException::class,
            'Insufficient wallet balance to confirm this transaction.'
        );

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($this->wallet->fresh()->last_transaction_at)
        ->toBeNull()
        ->and($transaction->fresh()->status->slug)
        ->toBe('pending')
        ->and($transaction->fresh()->balance_after)
        ->toBe($snapshot->balance_after);
});

it('throws an exception when confirming an already confirmed transaction', function () {
    $transaction = recordPendingTransaction('sale', '100.00');

    $this->service->confirm($transaction);

    expect(fn () => $this->service->confirm($transaction))
        ->toThrow(
            TransactionNotPendingException::class,
            'Only pending transactions can be confirmed.'
        );

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($this->wallet->transactions()->count())
        ->toBe(1);
});

it('ignores dirty in-memory changes when confirming a pending transaction', function () {
    $transaction = recordPendingTransaction('sale', '25.00');

    $transaction->amount = '999.00';
    $transaction->transaction_status_id = TransactionStatus::bySlugOrFail('completed')->id;

    $this->wallet->balance = '9999.00';

    $confirmed = $this->service->confirm($transaction);

    expect($confirmed->amount)
        ->toBe('25.00')
        ->and($confirmed->status->slug)
        ->toBe('completed')
        ->and($confirmed->balance_after)
        ->toBe('25.00')
        ->and($this->wallet->fresh()->balance)
        ->toBe('25.00')
        ->and($confirmed->fresh()->amount)
        ->toBe('25.00');
});

it('preserves external reference when confirming a transaction', function () {
    $transaction = $this->service->record(
        $this->wallet,
        'sale',
        '100.00',
        new WalletTransactionReference('stripe', 'pi_confirm_123'),
        [
            'status' => 'pending',
        ]
    );

    $confirmed = $this->service->confirm($transaction);

    $confirmed = $confirmed->fresh();

    expect($confirmed->status->slug)
        ->toBe('completed')
        ->and($confirmed->external_provider)
        ->toBe('stripe')
        ->and($confirmed->external_reference)
        ->toBe('pi_confirm_123');
});

it('preserves descriptive transaction data when confirming', function () {
    $transaction = $this->service->record($this->wallet, 'sale', '100.00', options: [
        'status' => 'pending',
        'description' => 'Order #123',
        'metadata' => [
            'order_id' => 123,
        ],
    ]);

    $confirmed = $this->service->confirm($transaction);

    $confirmed = $confirmed->fresh();

    expect($confirmed->description)
        ->toBe('Order #123')
        ->and($confirmed->metadata)
        ->toBe([
            'order_id' => 123,
        ]);
});

it('preserves transaction ownership and source when confirming', function () {
    $admin = Admin::factory()->create();

    $transaction = $this->service->record($this->wallet, 'sale', '100.00', options: [
        'status' => 'pending',
        'source' => TransactionSource::Webhook,
        'created_by' => $admin->id,
    ]);

    $transaction->source = TransactionSource::System;
    $transaction->created_by = null;

    $confirmed = $this->service->confirm($transaction);

    $confirmed = $confirmed->fresh();

    expect($confirmed->source)
        ->toBe(TransactionSource::Webhook)
        ->and($confirmed->created_by)
        ->toBe($admin->id)
        ->and($confirmed->createdBy->is($admin))
        ->toBeTrue();
});

it('preserves referenceable when confirming a transaction', function () {
    $transaction = $this->service->record($this->wallet, 'sale', '100.00', null, [
        'status' => 'pending',
        'referenceable' => $this->store,
    ]);

    $confirmed = $this->service->confirm($transaction);

    $confirmed = $confirmed->fresh();

    expect($confirmed->referenceable_type)
        ->toBe($this->store->getMorphClass())
        ->and($confirmed->referenceable_id)
        ->toBe($this->store->id)
        ->and($confirmed->referenceable->is($this->store))
        ->toBeTrue();
});

it('confirms two independent pending transactions correctly', function () {
    $first = recordPendingTransaction('sale', '100.00');

    $second = recordPendingTransaction('sale', '50.00');

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00');

    $this->service->confirm($first);

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($first->fresh()->balance_after)
        ->toBe('100.00');

    $this->service->confirm($second);

    expect($this->wallet->fresh()->balance)
        ->toBe('150.00')
        ->and($second->fresh()->balance_after)
        ->toBe('150.00');
});

it('confirms a pending debit transaction after a pending credit transaction in sequence', function () {
    $credit = recordPendingTransaction('sale', '100.00');

    $debit = recordPendingTransaction('commission', '30.00');

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00');

    $this->service->confirm($credit);

    expect($this->wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($credit->fresh()->balance_after)
        ->toBe('100.00');

    $this->service->confirm($debit);

    expect($this->wallet->fresh()->balance)
        ->toBe('70.00')
        ->and($debit->fresh()->balance_after)
        ->toBe('70.00');
});

it('does not mutate wallet when confirmation fails', function () {
    $transaction = recordPendingTransaction('commission', '100.00');

    $walletBefore = $this->wallet->fresh();

    expect(fn () => $this->service->confirm($transaction))
        ->toThrow(InsufficientWalletBalanceException::class);

    expectWalletUnchanged($walletBefore);

    expect($transaction->fresh()->status->slug)
        ->toBe('pending');
});

it('rolls back the transaction status if the wallet update fails', function () {
    $transaction = recordPendingTransaction('sale', '100.00');

    StoreWallet::updating(function () {
        throw new RuntimeException('Simulated failure during wallet update.');
    });

    expect(fn () => $this->service->confirm($transaction))
        ->toThrow(RuntimeException::class, 'Simulated failure during wallet update.');

    expect($this->wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($transaction->fresh()->status->slug)
        ->toBe('pending');
});

it('updates last_transaction_at when confirming a pending transaction', function () {
    $before = $this->wallet->fresh()->last_transaction_at;

    $transaction = recordPendingTransaction('sale', '100.00');

    $this->service->confirm($transaction);

    $wallet = $this->wallet->fresh();

    expect($wallet->last_transaction_at)
        ->not->toBeNull()
        ->and($wallet->last_transaction_at)
        ->not->toBe($before);
});
