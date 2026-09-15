<?php

namespace App\Domain\Payouts\Services;

use App\Domain\Payouts\DTOs\PayoutProviderOutcome;
use App\Domain\Payouts\Enums\PayoutAttemptStatus;
use App\Domain\Payouts\Enums\PayoutOutcomeType;
use App\Domain\Payouts\Enums\PayoutStatus;
use App\Domain\Payouts\Exceptions\ExternalTransferReferenceAlreadyUsedException;
use App\Domain\Payouts\Exceptions\PayoutAttemptMismatchException;
use App\Domain\Payouts\Exceptions\PayoutAttemptNotFoundException;
use App\Domain\Payouts\Models\Payout;
use App\Domain\Payouts\Models\PayoutAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a provider-neutral PayoutProviderOutcome to a PayoutAttempt/Payout
 * — the single canonical settlement path every source of truth about a
 * transfer funnels through, whether that's an operator's manual
 * confirmation today (see App\Http\Controllers\Admin\PayoutRecoveryController)
 * or a future automatic provider's own webhook/poll translator. Mirrors
 * App\Domain\Payments\Services\PaymentEventProcessor, with one deliberate,
 * load-bearing difference: applyFailed() here never touches the Wallet.
 *
 * Unlike Payments, a Payout's reservation debit is already posted (at
 * Payout creation, not at attempt-claim time) — so a Succeeded outcome
 * needs no further ledger action (the debit already IS the final effect),
 * and a Failed outcome for *this attempt* must NOT release it: an attempt
 * failing is never, by itself, a fact about the Payout (see
 * PayoutAttemptStatus's own docblock). Releasing the reservation only ever
 * happens through PayoutService::abandon(), a decision about the Payout
 * aggregate, never a side effect of one attempt's outcome.
 *
 * Deliberately has no unmatched-event inbox (unlike
 * payment_provider_events): ManualPayoutProvider's confirmation always
 * targets a specific, already-existing PayoutAttempt (the admin route binds
 * it), so there is no "event arrived before the local claim exists"
 * scenario to queue for later replay in this branch. A future webhook-driven
 * provider that needs one should add it the same way payments did, rather
 * than this class guessing at a shape nothing yet exercises.
 */
class PayoutEventProcessor
{
    /**
     * @throws PayoutAttemptNotFoundException if no PayoutAttempt claims this (provider, reference) pair
     * @throws PayoutAttemptMismatchException if the outcome's amount/currency/correlation
     *                                        doesn't match the Payout this attempt belongs to
     * @throws ExternalTransferReferenceAlreadyUsedException if a Succeeded outcome's evidence
     *                                                       reference already identifies a different attempt
     */
    public function apply(PayoutProviderOutcome $outcome): void
    {
        match ($outcome->type) {
            PayoutOutcomeType::Succeeded => $this->applySucceeded($outcome),
            PayoutOutcomeType::Failed => $this->applyFailed($outcome),
            PayoutOutcomeType::Informational => Log::info('Informational payout provider outcome, no state change.', $this->logContext($outcome)),
            PayoutOutcomeType::Unrecognized => Log::info('Unhandled payout provider outcome type.', $this->logContext($outcome)),
        };
    }

    /**
     * Terminal success: the reservation debit stands as the final financial
     * effect — never a second ledger entry. The attempt's own CAS guard
     * (`whereIn('status', [...non-terminal...])`) is what makes a duplicate
     * or late-arriving Succeeded delivery a safe no-op, whether it's a
     * genuine redelivery of the same confirmation or a stale one racing a
     * second admin submitting at the same time.
     */
    private function applySucceeded(PayoutProviderOutcome $outcome): void
    {
        $attempt = $this->findAttempt($outcome->provider, $outcome->providerReference);

        $this->assertOutcomeMatchesPayout($outcome, $attempt->payout);

        try {
            DB::transaction(function () use ($attempt, $outcome) {
                $affected = PayoutAttempt::where('id', $attempt->id)
                    ->whereIn('status', [PayoutAttemptStatus::Pending, PayoutAttemptStatus::Claimed, PayoutAttemptStatus::NeedsAttention])
                    ->whereNull('external_transfer_reference')
                    ->update([
                        'status' => PayoutAttemptStatus::Succeeded,
                        'external_transfer_reference' => $outcome->externalTransferReference,
                    ]);

                if ($affected !== 1) {
                    // Already settled (or racing another settlement that won) — no-op.
                    return;
                }

                Payout::where('id', $attempt->payout_id)
                    ->whereIn('status', [PayoutStatus::Reserved, PayoutStatus::Processing])
                    ->update(['status' => PayoutStatus::Succeeded, 'completed_at' => now()]);

                // Deliberately no WalletTransactionService call here — the
                // reservation debit already IS the settlement. See class docblock.

                Log::info('Payout attempt succeeded.', $this->logContext($outcome, $attempt));
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // unique(provider, external_transfer_reference) rejected the
            // UPDATE — this transaction rolled back entirely (nothing else
            // happens after it in this closure, so there is no "poisoned
            // transaction" concern on PostgreSQL here, unlike
            // PayoutService::request()). A real bank reference must never
            // be accepted as evidence for two different attempts.
            throw new ExternalTransferReferenceAlreadyUsedException(
                "external_transfer_reference '{$outcome->externalTransferReference}' for provider ".
                "'{$outcome->provider}' already identifies a different PayoutAttempt.",
                previous: $e,
            );
        }
    }

    /**
     * Terminal failure of *this attempt only*. Never reverses the Wallet
     * and never changes the Payout's own status — see class docblock. The
     * correction this method exists to enforce: an outcome naming a
     * historical, no-longer-current attempt must never move the Payout at
     * all, even indirectly, and a duplicate/late delivery for the
     * currently-current attempt must resolve it exactly once.
     */
    private function applyFailed(PayoutProviderOutcome $outcome): void
    {
        $attempt = $this->findAttempt($outcome->provider, $outcome->providerReference);

        $this->assertOutcomeMatchesPayout($outcome, $attempt->payout);

        DB::transaction(function () use ($attempt, $outcome) {
            $affected = PayoutAttempt::where('id', $attempt->id)
                ->whereIn('status', [PayoutAttemptStatus::Pending, PayoutAttemptStatus::Claimed, PayoutAttemptStatus::NeedsAttention])
                ->update([
                    'status' => PayoutAttemptStatus::Failed,
                    'external_transfer_reference' => $outcome->externalTransferReference,
                ]);

            if ($affected !== 1) {
                // Already resolved by a racing/duplicate delivery — no-op.
                return;
            }

            // EXACT CURRENT ATTEMPT ISOLATION: only move the Payout back to
            // Reserved if this attempt is STILL the one it currently points
            // at. A duplicate/late outcome for a historical attempt (one a
            // newer attempt has since superseded) must never touch the
            // Payout's state — conditioning on current_payout_attempt_id in
            // the same UPDATE's WHERE clause, not a separate read-then-write,
            // is what makes this atomic against a concurrent
            // createDurableAttempt() call already having moved the pointer on.
            Payout::where('id', $attempt->payout_id)
                ->where('current_payout_attempt_id', $attempt->id)
                ->where('status', PayoutStatus::Processing)
                ->update(['status' => PayoutStatus::Reserved]);

            // No reversal, no Payout-terminal transition — see class docblock.

            Log::info('Payout attempt failed; reservation left intact, a new attempt may follow.', $this->logContext($outcome, $attempt));
        });
    }

    /**
     * The final authority on "which attempt does this outcome belong to" is
     * (provider, provider_reference) — never provider+payout_id, and never
     * whichever attempt happens to be `current` by the time the outcome is
     * processed (see this class's own docblock and
     * PaymentEventProcessor::markSettled() for the identical reasoning on
     * the payments side).
     *
     * @throws PayoutAttemptNotFoundException
     */
    private function findAttempt(string $provider, string $providerReference): PayoutAttempt
    {
        return PayoutAttempt::with('payout.currency')
            ->where('provider', $provider)
            ->where('provider_reference', $providerReference)
            ->first() ?? throw new PayoutAttemptNotFoundException(
                "No PayoutAttempt found claiming provider '{$provider}' reference '{$providerReference}' — ".
                'refusing to apply an outcome against unidentified financial history.'
            );
    }

    /**
     * @throws PayoutAttemptMismatchException
     */
    private function assertOutcomeMatchesPayout(PayoutProviderOutcome $outcome, Payout $payout): void
    {
        $expectedCurrency = strtolower($payout->currency->code);
        $expectedCorrelationId = (string) $payout->id;

        if (bccomp($outcome->amount, $payout->amount, 2) === 0
            && strtolower($outcome->currency) === $expectedCurrency
            && $outcome->correlationId === $expectedCorrelationId) {
            return;
        }

        throw new PayoutAttemptMismatchException(
            "Outcome for provider reference {$outcome->providerReference} does not match Payout #{$payout->id}: ".
            "expected amount={$payout->amount} currency={$expectedCurrency} correlationId={$expectedCorrelationId}, ".
            "got amount={$outcome->amount} currency={$outcome->currency} correlationId=".($outcome->correlationId ?? 'null'),
        );
    }

    /**
     * Portable across drivers: MySQL/SQLite report a unique-constraint
     * violation as SQLSTATE 23000, PostgreSQL as 23505.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true);
    }

    /** @return array<string, mixed> */
    private function logContext(PayoutProviderOutcome $outcome, ?PayoutAttempt $attempt = null): array
    {
        return [
            'provider' => $outcome->provider,
            'provider_reference' => $outcome->providerReference,
            'payout_attempt_id' => $attempt?->id,
            'payout_id' => $attempt?->payout_id,
        ];
    }
}
