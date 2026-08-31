<?php

namespace App\Console\Commands;

use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Models\PaymentProviderEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Prunes payment_provider_events rows that will never be looked at again —
 * see docs/wallet/integrations.md ("Terminal webhooks that arrive before a
 * local claim exists"). This table is a durable replay inbox, not a general
 * audit log: a `pending` row still has real recovery work attached to it
 * (App\Domain\Payments\Services\PaymentEventProcessor::replayUnmatchedEvents()
 * may still need it, possibly indefinitely if its owning attempt is stuck
 * `needs_attention`) and must never be pruned on age alone — only a row
 * whose handler already reported `EventApplicationOutcome::Applied`
 * (settled, or permanently a no-op) has no further operational purpose once
 * it's old enough that nobody is going to come looking for it during an
 * incident.
 *
 * The retention anchor is `processed_at`, not `created_at`: how long ago a
 * row was *resolved* is what determines how long it's still useful for
 * debugging a recent incident — not how long ago it first arrived, which
 * for an event that sat `pending` for weeks against a stuck attempt before
 * finally being replayed could understate how recently it actually became
 * relevant. An `applied` row somehow missing `processed_at` is never
 * eligible (see the predicate below) rather than guessed at — failing safe
 * (never pruning) beats failing open here.
 *
 * Deletion is a chunked, conditional bulk `DELETE ... LIMIT` loop, not a
 * loaded-then-deleted collection: this command's memory footprint doesn't
 * grow with the number of eligible rows (config('payments.provider_event_prune_chunk_size')),
 * mirroring ReconcileOrphanedPaymentAttempts's own chunking rationale. Safe
 * to run repeatedly, and safe if two instances overlap: each batch only
 * ever matches rows that still satisfy the predicate at the moment it runs,
 * and a row another instance already deleted just doesn't match again.
 */
class PrunePaymentProviderEvents extends Command
{
    protected $signature = 'app:prune-payment-provider-events
        {--days= : Override payments.provider_event_retention_days for this run}';

    protected $description = 'Delete terminally applied payment_provider_events rows older than the configured retention period';

    public function handle(): int
    {
        $days = $this->resolveRetentionDays();

        if ($days === null) {
            return self::INVALID;
        }

        $chunkSize = max(1, (int) config('payments.provider_event_prune_chunk_size', 500));
        $cutoff = now()->subDays($days);
        $totalDeleted = 0;

        do {
            $deleted = PaymentProviderEvent::query()
                ->where('status', ProviderEventStatus::Applied)
                ->whereNotNull('processed_at')
                ->where('processed_at', '<', $cutoff)
                ->limit($chunkSize)
                ->delete();

            $totalDeleted += $deleted;
        } while ($deleted > 0);

        Log::info('Pruned payment_provider_events.', [
            'deleted' => $totalDeleted,
            'retention_days' => $days,
            'cutoff' => $cutoff->toIso8601String(),
        ]);
        $this->info("Pruned {$totalDeleted} payment_provider_events row(s) applied before {$cutoff->toIso8601String()}.");

        return self::SUCCESS;
    }

    /**
     * Resolves the retention period from `--days` if given, otherwise from
     * `payments.provider_event_retention_days` — validated with strict
     * integer parsing rather than an early `(int)` cast. `(int) 'abc'` is
     * silently `0`, which would make this command aggressively prune every
     * currently-eligible Applied row on a typo'd/malformed value instead of
     * refusing to run — the opposite of the fail-safe behavior a pruning
     * command needs. An explicitly invalid `--days` is never masked by
     * falling back to the configured value: `handle()` returns
     * `self::INVALID` (deleting nothing) the moment either input fails to
     * parse.
     *
     * Zero retention (`--days=0`, or the config set to `0`) is intentionally
     * still valid — it means "prune anything currently eligible," not "skip
     * pruning" — consistent with this command's pre-existing behavior; only
     * the *parsing* is being hardened here, not what a valid value means.
     */
    private function resolveRetentionDays(): ?int
    {
        $option = $this->option('days');

        if ($option !== null) {
            return $this->parseNonNegativeInteger($option, '--days');
        }

        return $this->parseNonNegativeInteger(
            config('payments.provider_event_retention_days', 90),
            'payments.provider_event_retention_days',
        );
    }

    /**
     * Accepts only a value that is *exactly* a non-negative integer —
     * `FILTER_VALIDATE_INT` rejects '', 'abc', and '3.5' outright (returns
     * `false`, not a truncated/rounded guess); a negative but
     * otherwise-well-formed integer like '-1' is then rejected by the
     * explicit `< 0` check below. Never coerces — either the value parses
     * cleanly as >= 0, or this returns `null` and prints exactly which
     * source (`--days` or the config key) failed.
     */
    private function parseNonNegativeInteger(mixed $value, string $source): ?int
    {
        $normalized = is_int($value) ? (string) $value : $value;

        $filtered = is_string($normalized) ? filter_var($normalized, FILTER_VALIDATE_INT) : false;

        if ($filtered === false || $filtered < 0) {
            $printable = is_scalar($value) ? (string) $value : get_debug_type($value);
            $this->error("Invalid retention value for {$source}: '{$printable}' is not a non-negative integer.");

            return null;
        }

        return $filtered;
    }
}
