<?php

namespace App\Domain\Payouts\Enums;

/**
 * The provider-neutral shape every PayoutProviderContract implementation's
 * outcome is normalized into before it ever reaches
 * App\Domain\Payouts\Services\PayoutEventProcessor::apply() — mirrors
 * App\Domain\Payments\Enums\ProviderEventType. ManualPayoutProvider's admin
 * confirmation only ever produces Succeeded or Failed (a human picks one of
 * two outcomes from a closed form); Informational/Unrecognized exist for
 * parity with a future webhook-driven provider, which may receive event
 * types this domain has no opinion on.
 */
enum PayoutOutcomeType
{
    case Succeeded;
    case Failed;
    case Informational;
    case Unrecognized;
}
