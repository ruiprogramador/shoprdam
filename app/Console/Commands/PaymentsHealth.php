<?php

namespace App\Console\Commands;

use App\Domain\Payments\DTOs\PaymentsHealthReport;
use App\Domain\Payments\Services\PaymentsHealthCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Read-only operational snapshot of the payments domain — the only thing it
 * calls is App\Domain\Payments\Services\PaymentsHealthCheck::report(),
 * itself nothing but SELECT queries (see that class's own docblock for the
 * guarantee this command relies on). Never mutates a Payment, PaymentAttempt,
 * or PaymentProviderEvent, and introduces no manual recovery/settlement
 * action — that's explicitly out of scope here; see
 * App\Console\Commands\ReconcileOrphanedPaymentAttempts for the (separate,
 * mutating) recovery path this command only ever *reports on*, never
 * triggers.
 *
 * Exists to answer, without an operator querying tables by hand: are
 * payments stuck, which provider, which attempts, are unmatched provider
 * events accumulating, is reconciliation failing, does this need a human —
 * see PaymentsHealthReport's own fields for exactly what each answers.
 *
 * The exit code doubles as the actionable signal an external
 * scheduler/monitor can page on — SUCCESS when
 * PaymentsHealthReport::isHealthy(), FAILURE otherwise — deliberately
 * instead of standing up a separate metrics/alerting platform this project
 * has no other use for; "this command's last exit code was non-zero" is
 * already a condition any conventional cron/monitoring wrapper can act on.
 */
class PaymentsHealth extends Command
{
    protected $signature = 'payments:health {--json : Emit the full report as JSON instead of formatted tables}';

    protected $description = 'Read-only operational snapshot of stuck payment attempts and unmatched provider events (never mutates payment state)';

    public function handle(PaymentsHealthCheck $healthCheck): int
    {
        $report = $healthCheck->report();

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderHuman($report);
        }

        $this->logSummary($report);

        return $report->isHealthy() ? self::SUCCESS : self::FAILURE;
    }

    private function renderHuman(PaymentsHealthReport $report): void
    {
        $this->components->twoColumnDetail(
            'Overall',
            $report->isHealthy() ? '<fg=green>HEALTHY</>' : '<fg=red>NEEDS ATTENTION</>',
        );
        $this->newLine();

        $this->line('<comment>Actionable:</comment>');
        $this->table(['Signal', 'Count', 'By provider'], [
            ['NeedsAttention attempts', $report->needsAttentionCount, $this->formatByProvider($report->needsAttentionByProvider)],
            ["Stale pending attempts (> {$report->thresholds['stale_pending_minutes']}m)", $report->stalePendingCount, $this->formatByProvider($report->stalePendingByProvider)],
            ["Stale reconciliation leases (> {$report->thresholds['stale_lease_minutes']}m)", $report->staleLeaseCount, $this->formatByProvider($report->staleLeaseByProvider)],
            ["Stale pending events (> {$report->thresholds['stale_event_minutes']}m)", $report->staleEventCount, $this->formatByProvider($report->staleEventByProvider)],
            ["Repeated replay failures (>= {$report->thresholds['replay_attempts_warning']})", $report->repeatedReplayFailureCount, $this->formatByProvider($report->repeatedReplayFailureByProvider)],
        ]);

        $this->line('<comment>Informational (not, by themselves, unhealthy):</comment>');
        $this->table(['Signal', 'Value'], [
            ['Pending provider events', $report->pendingEventCount.($report->pendingEventByProvider === [] ? '' : ' ('.$this->formatByProvider($report->pendingEventByProvider).')')],
            ['Oldest pending event age', $report->oldestPendingEventAgeMinutes === null ? 'n/a' : "{$report->oldestPendingEventAgeMinutes}m"],
            ['Attempts still retrying after a recorded recovery failure', $report->recoveryFailureCount],
            ['Events eligible for pruning', $report->eventsEligibleForPruning],
        ]);

        if ($report->providerDistribution !== []) {
            $this->line('<comment>Provider distribution (all attempts, by status):</comment>');

            $rows = [];

            foreach ($report->providerDistribution as $provider => $byStatus) {
                foreach ($byStatus as $status => $count) {
                    $rows[] = [$provider, $status, $count];
                }
            }

            $this->table(['Provider', 'Status', 'Count'], $rows);
        }
    }

    /** @param  array<string, int>  $byProvider */
    private function formatByProvider(array $byProvider): string
    {
        if ($byProvider === []) {
            return '';
        }

        return collect($byProvider)->map(fn ($count, $provider) => "{$provider}={$count}")->implode(', ');
    }

    /** One compact summary line — the per-attempt/per-event detail already went to stdout above (or --json). */
    private function logSummary(PaymentsHealthReport $report): void
    {
        $context = [
            'healthy' => $report->isHealthy(),
            'needs_attention_count' => $report->needsAttentionCount,
            'stale_pending_count' => $report->stalePendingCount,
            'stale_lease_count' => $report->staleLeaseCount,
            'stale_event_count' => $report->staleEventCount,
            'repeated_replay_failure_count' => $report->repeatedReplayFailureCount,
        ];

        if ($report->isHealthy()) {
            Log::info('Payments health check: healthy.', $context);
        } else {
            Log::warning('Payments health check: needs attention.', $context);
        }
    }
}
