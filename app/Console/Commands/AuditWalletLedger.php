<?php

namespace App\Console\Commands;

use App\Domain\Wallet\DTOs\WalletAuditReport;
use App\Services\Wallet\WalletLedgerAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Read-only reconciliation of every StoreWallet's stored `balance` against
 * its own ledger (see App\Services\Wallet\WalletLedgerAuditor for the exact
 * formula and why it's safe to compute this way).
 *
 * Deliberately has no `--fix`, and never will: a wallet's stored balance
 * disagreeing with its own ledger means either a bug wrote a value that
 * didn't go through App\Services\Wallet\WalletTransactionService, or a bug
 * in the auditor itself — in both cases, silently overwriting `balance` to
 * make the report go green would destroy the only evidence available to
 * find out which, and risks moving a real number away from what it should
 * be based on nothing but this command's own (possibly buggy) opinion. A
 * mismatch is a page for a human, never a click for a script.
 *
 * The exit code doubles as the actionable signal a scheduler/monitor can
 * page on — SUCCESS when WalletAuditReport::isHealthy(), FAILURE otherwise —
 * mirrors `php artisan payments:health` exactly.
 */
class AuditWalletLedger extends Command
{
    protected $signature = 'wallet:audit
        {--store= : Only audit wallets belonging to this store id}
        {--json : Emit the full report as JSON instead of a formatted table}';

    protected $description = 'Read-only reconciliation of every wallet balance against its own ledger (never writes, never fixes drift)';

    public function handle(WalletLedgerAuditor $auditor): int
    {
        $storeId = $this->option('store');

        if ($storeId !== null && ! ctype_digit((string) $storeId)) {
            $this->error("--store must be a positive integer. Got: '{$storeId}'.");

            return self::INVALID;
        }

        $report = $auditor->auditAll($storeId !== null ? (int) $storeId : null);

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderHuman($report);
        }

        $this->logSummary($report);

        return $report->isHealthy() ? self::SUCCESS : self::FAILURE;
    }

    private function renderHuman(WalletAuditReport $report): void
    {
        $this->components->twoColumnDetail(
            'Overall',
            $report->isHealthy() ? '<fg=green>CONSISTENT</>' : '<fg=red>DRIFT DETECTED</>',
        );
        $this->components->twoColumnDetail('Wallets audited', (string) $report->totalAudited);
        $this->newLine();

        if ($report->mismatches === []) {
            $this->info('No drift found — every wallet balance matches its own ledger.');

            return;
        }

        $this->line('<comment>Mismatches (reported only — nothing was written):</comment>');
        $this->table(
            ['Wallet', 'Store', 'Currency', 'Expected', 'Actual', 'Difference', 'Completed txns'],
            array_map(fn ($result) => [
                $result->walletId,
                $result->storeId,
                $result->currencyCode,
                $result->expectedBalance,
                $result->actualBalance,
                $result->difference,
                $result->completedTransactionCount,
            ], $report->mismatches),
        );
    }

    private function logSummary(WalletAuditReport $report): void
    {
        $context = [
            'healthy' => $report->isHealthy(),
            'total_audited' => $report->totalAudited,
            'mismatch_count' => count($report->mismatches),
        ];

        if ($report->isHealthy()) {
            Log::info('Wallet ledger audit: consistent.', $context);
        } else {
            Log::error('Wallet ledger audit: drift detected.', [
                ...$context,
                'mismatches' => array_map(fn ($result) => $result->toArray(), $report->mismatches),
            ]);
        }
    }
}
