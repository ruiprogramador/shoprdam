<?php

namespace App\Domain\Payments\Contracts;

use Throwable;

/**
 * Optional capability: precisely distinguish "this provider positively
 * confirms the exact resource does not exist" from every other retrieval
 * failure — never inferred from `PaymentProviderContract::classifyFailure()`'s
 * `FailureClass::NonRetryable`, which is a much broader bucket (also
 * covers authentication failure, permission failure, a malformed request,
 * a card error, an idempotency conflict — none of which prove the resource
 * is absent) built for a different question ("is retrying this worth it")
 * than the one reconciliation evidence needs ("does this specific reference
 * exist or not").
 *
 * Not part of the base PaymentProviderContract, mirroring
 * SupportsCanonicalRetrieval's own optionality exactly: a provider whose
 * failure semantics don't reliably distinguish confirmed absence from other
 * definitive rejections (verified against its own adapter/exception code,
 * not assumed from a generic REST convention) simply doesn't implement
 * this — see App\Payments\EasyPay\EasyPayPaymentProvider's own docblock for
 * why it deliberately does not, and
 * docs/financial/RECONCILIATION.md §8/§13 for the evidence-correctness
 * distinction this exists to enforce:
 * App\Domain\Payments\Services\ProviderReconciler only ever classifies a
 * retrieval failure as evidence of `RemoteMissing` when the resolved
 * provider both implements this interface AND returns `true` from
 * `isConfirmedAbsent()` for that exact exception — every other retrieval
 * failure (retryable or not) is a `RetrievalFailed` operational outcome,
 * never a financial finding.
 */
interface SupportsConfirmedResourceAbsence
{
    /**
     * @param  Throwable  $e  the exception retrieveByReference() just threw for this exact reference
     */
    public function isConfirmedAbsent(Throwable $e): bool;
}
