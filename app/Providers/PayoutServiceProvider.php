<?php

namespace App\Providers;

use App\Domain\Payouts\PayoutProviderManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers every payout provider driver from config('payouts.providers')
 * against PayoutProviderManager — mirrors App\Providers\PaymentServiceProvider.
 * The only place a payout provider's concrete class ever gets wired in;
 * App\Domain\Payouts\Services\PayoutService only ever resolves a provider
 * through this manager, never `new`s one directly.
 */
class PayoutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PayoutProviderManager::class, function (Application $app) {
            $manager = new PayoutProviderManager($app);

            foreach (config('payouts.providers', []) as $name => $config) {
                $manager->extend($name, fn ($app) => $app->make($config['provider']));
            }

            return $manager;
        });
    }
}
