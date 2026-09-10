<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Enums\RecoveryOutcome;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Services\PaymentAttemptRecoveryService;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Payments\Services\PaymentsHealthCheck;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Policies\PaymentRecoveryPolicy;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Narrow, safe administrative recovery for PaymentAttempts stuck the way
 * `php artisan payments:health` (App\Domain\Payments\Services\PaymentsHealthCheck)
 * already surfaces them — never a new financial settlement path. Every
 * mutating action here is a direct call to a method the automatic side of
 * this domain already trusts:
 *
 * - a `pending` attempt is retried via
 *   App\Domain\Payments\Services\PaymentAttemptRecoveryService::recover(),
 *   the exact same lease/CAS-guarded algorithm
 *   App\Console\Commands\ReconcileOrphanedPaymentAttempts runs on a
 *   schedule — a manual click and the next scheduled tick can race safely
 *   because they are, underneath, the same code path;
 * - an attempt that already has a `provider_reference` (claimed, succeeded,
 *   or failed) is replayed via PaymentService::finalizeAttempt(), which for
 *   an already-claimed attempt never calls the provider again — it only
 *   re-runs PaymentEventProcessor::replayUnmatchedEvents(), itself required
 *   to be idempotent.
 *
 * Deliberately does NOT expose: marking a Payment paid, setting a
 * PaymentAttempt to succeeded, releasing a lease that hasn't expired, or
 * any other action not already backed by provider/domain evidence — see
 * PaymentRecoveryPolicy for the eligibility rule this controller re-checks
 * itself (defense in depth) before ever calling either method above. An
 * attempt that is `needs_attention` with no provider claim at all has no
 * safe action here on purpose: there is no provider evidence to retry
 * against, so this fails closed rather than guessing.
 *
 * Every invocation — successful or not — is written to
 * payment_recovery_actions (App\Domain\Payments\Models\PaymentRecoveryAction)
 * before the response is returned, and mirrored to the log, so a manual
 * recovery attempt is never a silent, unauditable action.
 */
class PaymentRecoveryController extends Controller
{
    /**
     * Mirrors App\Console\Commands\ReconcileOrphanedPaymentAttempts's own
     * option defaults exactly — an operator triggering an out-of-band
     * retry right now gets the same eligibility rules as the next scheduled
     * automatic run would apply to the same attempt, never looser ones.
     */
    private const MAX_ATTEMPTS = 5;

    private const MAX_AGE_MINUTES = 720;

    private const LEASE_TIMEOUT_MINUTES = 15;

    public function __construct(
        private readonly PaymentAttemptRecoveryService $recovery,
        private readonly PaymentService $paymentService,
        private readonly PaymentsHealthCheck $healthCheck,
        private readonly PaymentRecoveryPolicy $policy,
    ) {}

    /**
     * Entry point into this tool: the same NeedsAttention/stale-pending
     * samples `payments:health` already computes, reused as-is rather than
     * this controller running its own duplicate query — see
     * PaymentsHealthCheck.
     */
    public function index(): Response
    {
        $report = $this->healthCheck->report();

        return Inertia::render('Admin/Payments/Recovery/Index', [
            'needs_attention' => $report->needsAttentionSample,
            'stale_pending' => $report->stalePendingSample,
        ]);
    }

