<?php

namespace App\Domain\Payouts;

use App\Domain\Payouts\Contracts\PayoutProviderContract;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves a PayoutProviderContract implementation by driver name (e.g.
 * 'manual'). Mirrors App\Domain\Payments\PaymentProviderManager exactly —
 * knows nothing about any concrete provider itself; App\Providers\PayoutServiceProvider
 * registers each driver via extend(), reading the mapping from
 * config('payouts.providers').
 */
class PayoutProviderManager extends Manager
{
    /**
     * No default: every caller resolves a provider explicitly by the name
     * stored on the PayoutAttempt row, never implicitly.
     */
    public function getDefaultDriver(): string
    {
        throw new InvalidArgumentException(
            'No default payout provider — resolve one explicitly via driver($name), e.g. from a PayoutAttempt\'s own provider column.'
        );
    }

    public function driver($driver = null): PayoutProviderContract
    {
        return parent::driver($driver);
    }
}
