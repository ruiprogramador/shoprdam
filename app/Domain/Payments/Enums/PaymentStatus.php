<?php

namespace App\Domain\Payments\Enums;

/**
 * The Payment's own lifecycle — the single financial obligation an Order
 * has (see App\Domain\Payments\Models\Payment). Deliberately separate from
 * PaymentAttemptStatus: an individual attempt can fail and still leave the
 * Payment `pending`, ready for another attempt with a different
 * provider/method — see
 * App\Domain\Payments\Services\PaymentService::startOrResumeAttempt().
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending => false,
            self::Paid, self::Failed, self::Refunded => true,
        };
    }
}
