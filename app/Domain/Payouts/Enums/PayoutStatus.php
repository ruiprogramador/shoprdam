<?php

namespace App\Domain\Payouts\Enums;

/**
 * A Payout's own aggregate lifecycle — deliberately mirrors
 * App\Domain\Payments\Enums\PaymentStatus's relationship to
 * PaymentAttemptStatus: an individual PayoutAttempt failing is never, by
 * itself, a fact about the Payout (see PayoutAttemptStatus's own docblock).
 *
 * Reserved/Processing are the same "still alive" state from the ledger's
 * point of view — the reservation debit is already posted either way. The
 * only difference is whether a non-terminal PayoutAttempt currently exists
 * (Processing) or not (Reserved, either because none was ever created yet,
 * or because the last one resolved to Failed and a new one hasn't started).
 * Both statuses are written in the exact same conditional UPDATE that also
 * moves `current_payout_attempt_id`, in
 * App\Domain\Payouts\Services\PayoutService — never a bare
 * `$payout->update(['status' => ...])` — so the stored status can never
 * drift from what the pointer actually says.
 *
 * Succeeded/Cancelled/Failed are the only three terminal states. Only
 * Cancelled and Failed ever release the reservation (via a
 * `withdrawal_reversal`, exactly once) — see
 * PayoutService::abandon(). Succeeded never reverses: the reservation debit
 * IS the final financial effect, permanently.
 */
enum PayoutStatus: string
{
    case Reserved = 'reserved';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Cancelled, self::Failed => true,
            self::Reserved, self::Processing => false,
        };
    }

    /** Whether the reservation debit for this Payout has already been released (or never will be). */
    public function blocksNewAttempt(): bool
    {
        return $this->isTerminal();
    }
}
