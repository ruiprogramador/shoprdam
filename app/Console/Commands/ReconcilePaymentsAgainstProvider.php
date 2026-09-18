<?php

namespace App\Console\Commands;

use App\Domain\Payments\ConfigInteger;
use App\Domain\Payments\Enums\ReconciliationOutcomeType;
use App\Domain\Payments\Enums\ReconciliationSeverity;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\ReconciliationFinding;
use App\Domain\Payments\Services\ProviderReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1 of feat/financial-reconciliation
 * (docs/financial/RECONCILIATION.md) — detection + persistence +
 * observability ONLY. Never mutates a Wallet, a Payment, a PaymentAttempt,
 * an Order, or a PaymentProviderEvent; never calls
 * PaymentAttemptRecoveryService::recover(), PaymentService::finalizeAttempt(),
 * or PaymentEventProcessor::apply() — see
 * App\Domain\Payments\Services\ProviderReconciler's own docblock and
 * tests/Architecture/ReconciliationNoFinancialMutationTest for the
 * mechanical proof.
 *
 * Candidates: every PaymentAttempt with a `provider_reference` (i.e.
 * `Claimed`, `Succeeded`, or `Failed` — deliberately not narrowed to
 * `Claimed`, so RemotePendingLocalTerminal can be detected too) at least
 * `--min-age` minutes old — giving the normal settlement path (a live
 * webhook, or the existing scheduled event replay — see
 * docs/financial/RECONCILIATION.md §3.D) a fair chance to resolve it before
 * reconciliation ever looks at it, exactly the same reasoning
 * `ReconcileOrphanedPaymentAttempts --stale-after` already applies to its
 * own, differently-shaped candidates.
 *
 * The exit code mirrors `wallet:audit`/`payments:health`: FAILURE whenever
 * any High-severity finding is currently open, across the whole table, not
 * just this run — a category like RemoteSucceededNoSettlementPath can stay
 * open indefinitely by design (docs/financial/RECONCILIATION.md §8/§14), so
 * this is an honest, not a bug-shaped, way for this command to keep paging
 * until a human resolves it.
 */
class ReconcilePaymentsAgainstProvider extends Command
{
    protected $signature = 'app:reconcile-payments-against-provider
        {--min-age=10 : Minutes a PaymentAttempt with a provider_reference must exist before being a reconciliation candidate}
        {--json : Emit the run summary as JSON instead of formatted output}';

    protected $description = 'Detect disagreement between local Payments-domain state and each provider\'s own canonical record (never mutates financial state)';

    public function handle(ProviderReconciler $reconciler): int
    {
        $minAge = ConfigInteger::parse($this->option('min-age'), min: 0);

        if ($minAge === null) {
            $this->error("--min-age must be an integer. Got: '".ConfigInteger::printable($this->option('min-age'))."'.");

            return self::INVALID;
        }

        $chunkSize = max(1, (int) config('payments.reconciliation_finding_chunk_size', 200));

        $summary = [
            'candidates' => 0,
            'observed' => 0,
            'skipped' => 0,
            'retrieval_failed' => 0,
            'by_category' => [],
            'retrieval_failed_by_provider' => [],
        ];

        PaymentAttempt::query()
            ->whereNotNull('provider_reference')
            ->where('created_at', '<=', now()->subMinutes($minAge))
            ->with('payment.order.currency')
            ->chunkById($chunkSize, function ($candidates) use (&$summary, $reconciler) {
                foreach ($candidates as $attempt) {
                    $summary['candidates']++;
                    $this->processAttempt($attempt, $reconciler, $summary);
                }
            });

        $openHighSeverityCount = ReconciliationFinding::query()
            ->where('status', ReconciliationStatus::Open)
            ->where('severity', ReconciliationSeverity::High)
            ->count();

        $summary['open_high_severity_findings'] = $openHighSeverityCount;

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderHuman($summary);
        }

        $this->logSummary($summary);

