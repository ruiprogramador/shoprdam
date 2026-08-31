<?php

namespace App\Providers;

use App\Domain\Payments\PaymentProviderManager;
use App\Domain\Payments\ProviderEventTranslatorManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers every payment provider driver from config('payments.providers')
 * against both PaymentProviderManager and ProviderEventTranslatorManager.
 * This is the *only* place a provider's concrete class ever gets wired in —
 * neither manager knows any provider by name itself, and
 * App\Domain\Payments\Services\PaymentService/PaymentEventProcessor only
 * ever resolve a provider through these managers, never `new` one
 * directly. Adding a provider is a config/payments.php entry plus its two
 * classes; nothing here changes.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentProviderManager::class, function (Application $app) {
            $manager = new PaymentProviderManager($app);

            foreach (config('payments.providers', []) as $name => $config) {
                $manager->extend($name, fn ($app) => $app->make($config['provider']));
            }

            return $manager;
        });

        $this->app->singleton(ProviderEventTranslatorManager::class, function (Application $app) {
            $manager = new ProviderEventTranslatorManager($app);

            foreach (config('payments.providers', []) as $name => $config) {
                $manager->extend($name, fn ($app) => $app->make($config['translator']));
            }

            return $manager;
        });
    }
}
