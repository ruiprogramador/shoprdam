<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Payments\RecoveryErrorFormatter;
use App\Domain\Payouts\DTOs\PayoutProviderOutcome;
use App\Domain\Payouts\Enums\PayoutOutcomeType;
use App\Domain\Payouts\Enums\RecoveryOutcome;
use App\Domain\Payouts\Exceptions\ExternalTransferReferenceAlreadyUsedException;
use App\Domain\Payouts\Exceptions\PayoutAttemptMismatchException;
use App\Domain\Payouts\Models\PayoutAttempt;
use App\Domain\Payouts\Models\PayoutRecoveryAction;
use App\Domain\Payouts\Services\PayoutAttemptRecoveryService;
use App\Domain\Payouts\Services\PayoutEventProcessor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmManualPayoutRequest;
use App\Models\Admin;
use App\Policies\PayoutRecoveryPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The only place an operator's manual confirmation of a SEPA transfer ever
 * reaches this domain — never a direct Wallet/Payout/PayoutAttempt mutation.
 * Mirrors App\Http\Controllers\Admin\PaymentRecoveryController's audit
 * discipline exactly: every invocation is durably audited *before* it runs
 * (a `payout_recovery_actions` row with outcome `started`, its own committed
 * insert), then updated once the real outcome is known.
 *
 * `provider`/`provider_reference` are never read from the request — they
 * always come from the route-bound PayoutAttempt itself (see
 * ConfirmManualPayoutRequest's own docblock), so confirming can never target
 * a different attempt than the URL names. This controller builds a generic
 * PayoutProviderOutcome and hands it to PayoutEventProcessor::apply() — the
 * exact same canonical settlement path a future automatic provider's own
 * webhook would call. Reuses App\Domain\Payments\RecoveryErrorFormatter
 * as-is: never persists or renders a raw exception message.
 */
class PayoutRecoveryController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const MAX_AGE_MINUTES = 720;

    private const LEASE_TIMEOUT_MINUTES = 15;

    public function __construct(
        private readonly PayoutEventProcessor $processor,
        private readonly PayoutAttemptRecoveryService $recovery,
        private readonly PayoutRecoveryPolicy $policy,
    ) {}

    /**
     * Re-claims a stuck `pending` attempt — the exact same lease/CAS-guarded
     * algorithm App\Console\Commands\ReconcileOrphanedPayoutAttempts runs on
     * a schedule. Never settles or reverses anything; mirrors
     * PaymentRecoveryController::retryPendingAttempt().
     */
    public function retry(PayoutAttempt $attempt): RedirectResponse
    {
        $this->authorizeAdmin('retry', $attempt);

        $admin = auth('admin')->user();
        $record = $this->startAction($attempt, $admin, 'retry_recovery', []);

        $result = $this->recovery->recover($attempt, self::MAX_ATTEMPTS, self::MAX_AGE_MINUTES, self::LEASE_TIMEOUT_MINUTES);

        $summary = $result->exception !== null
            ? RecoveryErrorFormatter::summarizeForAudit($result->exception, $attempt->provider, $result->retryable)
            : null;

        $this->finishAction($record, $this->outcomeLabel($result->outcome), $summary);

        return match ($result->outcome) {
            RecoveryOutcome::Recovered => redirect()->back()->with('success', 'Recovered — the attempt is now claimed.'),
            RecoveryOutcome::Skipped => redirect()->back()->with('info', 'Another process is already handling this attempt right now — nothing to do.'),
            RecoveryOutcome::AgeExceeded => redirect()->back()->with('error', 'This attempt exceeded the safe recovery window and was marked needs_attention.'),
            RecoveryOutcome::NeedsAttention => redirect()->back()->with('error', "Recovery failed and needs attention: {$summary}"),
            RecoveryOutcome::RetryPending => redirect()->back()->with('info', "Recovery failed but is retryable: {$summary}"),
            RecoveryOutcome::AlreadyProgressed => redirect()->back()->with('info', 'This attempt had already moved past pending before this retry ran.'),
        };
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

    public function confirm(ConfirmManualPayoutRequest $request, PayoutAttempt $attempt): RedirectResponse
    {
        $this->authorizeAdmin('confirm', $attempt);

        $admin = auth('admin')->user();
        $data = $request->validated();

        $record = $this->startAction($attempt, $admin, 'manual_confirmation', $data);

        try {
            $statusBefore = $attempt->fresh()->status;

            $this->processor->apply(new PayoutProviderOutcome(
                provider: $attempt->provider,
                providerReference: $attempt->provider_reference,
                type: $data['outcome'] === 'succeeded' ? PayoutOutcomeType::Succeeded : PayoutOutcomeType::Failed,
                amount: $data['amount'],
                currency: strtoupper($data['currency']),
                correlationId: (string) $attempt->payout_id,
                externalTransferReference: $data['external_transfer_reference'] ?? null,
                failureReason: $data['failure_note'] ?? null,
                executedAt: Carbon::parse($data['executed_at']),
            ));

            $statusAfter = $attempt->fresh()->status;
            $outcomeLabel = $statusBefore === $statusAfter ? 'already_settled' : 'applied';

            $this->finishAction($record, $outcomeLabel, null);

            return redirect()->back()
                ->with('success', "Payout attempt #{$attempt->id} confirmed ({$data['outcome']}).");
        } catch (PayoutAttemptMismatchException $e) {
            $summary = RecoveryErrorFormatter::summarizeForAudit($e, $attempt->provider);
            $this->finishAction($record, 'rejected', $summary);

            return redirect()->back()
                ->with('error', "Submitted amount/currency does not match this payout — rejected: {$summary}");
        } catch (ExternalTransferReferenceAlreadyUsedException $e) {
            $summary = RecoveryErrorFormatter::summarizeForAudit($e, $attempt->provider);
            $this->finishAction($record, 'rejected', $summary);

            return redirect()->back()
                ->with('error', 'This external transfer reference has already been used to confirm a different payout.');
        } catch (Throwable $e) {
            $summary = RecoveryErrorFormatter::summarizeForAudit($e, $attempt->provider);
            $this->finishAction($record, 'failed', $summary);

            return redirect()->back()
                ->with('error', "Confirmation failed: {$summary}");
        }
    }

    /**
     * Inserts the audit row *before* the confirmation runs, in its own
     * committed statement — mirrors PaymentRecoveryController::startAction()
     * exactly, including why. `metadata` captures the submitted payload as
     * historical evidence — never edited again after finishAction() below.
     */
    private function startAction(PayoutAttempt $attempt, Admin $admin, string $action, array $metadata): PayoutRecoveryAction
    {
        $record = $attempt->recoveryActions()->create([
            'admin_id' => $admin->id,
            'action' => $action,
            'outcome' => 'started',
            'detail' => null,
            'metadata' => $metadata,
        ]);

        logger()->info('Admin payout recovery action started.', [
            'payout_recovery_action_id' => $record->id,
            'payout_attempt_id' => $attempt->id,
            'payout_id' => $attempt->payout_id,
            'admin_id' => $admin->id,
            'action' => $action,
        ]);

        return $record;
    }

    /** Updates the same row startAction() created, once the operation's real outcome is known. Never called twice for the same row. */
    private function finishAction(PayoutRecoveryAction $record, string $outcome, ?string $detail): void
    {
        $record->update(['outcome' => $outcome, 'detail' => $detail]);

        logger()->info('Admin payout recovery action finished.', [
            'payout_recovery_action_id' => $record->id,
            'payout_attempt_id' => $record->payout_attempt_id,
            'outcome' => $outcome,
        ]);
    }

    /**
     * Mirrors PaymentRecoveryController::authorizeAdmin() exactly: the
     * default Gate resolves against the 'web' guard's user, never the admin
     * guard's — every admin-guarded controller in this app checks its own
     * guard's user against the policy explicitly instead.
     */
    private function authorizeAdmin(string $ability, PayoutAttempt $attempt): void
    {
        $admin = auth('admin')->user();

        abort_unless(
            $admin && method_exists($this->policy, $ability) && $this->policy->{$ability}($admin, $attempt),
            403
        );
    }
}
