<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\DTOs\ProviderPaymentResult;

/**
 * Optional capability: fetch the canonical remote payment by its provider
 * reference, independent of any local attempt. Only needed for the
 * losing-side-of-a-race fallback in PaymentService::startOrResumeAttempt()
 * — when this attempt's own provider call didn't win the
 * `payment_attempts` provider/reference uniqueness race, the *other* one
 * (the actual canonical remote payment) must be re-fetched and validated
 * before anything is handed back, never assumed correct just because the
 * database says it's the one that won.
 *
 * Not part of the base PaymentProviderContract — a provider without a
 * "retrieve by reference" API simply doesn't implement this, and
 * PaymentService falls back to trusting only its own already-validated
 * result (which is safe: that race is inherently rare, and providers with
 * a deterministic idempotency key rarely lose it at all).
 */
interface SupportsCanonicalRetrieval
{
    public function retrieveByReference(string $providerReference): ProviderPaymentResult;
}
