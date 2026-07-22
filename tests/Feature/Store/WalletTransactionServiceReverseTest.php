<?php

use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\Admin;
use App\Models\Store;
use App\Services\Wallet\WalletTransactionService;

it('creates a reversal transaction and restores the wallet balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');

    $reversal = $service->reverse($sale, 'customer_refund');

    $reversal->load([
        'category',
        'status',
        'storeWallet',
        'relatedTransaction',
    ]);

    expect($reversal->id)
        ->not->toBe($sale->id)
        ->and($reversal->store_wallet_id)->toBe($wallet->id)
        ->and($reversal->amount)->toBe('100.00')
        ->and($reversal->balance_after)->toBe('0.00')
        ->and($reversal->related_transaction_id)->toBe($sale->id)
        ->and($reversal->relatedTransaction->is($sale))->toBeTrue()
        ->and($reversal->category->slug)->toBe('customer_refund')
        ->and($reversal->status->slug)->toBe('completed')
        ->and($reversal->isReversal())->toBeTrue();

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);
});

it('creates a reversal transaction for a debit transaction and restores the wallet balance', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $service->record($wallet, 'sale', '100.00');

    $commission = $service->record($wallet, 'commission', '30.00');

    $reversal = $service->reverse($commission, 'commission_refund');

    expect($reversal->category->slug)->toBe('commission_refund')
        ->and($reversal->amount)->toBe('30.00')
        ->and($reversal->balance_after)->toBe('100.00')
        ->and($reversal->related_transaction_id)->toBe($commission->id)
        ->and($wallet->fresh()->balance)->toBe('100.00')
        ->and($wallet->transactions()->count())->toBe(3);
});

it('preserves custom data when creating a reversal transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $admin = Admin::factory()->create();

    $sale = $service->record($wallet, 'sale', '100.00');

    $reversal = $service->reverse($sale, 'customer_refund', 'Manual refund', null, [
        'metadata' => [
            'reason' => 'duplicate_charge',
        ],
        'source' => TransactionSource::Webhook,
        'created_by' => $admin->id,
    ]);

    $reversal = $reversal->fresh();

    expect($reversal->description)
        ->toBe('Manual refund')
        ->and($reversal->metadata)
        ->toBe([
            'reason' => 'duplicate_charge',
        ])
        ->and($reversal->source)
        ->toBe(TransactionSource::Webhook)
        ->and($reversal->created_by)
        ->toBe($admin->id)
        ->and($reversal->createdBy->is($admin))
        ->toBeTrue()
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});

it('returns the existing reversal for duplicated external reference', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');
    $reference = new WalletTransactionReference('stripe', 'refund_pi_123');

    $first = $service->reverse($sale, 'customer_refund', null, $reference);

    $second = $service->reverse($sale, 'customer_refund', null, $reference);

    expect($second->id)
        ->toBe($first->id)
        ->and($second->related_transaction_id)->toBe($sale->id)
        ->and($second->balance_after)->toBe('0.00')
        ->and($wallet->fresh()->balance)->toBe('0.00')
        ->and($wallet->transactions()->count())->toBe(2);
});

it('promotes a pending reversal to completed for duplicated external reference', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');
    $reference = new WalletTransactionReference('stripe', 'refund_pending_123');

    $first = $service->reverse($sale, 'customer_refund', null, $reference, [
        'status' => 'pending',
    ]);

    $second = $service->reverse($sale, 'customer_refund', null, $reference, [
        'status' => 'completed',
    ]);

    expect($second->id)->toBe($first->id)
        ->and($second->status->slug)->toBe('completed')
        ->and($second->balance_after)->toBe('0.00')
        ->and($wallet->fresh()->balance)->toBe('0.00')
        ->and($wallet->transactions()->count())->toBe(2)
        ->and($sale->fresh()->childTransactions)->toHaveCount(1);
});

it('uses a default description when none is provided', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');

    $reversal = $service->reverse($sale, 'customer_refund');

    expect($reversal->description)
        ->toBe("Reversal of transaction #{$sale->id}");
});

it('throws an exception when reversing a pending transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00', null, [
        'status' => 'pending',
    ]);

    expect(fn () => $service->reverse($sale, 'customer_refund'))
        ->toThrow(
            RuntimeException::class,
            'Only completed transactions can be reversed.'
        );

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(1);
});

it('throws an exception when reversing an unknown category', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');

    expect(fn () => $service->reverse($sale, 'drop-table'))
        ->toThrow(RuntimeException::class);

    expect($wallet->fresh()->balance)
        ->toBe('100.00')
        ->and($wallet->transactions()->count())
        ->toBe(1);
});

it('cannot reverse a reversal transaction', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');

    $reversal = $service->reverse($sale, 'customer_refund');

    expect(fn () => $service->reverse($reversal, 'commission_refund'))
        ->toThrow(
            RuntimeException::class,
            'Cannot reverse a transaction that is itself a reversal.'
        );
});

it('cannot reverse the same transaction twice without idempotency key', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');

    $service->reverse($sale, 'customer_refund');

    expect(fn () => $service->reverse($sale, 'customer_refund'))
        ->toThrow(
            RuntimeException::class,
            'This transaction has already been reversed.'
        );

    expect($wallet->fresh()->balance)
        ->toBe('0.00')
        ->and($wallet->transactions()->count())
        ->toBe(2);
});

it('ignores dirty in-memory transaction changes when reversing', function () {
    $service = app(WalletTransactionService::class);

    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();

    $sale = $service->record($wallet, 'sale', '100.00');

    $sale->amount = '9999.00';
    $sale->transaction_category_id = null;

    $reversal = $service->reverse($sale, 'customer_refund');

    expect($reversal->amount)
        ->toBe('100.00')
        ->and($reversal->balance_after)
        ->toBe('0.00')
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});
