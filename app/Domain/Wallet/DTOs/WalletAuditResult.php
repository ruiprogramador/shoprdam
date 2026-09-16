<?php

namespace App\Domain\Wallet\DTOs;

/**
 * The result of reconciling one StoreWallet's stored `balance` against its
 * own ledger — see App\Services\Wallet\WalletLedgerAuditor::auditWallet().
 * Every amount here is a decimal(2) string (bcmath), never a float — see
 * that class's own docblock for why.
 *
 * Read-only by construction: nothing that produces this ever writes to the
 * wallet or its transactions. A non-empty `$difference` (i.e. `expected !==
 * actual`) is reported, never corrected — see WalletLedgerAuditor's docblock.
 */
final readonly class WalletAuditResult
{
    public function __construct(
        public int $walletId,
        public int $storeId,
        public string $currencyCode,
        public string $expectedBalance,
        public string $actualBalance,
        public string $difference,
        public int $completedTransactionCount,
    ) {}

    /** True when `expectedBalance` and `actualBalance` agree to the cent — bccomp, never `==`/`===` on the strings or a float cast. */
    public function isConsistent(): bool
    {
        return bccomp($this->expectedBalance, $this->actualBalance, 2) === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'wallet_id' => $this->walletId,
            'store_id' => $this->storeId,
            'currency' => $this->currencyCode,
            'expected_balance' => $this->expectedBalance,
            'actual_balance' => $this->actualBalance,
            'difference' => $this->difference,
            'consistent' => $this->isConsistent(),
            'completed_transaction_count' => $this->completedTransactionCount,
        ];
    }
}
