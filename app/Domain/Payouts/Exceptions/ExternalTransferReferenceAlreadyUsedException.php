<?php

namespace App\Domain\Payouts\Exceptions;

use RuntimeException;

/**
 * Thrown when a manual confirmation submits an external_transfer_reference
 * that already identifies a different PayoutAttempt for the same provider —
 * see the unique(provider, external_transfer_reference) constraint on
 * payout_attempts. Fails closed: a real bank reference must never be
 * accepted as evidence for two different payouts.
 */
class ExternalTransferReferenceAlreadyUsedException extends RuntimeException {}
