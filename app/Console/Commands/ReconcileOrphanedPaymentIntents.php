<?php

namespace App\Console\Commands;

use App\Enums\PaymentIntentAttemptStatus;
use App\Models\OrderPaymentIntentAttempt;
use App\Payments\Stripe\StripePaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recovers Orders where Stripe successfully created a PaymentIntent but the
 * process died (or the following DB write failed) before any local claim or
 * pending Wallet transaction was recorded — see
 * StripePaymentService::createPaymentIntentForOrder() and
 * docs/wallet/integrations.md for the gap this closes.
 *
 * Recovery only ever recreates the pending local state (claim + Wallet
 * transaction) by re-issuing the same idempotent Stripe request — it never
 * confirms a transaction or moves a Wallet balance. Settlement stays the
 * exclusive responsibility of the Stripe webhook (StripeEventDispatcher),
 * triggered only by Stripe telling us the PaymentIntent actually succeeded.
 *
 * Retrying re-issues the request under the *same* idempotency key, which
 * only guarantees Stripe returns the original PaymentIntent while that key
 * is still recognized — Stripe may remove a key after it has been around
 * for at least 24 hours, after which reusing it risks being processed as a
 * new request instead. `--max-age` is an absolute ceiling on an attempt's
 * age, checked before `--max-attempts`, so raising the attempt/retry budget
 * alone can never push recovery past the safe window: past `--max-age` an
 * attempt goes straight to `needs_attention` regardless of how many
 * recovery attempts it has left.
 */
class ReconcileOrphanedPaymentIntents extends Command
{
    protected $signature = 'app:reconcile-orphaned-payment-intents
        {--stale-after=5 : Minutes an attempt must have been pending before it is considered orphaned}
        {--max-attempts=5 : Recovery attempts before an attempt is marked needs_attention and left alone}
        {--max-age=720 : Minutes since an attempt was created before it is marked needs_attention regardless of --max-attempts, kept well under Stripe\'s idempotency key retention window (at least 24h) so a repeat request is never treated as a new one}';

    protected $description = 'Recover Orders whose Stripe PaymentIntent was created but never got a local claim or Wallet transaction';

    public function handle(StripePaymentService $stripePaymentService): int
    {
        $staleAfter = (int) $this->option('stale-after');
        $maxAttempts = (int) $this->option('max-attempts');
        $maxAge = (int) $this->option('max-age');

        $orphaned = OrderPaymentIntentAttempt::query()
            ->where('status', PaymentIntentAttemptStatus::Pending)
            ->where('created_at', '<=', now()->subMinutes($staleAfter))
            ->with('order')
            ->get();

        if ($orphaned->isEmpty()) {
            $this->info('No orphaned PaymentIntent attempts found.');

            return self::SUCCESS;
        }

        foreach ($orphaned as $attempt) {
            $ageMinutes = $attempt->created_at->diffInMinutes(now());

            if ($ageMinutes >= $maxAge) {
                $attempt->update(['status' => PaymentIntentAttemptStatus::NeedsAttention]);

                $context = [
                    'order_id' => $attempt->order_id,
                    'idempotency_key' => $attempt->idempotency_key,
                    'age_minutes' => $ageMinutes,
                    'max_age' => $maxAge,
                ];

                Log::error('PaymentIntent reconciliation attempt exceeded the safe recovery window, needs manual attention.', $context);
                $this->error("Order #{$attempt->order_id} needs manual attention: attempt is older than the {$maxAge}-minute safe recovery window.");

                continue;
            }

            try {
                $stripePaymentService->createPaymentIntentForOrder($attempt->order);

                $this->info("Recovered Order #{$attempt->order_id} ({$attempt->idempotency_key}).");
            } catch (Throwable $e) {
                $attempt->increment('recovery_attempts');
                $attempt->update([
                    'last_recovery_attempted_at' => now(),
                    'last_recovery_error' => $e->getMessage(),
                ]);

                $context = [
                    'order_id' => $attempt->order_id,
                    'idempotency_key' => $attempt->idempotency_key,
                    'recovery_attempts' => $attempt->recovery_attempts,
                    'error' => $e->getMessage(),
                ];

                if ($attempt->recovery_attempts >= $maxAttempts) {
                    $attempt->update(['status' => PaymentIntentAttemptStatus::NeedsAttention]);

                    Log::error('PaymentIntent reconciliation exhausted retries, needs manual attention.', $context);
                    $this->error("Order #{$attempt->order_id} needs manual attention after {$attempt->recovery_attempts} failed recovery attempts: {$e->getMessage()}");
                } else {
                    Log::warning('PaymentIntent reconciliation attempt failed, will retry.', $context);
                    $this->error("Failed to recover Order #{$attempt->order_id} (attempt {$attempt->recovery_attempts}/{$maxAttempts}): {$e->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }
}
