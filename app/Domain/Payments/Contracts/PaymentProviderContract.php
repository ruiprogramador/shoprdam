<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\Enums\FailureClass;
use App\Domain\Payments\Models\PaymentAttempt;
use Throwable;

/**
 * The minimum every payment provider adapter must implement. Deliberately
 * small: capture/authorize/expire/etc. are NOT here — add a
 * capability-specific interface (e.g. SupportsCanonicalRetrieval) only once
 * a real second provider actually needs it, instead of forcing every
 * adapter to pretend it supports operations it doesn't have.
 */
interface PaymentProviderContract
{
    /** The driver name this adapter is resolved under, e.g. 'stripe'. */
    public function name(): string;

    /**
     * Idempotently create (or, on a retry under the same attempt, return
     * the existing) remote payment for this attempt. Must be safe to call
     * more than once for the same PaymentAttempt — this is what
     * ReconcileOrphanedPaymentAttempts relies on to recover a crash between
     * the provider responding and the local write completing.
     */
    public function createOrGetPayment(PaymentAttempt $attempt): ProviderPaymentResult;

    /**
     * Classify a failure from createOrGetPayment() (or any other call this
     * adapter makes) as worth retrying or not. A definitive rejection (bad
     * credentials, a malformed request, a declined card) is NonRetryable —
     * retrying will fail identically every time. Network failures, rate
     * limits, and the provider's own 5xx responses are Retryable.
     */
    public function classifyFailure(Throwable $e): FailureClass;
}
