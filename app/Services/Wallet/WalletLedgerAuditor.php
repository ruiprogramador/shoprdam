<?php

namespace App\Services\Wallet;

use App\Domain\Wallet\DTOs\WalletAuditReport;
use App\Domain\Wallet\DTOs\WalletAuditResult;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\TransactionStatus;

/**
 * Reconciles a StoreWallet's stored `balance` against its own ledger —
 * `expected balance = Σ completed credits − Σ completed debits`, considering
 * the wallet's ENTIRE transaction history since creation, never a time
 * window. This is the formal statement of LEDGER-03; see this branch's own
 * design record for why it's safe to state this way: every production
 * StoreWallet is created with `balance = '0.00'` (the only writer is
 * WalletService::createWallet(), which hardcodes it — see
 * tests/Feature/Store/WalletServiceOpeningBalanceTest), so there is no
 * unaccounted-for opening balance to fold into the formula.
 *
 * Strictly read-only: every method here is nothing but SELECT queries (see
 * tests/Architecture/WalletLedgerReadOnlyAuditTest, which enforces this by
 * scanning this class and the console command for any write-shaped call).
 * A wallet whose stored balance disagrees with its ledger is *reported*,
 * never corrected — there is no `--fix`, and there will not be one; see
 * App\Console\Commands\AuditWalletLedger's own docblock for why silently
 * "fixing" a financial mismatch is exactly the wrong instinct: a drift is
 * either a bug that needs a human to find its root cause, or evidence this
 * auditor itself has a bug — either way, the last thing to do with it is
 * make it disappear.
 *
 * Every amount is a decimal(2) string handled with bcmath end to end —
 * never a float, and never SQL `SUM()` either: Eloquent's `decimal:2` cast
 * already guarantees a string (`BigDecimal::of((string) $value)` under the
 * hood, verified against the framework's own source — never a float
 * round-trip), but summing in SQL would still mean trusting the database
 * driver's own arithmetic instead of this codebase's one, single
 * money-math primitive. Chunks by id so a wallet with a very large ledger
 * never has its full history loaded into memory at once, and so every
 * transaction is visited exactly once regardless of chunk boundaries.
 */
class WalletLedgerAuditor
{
    private const DEFAULT_CHUNK_SIZE = 500;

    /**
     * Σ completed credits − Σ completed debits for this wallet's entire
     * history. Direction comes from the transaction's own category
     * (`TransactionCategory::isCredit()`/`isDebit()`) — the canonical
     * semantic already fixed at the database level (see
     * transaction_categories.direction) — never inferred from the sign of
     * `amount`, which is always stored positive regardless of direction.
     */
    public function expectedBalance(StoreWallet $wallet, int $chunkSize = self::DEFAULT_CHUNK_SIZE): string
    {
        $completedStatusId = TransactionStatus::bySlugOrFail('completed')->id;

        $balance = '0.00';

        StoreWalletTransaction::query()
            ->where('store_wallet_id', $wallet->id)
            ->where('transaction_status_id', $completedStatusId)
            ->with('category')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($transactions) use (&$balance) {
                foreach ($transactions as $transaction) {
                    $balance = $transaction->category->isCredit()
                        ? bcadd($balance, $transaction->amount, 2)
                        : bcsub($balance, $transaction->amount, 2);
                }
            });

        return $balance;
    }

    /** Reconciles one wallet — never writes anything. */
    public function auditWallet(StoreWallet $wallet, int $chunkSize = self::DEFAULT_CHUNK_SIZE): WalletAuditResult
    {
        $wallet->loadMissing('currency');

        $expected = $this->expectedBalance($wallet, $chunkSize);
        $actual = $wallet->balance;

        return new WalletAuditResult(
            walletId: $wallet->id,
            storeId: $wallet->store_id,
            currencyCode: $wallet->currency->code,
            expectedBalance: $expected,
            actualBalance: $actual,
            difference: bcsub($expected, $actual, 2),
            completedTransactionCount: $this->completedTransactionCount($wallet),
        );
    }

    /**
     * Reconciles every wallet in the system (optionally scoped to one
     * store), chunked so the audit itself never loads every wallet — or
     * every wallet's ledger — into memory at once.
     */
    public function auditAll(?int $storeId = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): WalletAuditReport
    {
        $totalAudited = 0;
        $mismatches = [];

        StoreWallet::query()
            ->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId))
            ->with('currency')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($wallets) use (&$totalAudited, &$mismatches, $chunkSize) {
                foreach ($wallets as $wallet) {
                    $totalAudited++;

                    $result = $this->auditWallet($wallet, $chunkSize);

                    if (! $result->isConsistent()) {
                        $mismatches[] = $result;
                    }
                }
            });

        return new WalletAuditReport($totalAudited, $mismatches);
    }

    private function completedTransactionCount(StoreWallet $wallet): int
    {
        return StoreWalletTransaction::query()
            ->where('store_wallet_id', $wallet->id)
            ->where('transaction_status_id', TransactionStatus::bySlugOrFail('completed')->id)
            ->count();
    }
}