    public function show(PaymentAttempt $attempt): Response
    {
        $this->authorizeAdmin('view', $attempt);

        $attempt->load(['payment.order', 'recoveryActions.admin']);

        return Inertia::render('Admin/Payments/Recovery/Show', [
            'attempt' => [
                'id' => $attempt->id,
                'payment_id' => $attempt->payment_id,
                'order_id' => $attempt->payment?->order_id,
                'provider' => $attempt->provider,
                'method' => $attempt->method,
                'status' => $attempt->status->value,
                'provider_reference' => $attempt->provider_reference,
                'recovery_attempts' => $attempt->recovery_attempts,
                'last_recovery_error' => $attempt->last_recovery_error,
                'last_attempted_at' => $attempt->last_attempted_at?->toIso8601String(),
                'locked_until' => $attempt->locked_until?->toIso8601String(),
                'created_at' => $attempt->created_at->toIso8601String(),
            ],
            'pending_events' => $this->pendingEventsFor($attempt),
            'can_retry' => $this->policy->retry(auth('admin')->user(), $attempt),
            'history' => $attempt->recoveryActions->map(fn ($action) => [
                'id' => $action->id,
                'action' => $action->action,
                'outcome' => $action->outcome,
                'detail' => $action->detail,
                'admin' => $action->admin?->name,
                'created_at' => $action->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function retry(PaymentAttempt $attempt): RedirectResponse
    {
        $this->authorizeAdmin('retry', $attempt);

        $admin = auth('admin')->user();

        // Defense in depth — PaymentRecoveryPolicy::retry() already checked
        // this; never trust a single check for a mutating financial-adjacent
        // action. Re-fetches fresh so a status that changed between the
        // authorize() call above and here (e.g. a concurrent webhook
        // settling it) is what this decision is actually made against.
        $attempt = $attempt->fresh();

        if ($attempt->status === PaymentAttemptStatus::Pending) {
            return $this->retryPendingAttempt($attempt, $admin);
        }

        if ($attempt->provider_reference !== null) {
            return $this->replayClaimedAttempt($attempt, $admin);
        }

        abort(422, 'No safe recovery action exists for this attempt — it needs manual investigation outside this tool.');
    }

    private function retryPendingAttempt(PaymentAttempt $attempt, Admin $admin): RedirectResponse
    {
        $result = $this->recovery->recover($attempt, self::MAX_ATTEMPTS, self::MAX_AGE_MINUTES, self::LEASE_TIMEOUT_MINUTES);

        $this->recordAction($attempt, $admin, 'retry_recovery', $this->outcomeLabel($result->outcome), $result->exception?->getMessage());

        $route = redirect()->route('admin.payments.recovery.show', $attempt);

        return match ($result->outcome) {
            RecoveryOutcome::Recovered => $route->with('success', 'Recovered — the attempt is now claimed.'),
            RecoveryOutcome::Skipped => $route->with('info', 'Another process is already handling this attempt right now — nothing to do.'),
            RecoveryOutcome::AgeExceeded => $route->with('error', 'This attempt exceeded the safe recovery window and was marked needs_attention.'),
            RecoveryOutcome::NeedsAttention => $route->with('error', "Recovery failed and needs attention: {$result->exception?->getMessage()}"),
            RecoveryOutcome::RetryPending => $route->with('info', "Recovery failed but is retryable — it will be tried again automatically: {$result->exception?->getMessage()}"),
            RecoveryOutcome::AlreadyProgressed => $route->with('info', 'This attempt had already moved past pending before this retry ran — nothing was overwritten.'),
        };
    }

    private function replayClaimedAttempt(PaymentAttempt $attempt, Admin $admin): RedirectResponse
    {
        try {
            $this->paymentService->finalizeAttempt($attempt);

            $this->recordAction($attempt, $admin, 'replay_events', 'replayed', null);

            return redirect()->route('admin.payments.recovery.show', $attempt)
                ->with('success', 'Replayed any pending provider events for this attempt.');
        } catch (Throwable $e) {
            $this->recordAction($attempt, $admin, 'replay_events', 'replay_failed', $e->getMessage());

            return redirect()->route('admin.payments.recovery.show', $attempt)
                ->with('error', "Replay failed: {$e->getMessage()}");
        }
    }

    /** @return list<array<string, mixed>> */
    private function pendingEventsFor(PaymentAttempt $attempt): array
    {
        if ($attempt->provider_reference === null) {
            return [];
        }

        return PaymentProviderEvent::query()
            ->where('provider', $attempt->provider)
            ->where('provider_reference', $attempt->provider_reference)
            ->where('status', ProviderEventStatus::Pending)
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentProviderEvent $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'replay_attempts' => $event->replay_attempts,
                'last_replay_error' => $event->last_replay_error,
                'created_at' => $event->created_at->toIso8601String(),
            ])
            ->all();
    }

    /** The single write path for payment_recovery_actions — every retry()/replay call goes through here, success or failure. */
    private function recordAction(PaymentAttempt $attempt, Admin $admin, string $action, string $outcome, ?string $detail): void
    {
        $attempt->recoveryActions()->create([
            'admin_id' => $admin->id,
            'action' => $action,
            'outcome' => $outcome,
            'detail' => $detail,
        ]);

        logger()->info('Admin payment recovery action.', [
            'payment_attempt_id' => $attempt->id,
            'payment_id' => $attempt->payment_id,
            'admin_id' => $admin->id,
            'action' => $action,
            'outcome' => $outcome,
        ]);
    }

    private function outcomeLabel(RecoveryOutcome $outcome): string
    {
        return match ($outcome) {
            RecoveryOutcome::Recovered => 'claimed',
            RecoveryOutcome::Skipped => 'skipped',
            RecoveryOutcome::AgeExceeded, RecoveryOutcome::NeedsAttention => 'needs_attention',
            RecoveryOutcome::RetryPending => 'retry_pending',
            RecoveryOutcome::AlreadyProgressed => 'left_as_is',
        };
    }

    /**
     * Mirrors App\Http\Controllers\Admin\KycController's own
     * authorizeAdmin() exactly, for the same reason: Laravel's
     * $this->authorize()/Gate::authorize() resolve against the default
     * ('web') guard's user, which is never who's signed in here — every
     * admin-guarded controller in this app checks its own guard's user
     * against the policy explicitly instead.
     */
    private function authorizeAdmin(string $ability, PaymentAttempt $attempt): void
    {
        $admin = auth('admin')->user();

        abort_unless(
            $admin && method_exists($this->policy, $ability) && $this->policy->{$ability}($admin, $attempt),
            403
        );
    }
}
