<?php

namespace App\Domain\Payouts\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Domain\Payouts\Services\PayoutEventProcessor when an
 * outcome names a (provider, provider_reference) pair that no
 * PayoutAttempt has ever claimed. Mirrors
 * App\Domain\Payments\Exceptions\PaymentAttemptNotFoundException. Fails
 * closed rather than silently ignoring the outcome — this domain has no
 * unmatched-event inbox (see PayoutEventProcessor's own docblock for why),
 * so an outcome that can't be resolved to a known attempt is always a bug
 * or a forged request, never routine "arrived early" ordering the caller
 * should just retry later.
 */
class PayoutAttemptNotFoundException extends RuntimeException {}
