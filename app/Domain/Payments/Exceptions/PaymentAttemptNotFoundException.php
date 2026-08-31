<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/**
 * Thrown when PaymentEventProcessor::markSettled() needs to transition a
 * PaymentAttempt but no attempt for the settling Payment claims the exact
 * `(provider, provider_reference)` pair the Wallet transaction was recorded
 * under. This should be unreachable in normal operation — every
 * StoreWalletTransaction settled here was itself created from a
 * PaymentAttempt's own claimed provider_reference (see
 * PaymentService::claimProviderReference()) — so hitting it means the local
 * data is inconsistent with the financial history it's supposed to
 * describe. Failing closed here, inside the same DB::transaction() wrapping
 * the Wallet mutation (see applySucceeded()/applyFailed()), rolls that
 * mutation back too rather than settling the Wallet while leaving no
 * PaymentAttempt record of it.
 */
class PaymentAttemptNotFoundException extends RuntimeException {}
