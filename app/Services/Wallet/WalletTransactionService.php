<?php

namespace App\Services\Wallet;

use App\Domain\Wallet\Exceptions\CannotReverseReversalException;
use App\Domain\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Domain\Wallet\Exceptions\InvalidTransactionAmountException;
use App\Domain\Wallet\Exceptions\TransactionAlreadyReversedException;
use App\Domain\Wallet\Exceptions\TransactionNotCompletedException;
use App\Domain\Wallet\Exceptions\TransactionNotPendingException;
use App\Domain\Wallet\Exceptions\WalletMismatchException;
use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\TransactionCategory;
use App\Models\TransactionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class WalletTransactionService
{
    /**
     * Record a new transaction against a wallet, adjusting its balance.
     *
     * Idempotent transactions are immutable.
     * Later duplicate events must not overwrite the original payload.
     * The first persisted event is the source of truth.
     *
     * @param  string  $categorySlug  e.g. 'sale', 'withdrawal', 'commission'
     * @param  string  $amount  always positive; direction comes from the category. Positive decimal amount (e.g. "100.00")
     * @param  WalletTransactionReference|null  $reference  optional external reference for idempotency
     * @param  array  $options  optional keys: description,referenceable, related_transaction_id
     *                          , metadata, source, created_by, status
     */
    public function record(
        StoreWallet $wallet,
        string $categorySlug,
        string $amount,
        ?WalletTransactionReference $reference = null,
        array $options = []
    ): StoreWalletTransaction {
        if (bccomp($amount, '0', 2) <= 0) {
            throw InvalidTransactionAmountException::nonPositive();
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
                $existing = $this->findByReference($reference);

                if ($existing) {
                    return $this->resolveExisting($existing, $status);
                }
            }

            $lockedWallet = $this->lockWallet($wallet->id);

            $isCompleted = $status->isCompleted();

            $newBalance = $isCompleted
                ? $this->calculateNewBalance(
                    currentBalance: $lockedWallet->balance,
                    amount: $amount,
                    isCredit: $category->isCredit(),
                    insufficientFundsException: InsufficientWalletBalanceException::forNewTransaction()
                )
                : $lockedWallet->balance;

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
                    'related_transaction_id' => $options['related_transaction_id'] ?? null,

                    'metadata' => $options['metadata'] ?? null,
                    'source' => $options['source'] ?? TransactionSource::System,
                    'created_by' => $options['created_by'] ?? null,
                ]);
            } catch (QueryException $e) {
                if ($reference && $e->getCode() === '23000') {
                    $existing = $this->findByReference($reference) ?? throw $e;

                    return $this->resolveExisting($existing, $status);
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
     * @param  StoreWalletTransaction  $original  the transaction to reverse
     * @param  string  $reversalCategorySlug  e.g. 'customer_refund', 'commission_refund'
     * @param  string|null  $description  optional description for the reversal transaction
     * @param  WalletTransactionReference|null  $reference  optional external reference for idempotency
     * @param  array  $options  optional keys: metadata, source, created_by, status
     * @param  StoreWallet|null  $wallet  if provided, the original transaction must belong to this wallet
     * @return StoreWalletTransaction the reversal transaction
     *
     * @throws TransactionNotCompletedException if the original transaction is not completed
     * @throws CannotReverseReversalException if the original transaction is itself a reversal
     * @throws TransactionAlreadyReversedException if the original transaction was already reversed
     * @throws WalletMismatchException if the original transaction does not belong to the given wallet
     */
    public function reverse(
        StoreWalletTransaction $original,
        string $reversalCategorySlug,
        ?string $description = null,
        ?WalletTransactionReference $reference = null,
        array $options = [],
        ?StoreWallet $wallet = null
    ): StoreWalletTransaction {
        return DB::transaction(function () use (
            $original,
            $reversalCategorySlug,
            $description,
            $reference,
            $options,
            $wallet
        ) {
            $status = TransactionStatus::bySlugOrFail($options['status'] ?? 'completed');

            $original = $this->lockTransaction($original->id, ['storeWallet']);

            $this->ensureCanBeReversed($original, $wallet);

            if ($original->childTransactions()->exists()) {
                if ($reference !== null) {
                    $existing = $this->findByReference($reference, $original->id);

                    if ($existing) {
                        return $this->resolveExisting($existing, $status);
                    }
                }

                throw TransactionAlreadyReversedException::create();
            }

            return $this->record(
                wallet: $original->storeWallet,
                categorySlug: $reversalCategorySlug,
                amount: $original->amount,
                reference: $reference,
                options: array_merge($options, [
                    'description' => $description
                        ?? $options['description']
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

            $transaction = $this->lockTransaction($transaction->id);

            if (! $transaction->isPending()) {
                throw TransactionNotPendingException::cannotMarkFailed();
            }

            $transaction->update([
                'transaction_status_id' => $failedStatus->id,
                'description' => $reason
                    ? trim(($transaction->description ?? '').' | '.$reason)
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
            $transaction = $this->lockTransaction($transaction->id, ['category', 'storeWallet']);

            if (! $transaction->isPending()) {
                throw TransactionNotPendingException::cannotConfirm();
            }

            return $this->completePendingTransaction(
                $transaction,
                InsufficientWalletBalanceException::forConfirmation()
            );
        });
    }

    /**
     * Lock a wallet row for update within the current transaction.
     */
    private function lockWallet(int $id): StoreWallet
    {
        return StoreWallet::query()
            ->lockForUpdate()
            ->findOrFail($id);
    }

    /**
     * Lock a transaction row for update within the current transaction,
     * ignoring any state already loaded on the caller's model instance.
     */
    private function lockTransaction(int $id, array $with = []): StoreWalletTransaction
    {
        return StoreWalletTransaction::query()
            ->with($with)
            ->lockForUpdate()
            ->findOrFail($id);
    }

    /**
     * @throws WalletMismatchException if the transaction does not belong to the given wallet
     * @throws CannotReverseReversalException if the transaction is itself a reversal
     * @throws TransactionNotCompletedException if the transaction is not completed
     */
    private function ensureCanBeReversed(StoreWalletTransaction $original, ?StoreWallet $wallet): void
    {
        if ($wallet !== null && $wallet->id !== $original->store_wallet_id) {
            throw WalletMismatchException::forReversal();
        }

        if ($original->isReversal()) {
            throw CannotReverseReversalException::create();
        }

        if (! $original->isCompleted()) {
            throw TransactionNotCompletedException::cannotReverse();
        }
    }

    /**
     * Find an existing transaction by its external reference, locked for update.
     */
    private function findByReference(
        WalletTransactionReference $reference,
        ?string $relatedTransactionId = null
    ): ?StoreWalletTransaction {
        return StoreWalletTransaction::query()
            ->where('external_provider', $reference->provider)
            ->where('external_reference', $reference->reference)
            ->when(
                $relatedTransactionId,
                fn ($query) => $query->where('related_transaction_id', $relatedTransactionId)
            )
            ->lockForUpdate()
            ->first();
    }

    /**
     * Resolve a transaction found via idempotency lookup: promote it to
     * completed if it is pending and the incoming status demands it,
     * otherwise return it untouched (its original payload is the source of truth).
     */
    private function resolveExisting(
        StoreWalletTransaction $existing,
        TransactionStatus $status
    ): StoreWalletTransaction {
        $existing->loadMissing('status', 'category');

        if ($status->isCompleted() && $existing->isPending()) {
            return $this->completePendingTransaction(
                $existing,
                InsufficientWalletBalanceException::forNewTransaction()
            );
        }

        return $existing;
    }

    /**
     * Apply a pending transaction's effect to its wallet balance and mark it completed.
     */
    private function completePendingTransaction(
        StoreWalletTransaction $transaction,
        InsufficientWalletBalanceException $insufficientFundsException
    ): StoreWalletTransaction {
        $lockedWallet = $this->lockWallet($transaction->store_wallet_id);

        $newBalance = $this->calculateNewBalance(
            currentBalance: $lockedWallet->balance,
            amount: $transaction->amount,
            isCredit: $transaction->category->isCredit(),
            insufficientFundsException: $insufficientFundsException
        );

        return $this->markTransactionCompleted($transaction, $lockedWallet, $newBalance);
    }

    private function calculateNewBalance(
        string $currentBalance,
        string $amount,
        bool $isCredit,
        InsufficientWalletBalanceException $insufficientFundsException
    ): string {
        $newBalance = $isCredit
            ? bcadd($currentBalance, $amount, 2)
            : bcsub($currentBalance, $amount, 2);

        if (bccomp($newBalance, '0', 2) < 0) {
            throw $insufficientFundsException;
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
