<?php

namespace App\Services\Wallet;

use App\Domain\Wallet\WalletTransactionReference;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\TransactionCategory;
use App\Models\TransactionStatus;
use App\Enums\TransactionSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletTransactionService
{
    /**
     * Record a new transaction against a wallet, adjusting its balance.
     * 
     * Idempotent transactions are immutable.
     * Later duplicate events must not overwrite the original payload.
     * The first persisted event is the source of truth.
     *
     * @param StoreWallet $wallet
     * @param string $categorySlug e.g. 'sale', 'withdrawal', 'commission'
     * @param string $amount always positive; direction comes from the category. Positive decimal amount (e.g. "100.00")
     * @param WalletTransactionReference|null $reference optional external reference for idempotency
     * @param array $options optional keys: description,referenceable, related_transaction_id
     * , metadata, source, created_by, status
     */
    public function record(
        StoreWallet $wallet,
        string $categorySlug,
        string $amount,
        ?WalletTransactionReference $reference = null,
        array $options = []
    ): StoreWalletTransaction {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new RuntimeException(
                'Transaction amount must be greater than zero.'
            );
        }

        $category = TransactionCategory::bySlugOrFail($categorySlug);
        $status = TransactionStatus::bySlugOrFail(
            $options['status'] ?? 'completed'
        );

        return DB::transaction(function () use (
            $wallet,
            $category,
            $status,
            $amount,
            $reference,
            $options
        ) {
            if ($reference !== null) {
                $existing = StoreWalletTransaction::query()
                    ->where('external_provider', $reference->provider)
                    ->where('external_reference', $reference->reference)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $existing->loadMissing('status', 'category');

                    if ($status->isCompleted() && $existing->isPending()) {
                        return $this->promotePendingTransactionToCompleted(
                            $existing
                        );
                    }

                    return $existing;
                }
            }

            $lockedWallet = StoreWallet::query()
                ->lockForUpdate()
                ->findOrFail($wallet->id);

            $isCompleted = $status->isCompleted();

            if ($isCompleted) {
                $newBalance = $this->calculateNewBalance(
                    currentBalance: $lockedWallet->balance,
                    amount: $amount,
                    isCredit: $category->isCredit(),
                    insufficientFundsMessage:
                        'Insufficient wallet balance for this transaction.'
                );
            } else {
                $newBalance = $lockedWallet->balance;
            }

            $referenceable = $options['referenceable'] ?? null;

            try {
                $transaction = StoreWalletTransaction::create([
                    'store_wallet_id' => $lockedWallet->id,
                    'transaction_category_id' => $category->id,
                    'transaction_status_id' => $status->id,

                    'amount' => $amount,
                    'balance_after' => $newBalance,

                    'external_provider' => $reference?->provider,
                    'external_reference' => $reference?->reference,

                    'description' => $options['description'] ?? null,
                    'referenceable_type' => $referenceable?->getMorphClass(),
                    'referenceable_id' => $referenceable?->id,
                    'related_transaction_id' =>
                        $options['related_transaction_id'] ?? null,

                    'metadata' => $options['metadata'] ?? null,
                    'source' => $options['source'] ?? TransactionSource::System,
                    'created_by' => $options['created_by'] ?? null,
                ]);
            } catch (QueryException $e) {
                if ($reference && $e->getCode() === '23000') {
                    $existing = StoreWalletTransaction::query()
                        ->where('external_provider', $reference->provider)
                        ->where('external_reference', $reference->reference)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $existing->loadMissing('status', 'category');

                    if ($status->isCompleted() && $existing->isPending()) {
                        return $this->promotePendingTransactionToCompleted(
                            $existing
                        );
                    }

                    return $existing;
                }

                throw $e;
            }

            if ($isCompleted) {
                $lockedWallet->update([
                    'balance' => $newBalance,
                    'last_transaction_at' => now(),
                ]);
            }

            return $transaction->fresh();
        });
    }

    /**
     * Reverse an existing transaction (e.g. refund, chargeback reversal).
     * Creates a new transaction with the opposite direction, linked to the original.
     * 
     * A reversal follows the same immutability rule as normal transactions.
     * Duplicate webhook deliveries return the existing reversal.
     * 
     * @param StoreWalletTransaction $original the transaction to reverse
     * @param string $reversalCategorySlug e.g. 'customer_refund', 'commission_refund'
     * @param string|null $description optional description for the reversal transaction
     * @param WalletTransactionReference|null $reference optional external reference for idempotency
     * @param array $options optional keys: metadata, source, created_by, status
     * @return StoreWalletTransaction the reversal transaction
     * @throws RuntimeException if the original transaction is not completed or is already a reversal
     */
    public function reverse(
        StoreWalletTransaction $original,
        string $reversalCategorySlug,
        ?string $description = null,
        ?WalletTransactionReference $reference = null,
        array $options = []
    ): StoreWalletTransaction {
        return DB::transaction(function () use (
            $original,
            $reversalCategorySlug,
            $description,
            $reference,
            $options
        ) {
            $statusSlug = $options['status'] ?? 'completed';

            $original = StoreWalletTransaction::query()
                ->with('storeWallet')
                ->lockForUpdate()
                ->findOrFail($original->id);

            if ($original->isReversal()) {
                throw new RuntimeException(
                    'Cannot reverse a transaction that is itself a reversal.'
                );
            }

            if (! $original->isCompleted()) {
                throw new RuntimeException(
                    'Only completed transactions can be reversed.'
                );
            }

            if ($original->childTransactions()->exists()) {
                if ($reference !== null) {
                    $existing = StoreWalletTransaction::query()
                        ->where('related_transaction_id', $original->id)
                        ->where('external_provider', $reference->provider)
                        ->where('external_reference', $reference->reference)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        $existing->loadMissing('status', 'category');

                        if ($statusSlug === 'completed' && $existing->isPending()) {
                            return $this->promotePendingTransactionToCompleted($existing);
                        }

                        return $existing;
                    }
                }

                throw new RuntimeException(
                    'This transaction has already been reversed.'
                );
            }

            return $this->record(
                wallet: $original->storeWallet,
                categorySlug: $reversalCategorySlug,
                amount: $original->amount,
                reference: $reference,
                options: array_merge($options, [
                    'description' => $description
                        ?? "Reversal of transaction #{$original->id}",
                    'related_transaction_id' => $original->id,
                ])
            );
        });
    }

    /**
     * Mark a pending transaction as failed, without affecting the wallet balance.
     * Use this when a transaction was recorded as pending but never completed.
     */
    public function markFailed(
        StoreWalletTransaction $transaction,
        ?string $reason = null
    ): StoreWalletTransaction {

        $failedStatus = TransactionStatus::bySlugOrFail('failed');

        return DB::transaction(function () use (
            $transaction,
            $reason,
            $failedStatus
        ) {

            $transaction = StoreWalletTransaction::query()
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            if (! $transaction->isPending()) {
                throw new RuntimeException(
                    'Only pending transactions can be marked as failed.'
                );
            }

            $transaction->update([
                'transaction_status_id' => $failedStatus->id,
                'description' => $reason
                    ? trim(($transaction->description ?? '') . ' | ' . $reason)
                    : $transaction->description,
            ]);

            return $transaction->fresh();
        });
    }

    /**
     * Confirm a pending transaction, applying its effect to the wallet balance.
     * Use this when a transaction was recorded as pending and is now confirmed
     * (e.g. a payment gateway webhook confirms the charge succeeded).
     */
    public function confirm(StoreWalletTransaction $transaction): StoreWalletTransaction
    {
        return DB::transaction(function () use ($transaction) {
            $transaction = StoreWalletTransaction::query()
                ->with([
                    'category',
                    'storeWallet',
                ])
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            if (!$transaction->isPending()) {
                throw new RuntimeException('Only pending transactions can be confirmed.');
            }

            $lockedWallet = StoreWallet::query()
                ->lockForUpdate()
                ->findOrFail($transaction->store_wallet_id);

            $newBalance = $this->calculateNewBalance(
                currentBalance: $lockedWallet->balance,
                amount: $transaction->amount,
                isCredit: $transaction->category->isCredit(),
                insufficientFundsMessage: 'Insufficient wallet balance to confirm this transaction.'
            );

            return $this->markTransactionCompleted($transaction, $lockedWallet, $newBalance);
        });
    }

    private function promotePendingTransactionToCompleted(
        StoreWalletTransaction $transaction
    ): StoreWalletTransaction {
        $lockedWallet = StoreWallet::query()
            ->lockForUpdate()
            ->findOrFail($transaction->store_wallet_id);

        $newBalance = $this->calculateNewBalance(
            currentBalance: $lockedWallet->balance,
            amount: $transaction->amount,
            isCredit: $transaction->category->isCredit(),
            insufficientFundsMessage: 'Insufficient wallet balance for this transaction.'
        );

        return $this->markTransactionCompleted($transaction, $lockedWallet, $newBalance);
    }

    private function calculateNewBalance(
        string $currentBalance,
        string $amount,
        bool $isCredit,
        string $insufficientFundsMessage
    ): string {
        $newBalance = $isCredit
            ? bcadd($currentBalance, $amount, 2)
            : bcsub($currentBalance, $amount, 2);

        if (bccomp($newBalance, '0', 2) < 0) {
            throw new RuntimeException($insufficientFundsMessage);
        }

        return $newBalance;
    }

    private function markTransactionCompleted(
        StoreWalletTransaction $transaction,
        StoreWallet $lockedWallet,
        string $newBalance
    ): StoreWalletTransaction {
        $completedStatus = TransactionStatus::bySlugOrFail('completed');

        $transaction->update([
            'transaction_status_id' => $completedStatus->id,
            'balance_after' => $newBalance,
        ]);

        $lockedWallet->update([
            'balance' => $newBalance,
            'last_transaction_at' => now(),
        ]);

        return $transaction->fresh();
    }
}