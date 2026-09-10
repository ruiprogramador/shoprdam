<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Enums\RecoveryOutcome;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Models\PaymentRecoveryAction;
use App\Domain\Payments\RecoveryErrorFormatter;
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
 * - a `pending` attempt already at least as stale as automatic
 *   reconciliation itself requires (see PaymentRecoveryPolicy::isStalePending())
 *   is retried via App\Domain\Payments\Services\PaymentAttemptRecoveryService::recover(),
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
 * PaymentAttempt to succeeded, releasing a lease that hasn't expired,
 * manually recovering a *fresh* pending attempt that automatic
 * reconciliation itself wouldn't touch yet, or any other action not already
 * backed by provider/domain evidence — see PaymentRecoveryPolicy for the
 * eligibility rule this controller re-checks itself (defense in depth)
 * before ever calling either method above. An attempt that is
 * `needs_attention` with no provider claim at all has no safe action here
 * on purpose: there is no provider evidence to retry against, so this fails
 * closed rather than guessing.
 *
 * Every invocation is durably audited *before* it runs, not after: retry()
 * inserts a payment_recovery_actions row with outcome `started` first, then
 * updates that same row once the operation finishes — see startAction()/
 * finishAction(). If this process crashes between the two (or the recovery
 * call itself never returns), the `started` row is still there as evidence
 * an operator initiated this, exactly because it was its own committed
 * INSERT, never held in a transaction together with the provider call that
 * follows it.
 *
 * Nothing here ever persists or renders a raw exception message — every
 * failure detail (the audit row's `detail`, a flash message) is built by
 * App\Domain\Payments\RecoveryErrorFormatter from structured metadata only
 * (provider, exception class, HTTP status, retryability); an
 * already-stored last_recovery_error/last_replay_error is surfaced on the
 * inspect page only as "an error is on record", never its content — see
 * that formatter's own docblock for why a denylist over free-text provider
 * messages was rejected as insufficiently fail-closed.
 */
class PaymentRecoveryController extends Controller
{
    /**
     * Mirrors App\Console\Commands\ReconcileOrphanedPaymentAttempts's own
     * option defaults exactly — an operator triggering an out-of-band
     * retry right now gets the same eligibility rules as the next scheduled
     * automatic run would apply to the same attempt, never looser ones.
     * (--stale-after's default lives on PaymentRecoveryPolicy instead,
     * since the policy — not this controller — is what decides whether a
     * `pending` attempt is eligible at all.)
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
                'has_last_recovery_error' => RecoveryErrorFormatter::hasStoredMessage($attempt->last_recovery_error),
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
        // action. Re-fetches fresh and re-runs the SAME policy rule (never a
        // hand-rolled re-derivation of it) so a status that changed between
        // the authorizeAdmin() call above and here (e.g. a concurrent
        // webhook settling it, or the attempt aging past its own
        // --stale-after window mid-request) is what this decision is
        // actually made against.
        $attempt = $attempt->fresh();

        if ($this->policy->isStalePending($attempt)) {
            return $this->retryPendingAttempt($attempt, $admin);
        }

        if ($attempt->provider_reference !== null) {
            return $this->replayClaimedAttempt($attempt, $admin);
        }

        abort(422, 'No safe recovery action exists for this attempt — it needs manual investigation outside this tool.');
    }

    private function retryPendingAttempt(PaymentAttempt $attempt, Admin $admin): RedirectResponse
    {
        $record = $this->startAction($attempt, $admin, 'retry_recovery');

        $result = $this->recovery->recover($attempt, self::MAX_ATTEMPTS, self::MAX_AGE_MINUTES, self::LEASE_TIMEOUT_MINUTES);

        $summary = $result->exception !== null
            ? RecoveryErrorFormatter::summarizeForAudit($result->exception, $attempt->provider, $result->retryable)
            : null;

        $this->finishAction($record, $this->outcomeLabel($result->outcome), $summary);

        $route = redirect()->route('admin.payments.recovery.show', $attempt);

        return match ($result->outcome) {
            RecoveryOutcome::Recovered => $route->with('success', 'Recovered — the attempt is now claimed.'),
            RecoveryOutcome::Skipped => $route->with('info', 'Another process is already handling this attempt right now — nothing to do.'),
            RecoveryOutcome::AgeExceeded => $route->with('error', 'This attempt exceeded the safe recovery window and was marked needs_attention.'),
            RecoveryOutcome::NeedsAttention => $route->with('error', "Recovery failed and needs attention: {$summary}"),
            RecoveryOutcome::RetryPending => $route->with('info', "Recovery failed but is retryable — it will be tried again automatically: {$summary}"),
            RecoveryOutcome::AlreadyProgressed => $route->with('info', 'This attempt had already moved past pending before this retry ran — nothing was overwritten.'),
        };
    }

    private function replayClaimedAttempt(PaymentAttempt $attempt, Admin $admin): RedirectResponse
    {
        $record = $this->startAction($attempt, $admin, 'replay_events');

        try {
            $this->paymentService->finalizeAttempt($attempt);

            $this->finishAction($record, 'replayed', null);

            return redirect()->route('admin.payments.recovery.show', $attempt)
                ->with('success', 'Replayed any pending provider events for this attempt.');
        } catch (Throwable $e) {
            $summary = RecoveryErrorFormatter::summarizeForAudit($e, $attempt->provider);

            $this->finishAction($record, 'replay_failed', $summary);

            return redirect()->route('admin.payments.recovery.show', $attempt)
                ->with('error', "Replay failed: {$summary}");
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
                'has_last_replay_error' => RecoveryErrorFormatter::hasStoredMessage($event->last_replay_error),
                'created_at' => $event->created_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Inserts the audit row *before* any recovery/replay operation runs, in
     * its own committed statement — deliberately not wrapped in a
     * transaction together with what follows, and never spanning the
     * provider HTTP call recover()/finalizeAttempt() may make. If this
     * process crashes (or the provider call hangs) before finishAction()
     * ever runs, this `started` row is what proves an operator initiated
     * the action — see the class docblock.
     */
    private function startAction(PaymentAttempt $attempt, Admin $admin, string $action): PaymentRecoveryAction
    {
        $record = $attempt->recoveryActions()->create([
            'admin_id' => $admin->id,
            'action' => $action,
            'outcome' => 'started',
            'detail' => null,
        ]);

        logger()->info('Admin payment recovery action started.', [
            'payment_recovery_action_id' => $record->id,
            'payment_attempt_id' => $attempt->id,
            'payment_id' => $attempt->payment_id,
            'admin_id' => $admin->id,
            'action' => $action,
        ]);

        return $record;
    }

    /** Updates the same row startAction() created, once the operation's real outcome is known. */
    private function finishAction(PaymentRecoveryAction $record, string $outcome, ?string $detail): void
    {
        $record->update(['outcome' => $outcome, 'detail' => $detail]);

        logger()->info('Admin payment recovery action finished.', [
            'payment_recovery_action_id' => $record->id,
            'payment_attempt_id' => $record->payment_attempt_id,
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
