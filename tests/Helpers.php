<?php

use App\Domain\Wallet\WalletTransactionReference;
use App\Models\Store;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletTransactionService;

function recordTransaction(
    string $category,
    string $amount,
    ?WalletTransactionReference $reference = null,
    array $options = []
): StoreWalletTransaction {
    return test()->service->record(test()->wallet, $category, $amount, $reference, $options);
}

function recordPendingTransaction(string $category, string $amount, array $options = []): StoreWalletTransaction
{
    return recordTransaction($category, $amount, options: ['status' => 'pending', ...$options]);
}

function walletService(): WalletTransactionService
{
    return app(WalletTransactionService::class);
}

/**
 * @return array{0: Store, 1: StoreWallet}
 */
function createStoreWithWallet(): array
{
    $store = Store::factory()->create();

    return [$store, $store->wallets()->first()];
}

function expectWalletUnchanged(StoreWallet $before): void
{
    $after = $before->fresh();

    expect($after->balance)
        ->toBe($before->balance)
        ->and($after->last_transaction_at)
        ->toEqual($before->last_transaction_at);
}
