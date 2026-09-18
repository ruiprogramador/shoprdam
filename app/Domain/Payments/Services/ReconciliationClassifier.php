<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\DTOs\ReconciliationCandidate;
use App\Domain\Payments\DTOs\ReconciliationClassification;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\ReconciliationCategory;
use App\Domain\Payments\Enums\ReconciliationSeverity;

/**
 * A pure, side-effect-free function: local candidate + provider retrieval
 * result (or `null` for a clean "not found") in, one
 * ReconciliationClassification out. No I/O, no database access, no provider
 * call — see docs/financial/RECONCILIATION.md §7's architecture diagram and
 * §8's taxonomy, which this class implements exactly.
 *
 * Deliberately never touches Payment/PaymentAttempt/Order/Wallet state and
 * never calls PaymentAttemptRecoveryService, PaymentService, or
 * PaymentEventProcessor — classification is strictly the "what is true"
 * step, entirely separate from "what, if anything, to do about it" (which,
 * per §14's evidenced automatic-action matrix, is *nothing* automatic for
 * every category in Phase 1).
 *
 * ## Provider status vocabulary — verified, not guessed
 *
 * Interpreting a provider's raw status string requires knowing that
 * provider's own vocabulary. Rather than importing a provider SDK into this
 * domain (forbidden — see tests/Architecture/PaymentsDomainBoundaryTest) or
 * guessing at values, this class reuses the exact, already-verified string
 * literals App\Payments\Stripe\StripeEventTranslator and
 * App\Payments\EasyPay\EasyPayEventTranslator already trust for real
 * financial decisions:
 *
 * - Stripe (`StripeEventTranslator`'s own docblock): a PaymentIntent's
 *   `succeeded` status is terminal-success; `canceled` is the one status
 *   that carries genuine terminal-failure signal ("a failed attempt's
 *   status is `requires_payment_method`, not `canceled`" — that translator's
 *   own words). Every other status
 *   (`requires_payment_method`/`requires_confirmation`/`requires_action`/
 *   `processing`/`requires_capture`) is non-terminal.
 * - EasyPay (`EasyPayEventTranslator`'s own docblock, itself citing
 *   docs.easypay.pt): `success` is terminal-success; `failed` is terminal
 *   and irreversible for that payment id (EasyPay mints a new id per retry,
 *   unlike Stripe). `pending`/`waiting`/`delayed` are non-terminal.
 *   `refunded` and any other value are Unrecognized — EasyPay refunds are
 *   not supported by this codebase at all (docs/financial/RECONCILIATION.md
 *   §16), so a `refunded` status must never be silently treated as a
 *   terminal success/failure.
 *
 * A provider not covered above yields Unrecognized for any status, which
 * classifies to UnsupportedRemoteState — fails closed rather than guessing.
 */
final class ReconciliationClassifier
{
    private const STRIPE_SUCCEEDED = ['succeeded'];

    private const STRIPE_FAILED = ['canceled'];

    /**
     * Stripe's PaymentIntent status is a small, closed set per Stripe's own
     * API reference — listed explicitly, rather than treated as "anything
     * not succeeded/canceled," so a status this codebase has never seen
     * (a future Stripe API addition, or a genuinely malformed response)
     * fails closed to Unrecognized/UnsupportedRemoteState like EasyPay's
     * vocabulary already does below, instead of being silently assumed
     * non-terminal.
     */
    private const STRIPE_NON_TERMINAL = [
        'requires_payment_method',
        'requires_confirmation',
        'requires_action',
        'processing',
        'requires_capture',
    ];

    private const EASYPAY_SUCCEEDED = ['success'];

    private const EASYPAY_FAILED = ['failed'];

    private const EASYPAY_NON_TERMINAL = ['pending', 'waiting', 'delayed'];