        return $openHighSeverityCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<string, mixed>  $summary */
    private function processAttempt(PaymentAttempt $attempt, ProviderReconciler $reconciler, array &$summary): void
    {
        $outcome = $reconciler->reconcile($attempt);

        switch ($outcome->type) {
            case ReconciliationOutcomeType::Observed:
                $summary['observed']++;

                if ($outcome->finding !== null) {
                    $category = $outcome->finding->category->value;
                    $summary['by_category'][$category] = ($summary['by_category'][$category] ?? 0) + 1;

                    Log::info('Reconciliation observation recorded.', [
                        'payment_attempt_id' => $attempt->id,
                        'provider' => $attempt->provider,
                        'provider_reference' => $attempt->provider_reference,
                        'category' => $category,
                        'severity' => $outcome->finding->severity->value,
                        'status' => $outcome->finding->status->value,
                        'observation_count' => $outcome->finding->observation_count,
                    ]);
                }

                return;

            case ReconciliationOutcomeType::RetrievalFailed:
                $summary['retrieval_failed']++;
                $summary['retrieval_failed_by_provider'][$attempt->provider] =
                    ($summary['retrieval_failed_by_provider'][$attempt->provider] ?? 0) + 1;

                // Sanitized: exception class + HTTP status/retryability only,
                // never a raw provider payload/exception message — same
                // allowlist rule as RecoveryErrorFormatter. Never persisted
                // as a finding, and never touches an already-open one for
                // this identity — no authoritative evidence was obtained
                // (docs/financial/RECONCILIATION.md §8/§13).
                Log::warning('Reconciliation retrieval failed — no authoritative evidence obtained (operational, not a financial finding).', [
                    'payment_attempt_id' => $attempt->id,
                    'provider' => $attempt->provider,
                    'provider_reference' => $attempt->provider_reference,
                    'exception' => $outcome->exception::class,
                    'retryable' => $outcome->retryable,
                ]);

                return;

            case ReconciliationOutcomeType::Skipped:
                $summary['skipped']++;

                return;
        }
    }

    /** @param  array<string, mixed>  $summary */
    private function renderHuman(array $summary): void
    {
        $this->components->twoColumnDetail(
            'Overall',
            $summary['open_high_severity_findings'] === 0 ? '<fg=green>NO OPEN HIGH-SEVERITY FINDINGS</>' : '<fg=red>NEEDS ATTENTION</>',
        );
        $this->newLine();

        $this->table(['Signal', 'Count'], [
            ['Candidates checked', $summary['candidates']],
            ['Observations recorded', $summary['observed']],
            ['Skipped (provider has no canonical retrieval)', $summary['skipped']],
            ['Retrieval failed this run (no authoritative evidence)', $summary['retrieval_failed']],
            ['Open High-severity findings (all time)', $summary['open_high_severity_findings']],
        ]);

        if ($summary['by_category'] !== []) {
            $this->line('<comment>Observations by category (this run):</comment>');
            $this->table(['Category', 'Count'], collect($summary['by_category'])->map(fn ($count, $category) => [$category, $count])->values()->all());
        }

        if ($summary['retrieval_failed_by_provider'] !== []) {
            $this->line('<comment>Retrieval failed, by provider (this run):</comment>');
            $this->table(['Provider', 'Count'], collect($summary['retrieval_failed_by_provider'])->map(fn ($count, $provider) => [$provider, $count])->values()->all());
        }
    }

    /** @param  array<string, mixed>  $summary */
    private function logSummary(array $summary): void
    {
        $context = [
            'candidates' => $summary['candidates'],
            'observed' => $summary['observed'],
            'skipped' => $summary['skipped'],
            'retrieval_failed' => $summary['retrieval_failed'],
            'open_high_severity_findings' => $summary['open_high_severity_findings'],
        ];

        if ($summary['open_high_severity_findings'] === 0) {
            Log::info('Payment reconciliation run complete: no open high-severity findings.', $context);
        } else {
            Log::warning('Payment reconciliation run complete: open high-severity findings exist.', $context);
        }
    }
}
