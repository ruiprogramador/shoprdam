<?php

namespace App\Domain\Payments\Enums;

/**
 * The finite, provider-neutral mismatch taxonomy from
 * docs/financial/RECONCILIATION.md §8. Every case here is not automatically
 * actionable — see that document's §14 automatic-action matrix, proven by
 * execution trace against App\Domain\Payments\Services\PaymentService/
 * PaymentAttemptRecoveryService/PaymentEventProcessor (§3), not assumed.
 * App\Domain\Payments\Services\ReconciliationClassifier is the only class
 * that ever produces a value of this enum.
 *
 * `Match` is never persisted as a new finding — it only ever resolves an
 * already-open episode (see App\Domain\Payments\Services\ReconciliationFindingRepository).
 */
enum ReconciliationCategory: string
{
    case Match = 'match';

    /**
     * Provider reports succeeded; local attempt is non-terminal (`Claimed`);
     * a `pending` App\Domain\Payments\Models\PaymentProviderEvent row already
     * exists for this exact (provider, provider_reference). Proven, not
     * assumed, to self-resolve via the existing
     * App\Console\Commands\ReconcileOrphanedPaymentAttempts candidate set 2 —
     * see docs/financial/RECONCILIATION.md §3.D.
     */
    case RemoteSucceededAwaitingReplay = 'remote_succeeded_awaiting_replay';

    /**
     * Provider reports succeeded; local attempt is non-terminal (`Claimed`);
     * no stored/pending provider event exists at all for this reference.
     * docs/financial/RECONCILIATION.md §3.A/§3.B trace this exactly and prove
     * neither PaymentAttemptRecoveryService::recover() nor
     * PaymentService::finalizeAttempt() settle this state without a real
     * webhook eventually arriving — the single most consequential gap this
     * design surfaces. Never automatically actionable.
     */
    case RemoteSucceededNoSettlementPath = 'remote_succeeded_no_settlement_path';

    /** Provider reports failed/canceled; local attempt is still non-terminal. */
    case RemoteFailedLocalPending = 'remote_failed_local_pending';

    /** Local attempt is terminal (`Succeeded`/`Failed`); provider now reports a non-terminal state. */
    case RemotePendingLocalTerminal = 'remote_pending_local_terminal';

    /** Local attempt has a `provider_reference`; the provider cleanly reports it doesn't exist. */
    case RemoteMissing = 'remote_missing';

    /** The provider's amount differs from the local Order's. */
    case AmountMismatch = 'amount_mismatch';

    /** The provider's currency differs from the local Order's. */
    case CurrencyMismatch = 'currency_mismatch';

    /** The provider's echoed correlation id doesn't match the local Order. */
    case CorrelationMismatch = 'correlation_mismatch';

    /**
     * A refund-shaped, partial-refund-shaped, or otherwise unrecognized
     * remote status this codebase has no handling for — see
     * docs/financial/RECONCILIATION.md §16. Never invents handling for an
     * unsupported economic operation.
     */
    case UnsupportedRemoteState = 'unsupported_remote_state';

    /**
     * Both sides are terminal but disagree (e.g. local `Succeeded`, remote
     * reports failed — or vice versa) — evidence inconsistent in a way no
     * more specific category covers. The most alarming shape of ambiguity;
     * see App\Domain\Payments\Services\ReconciliationClassifier for why this
     * is deliberately classified at High severity, not this enum's own
     * generic default.
     */
    case Ambiguous = 'ambiguous';

    /**
     * The taxonomy's own reference severity, per
     * docs/financial/RECONCILIATION.md §8 — App\Domain\Payments\Services\ReconciliationClassifier
     * may still assign a different, explicit severity for a specific
     * scenario (e.g. Ambiguous from a double-terminal disagreement), never
     * relying on this default implicitly.
     */
    public function defaultSeverity(): ReconciliationSeverity
    {
        return match ($this) {
            self::Match => ReconciliationSeverity::Low,
            self::RemoteSucceededAwaitingReplay => ReconciliationSeverity::Low,
            self::UnsupportedRemoteState, self::Ambiguous => ReconciliationSeverity::Medium,
            self::RemoteSucceededNoSettlementPath,
            self::RemoteFailedLocalPending,
            self::RemotePendingLocalTerminal,
            self::RemoteMissing,
            self::AmountMismatch,
            self::CurrencyMismatch,
            self::CorrelationMismatch => ReconciliationSeverity::High,
        };
    }
}