    /**
     * @param  ProviderPaymentResult|null  $result  null means the provider cleanly reported
     *                                              "not found" for this exact reference — never
     *                                              used for a timeout/5xx/connection failure,
     *                                              which App\Domain\Payments\Services\ProviderReconciler
     *                                              never passes into classify() at all (see
     *                                              docs/financial/RECONCILIATION.md §13).
     */
    public function classify(ReconciliationCandidate $candidate, ?ProviderPaymentResult $result): ReconciliationClassification
    {
        if ($result === null) {
            return $this->result(
                $candidate,
                ReconciliationCategory::RemoteMissing,
                ReconciliationCategory::RemoteMissing->defaultSeverity(),
                null,
            );
        }

        $meaning = $this->interpretStatus($candidate->provider, $result->providerStatus);

        if ($meaning === null) {
            return $this->result(
                $candidate,
                ReconciliationCategory::UnsupportedRemoteState,
                ReconciliationCategory::UnsupportedRemoteState->defaultSeverity(),
                $result,
            );
        }

        if ($result->amountMinorUnits !== $candidate->expectedAmountMinorUnits) {
            return $this->result($candidate, ReconciliationCategory::AmountMismatch, ReconciliationCategory::AmountMismatch->defaultSeverity(), $result);
        }

        if (strtolower($result->currency) !== $candidate->expectedCurrency) {
            return $this->result($candidate, ReconciliationCategory::CurrencyMismatch, ReconciliationCategory::CurrencyMismatch->defaultSeverity(), $result);
        }

        if ($result->correlationId !== $candidate->expectedCorrelationId) {
            return $this->result($candidate, ReconciliationCategory::CorrelationMismatch, ReconciliationCategory::CorrelationMismatch->defaultSeverity(), $result);
        }

        $localTerminal = $candidate->localAttemptStatus->isTerminal();
        $localSucceeded = $candidate->localAttemptStatus === PaymentAttemptStatus::Succeeded;
        $localFailed = $candidate->localAttemptStatus === PaymentAttemptStatus::Failed;

        return match (true) {
            $meaning === 'succeeded' && $localSucceeded => $this->result($candidate, ReconciliationCategory::Match, ReconciliationCategory::Match->defaultSeverity(), $result),
            // Both sides terminal but disagreeing — the most alarming shape
            // of ambiguity (§8/§14). Explicitly High, not this category's
            // generic Medium default: a completed settlement contradicted by
            // the provider (either direction) is worse than a merely
            // inconsistent, non-terminal observation.
            $meaning === 'succeeded' && $localFailed => $this->result($candidate, ReconciliationCategory::Ambiguous, ReconciliationSeverity::High, $result),
            $meaning === 'succeeded' => $this->result(
                $candidate,
                $candidate->hasPendingProviderEvent
                    ? ReconciliationCategory::RemoteSucceededAwaitingReplay
                    : ReconciliationCategory::RemoteSucceededNoSettlementPath,
                $candidate->hasPendingProviderEvent
                    ? ReconciliationCategory::RemoteSucceededAwaitingReplay->defaultSeverity()
                    : ReconciliationCategory::RemoteSucceededNoSettlementPath->defaultSeverity(),
                $result,
            ),
            $meaning === 'failed' && $localFailed => $this->result($candidate, ReconciliationCategory::Match, ReconciliationCategory::Match->defaultSeverity(), $result),
            $meaning === 'failed' && $localSucceeded => $this->result($candidate, ReconciliationCategory::Ambiguous, ReconciliationSeverity::High, $result),
            $meaning === 'failed' => $this->result($candidate, ReconciliationCategory::RemoteFailedLocalPending, ReconciliationCategory::RemoteFailedLocalPending->defaultSeverity(), $result),
            // $meaning === 'non_terminal'
            $localTerminal => $this->result($candidate, ReconciliationCategory::RemotePendingLocalTerminal, ReconciliationCategory::RemotePendingLocalTerminal->defaultSeverity(), $result),
            default => $this->result($candidate, ReconciliationCategory::Match, ReconciliationCategory::Match->defaultSeverity(), $result),
        };
    }

    /** @return 'succeeded'|'failed'|'non_terminal'|null null means Unrecognized — fails closed. */
    private function interpretStatus(string $provider, string $status): ?string
    {
        return match ($provider) {
            'stripe' => match (true) {
                in_array($status, self::STRIPE_SUCCEEDED, true) => 'succeeded',
                in_array($status, self::STRIPE_FAILED, true) => 'failed',
                in_array($status, self::STRIPE_NON_TERMINAL, true) => 'non_terminal',
                default => null,
            },
            'easypay' => match (true) {
                in_array($status, self::EASYPAY_SUCCEEDED, true) => 'succeeded',
                in_array($status, self::EASYPAY_FAILED, true) => 'failed',
                in_array($status, self::EASYPAY_NON_TERMINAL, true) => 'non_terminal',
                default => null,
            },
            default => null,
        };
    }

    private function result(
        ReconciliationCandidate $candidate,
        ReconciliationCategory $category,
        ReconciliationSeverity $severity,
        ?ProviderPaymentResult $result,
    ): ReconciliationClassification {
        return new ReconciliationClassification(
            category: $category,
            severity: $severity,
            localState: $candidate->localAttemptStatus->value,
            remoteState: $result?->providerStatus,
            localAmountMinorUnits: $candidate->expectedAmountMinorUnits,
            remoteAmountMinorUnits: $result?->amountMinorUnits,
            localCurrency: $candidate->expectedCurrency,
            remoteCurrency: $result !== null ? strtolower($result->currency) : null,
            localCorrelationId: $candidate->expectedCorrelationId,
            remoteCorrelationId: $result?->correlationId,
        );
    }
}
