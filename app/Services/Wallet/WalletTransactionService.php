<?php

namespace App\Services\Wallet;

use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\TransactionCategory;
use App\Models\TransactionStatus;
use App\Enums\TransactionSource;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletTransactionService
{
    /**
     * Record a new transaction against a wallet, adjusting its balance.
     *
     * @param StoreWallet $wallet
     * @param string $categorySlug e.g. 'sale', 'withdrawal', 'commission'
     * @param string $amount always positive; direction comes from the category. Positive decimal amount (e.g. "100.00")
     * @param array $options optional keys: description, external_provider, external_reference,
     *                        referenceable, related_transaction_id, metadata, source, created_by, status
     */
    public function record(
        StoreWallet $wallet,
        string $categorySlug,
        string $amount,
        array $options = []
    ): StoreWalletTransaction {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new RuntimeException('Transaction amount must be greater than zero.');
        }

        $category = TransactionCategory::bySlugOrFail($categorySlug);
        $statusSlug = $options['status'] ?? 'completed';
        $status = TransactionStatus::bySlugOrFail($statusSlug);

        return DB::transaction(function () use ($wallet, $category, $status, $amount, $options) {
            // Lock the wallet row to prevent race conditions on concurrent transactions
            $lockedWallet = StoreWallet::query()
                ->where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $newBalance = $category->isCredit()
                ? bcadd($lockedWallet->balance, $amount, 2)
                : bcsub($lockedWallet->balance, $amount, 2);

            if (bccomp($newBalance, '0', 2) < 0) {
                throw new RuntimeException('Insufficient wallet balance for this transaction.');
            }

            $referenceable = $options['referenceable'] ?? null;

            $transaction = StoreWalletTransaction::create([
                'store_wallet_id'          => $lockedWallet->id,
                'transaction_category_id'  => $category->id,
                'transaction_status_id'    => $status->id,
                'amount'                   => $amount,
                'balance_after'            => $newBalance,
                'description'              => $options['description'] ?? null,
                'external_provider'        => $options['external_provider'] ?? null,
                'external_reference'       => $options['external_reference'] ?? null,
                'referenceable_type'       => $referenceable?->getMorphClass(),
                'referenceable_id'         => $referenceable?->id,
                'related_transaction_id'   => $options['related_transaction_id'] ?? null,
                'metadata'                 => $options['metadata'] ?? null,
                'source'                   => $options['source'] ?? TransactionSource::System,
                'created_by'               => $options['created_by'] ?? null,
            ]);

            $lockedWallet->update([
                'balance'             => $newBalance,
                'last_transaction_at' => now(),
            ]);

            return $transaction;
        });
    }

    /**
     * Reverse an existing transaction (e.g. refund, chargeback reversal).
     * Creates a new transaction with the opposite direction, linked to the original.
     */
    public function reverse(
        StoreWalletTransaction $original,
        string $reversalCategorySlug,
        ?string $description = null,
        array $options = []
    ): StoreWalletTransaction {

        if (!$original->isCompleted()) {
            throw new RuntimeException('Only completed transactions can be reversed.');
        }

        if ($original->isReversal()) {
            throw new RuntimeException('Cannot reverse a transaction that is itself a reversal.');
        }

        return $this->record(
            wallet: $original->storeWallet,
            categorySlug: $reversalCategorySlug,
            amount: $original->amount,
            options: array_merge($options, [
                'description'             => $description ?? "Reversal of transaction #{$original->id}",
                'related_transaction_id'  => $original->id,
            ])
        );
    }

    /**
     * Mark a pending transaction as failed, without affecting the wallet balance.
     * Use this when a transaction was recorded as pending but never completed.
     */
    public function markFailed(StoreWalletTransaction $transaction, ?string $reason = null): StoreWalletTransaction
    {
        return DB::transaction(function () use ($transaction, $reason) {
            if (!$transaction->isPending()) {
                throw new RuntimeException('Only pending transactions can be marked as failed.');
            }

            $failedStatus = TransactionStatus::bySlugOrFail('failed');

            $transaction->update([
                'transaction_status_id' => $failedStatus->id,
                'description' => $reason
                    ? trim(($transaction->description ?? '') . ' | ' . $reason)
                    : $transaction->description,
            ]);

            return $transaction->fresh();
        });
    }
}