<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\Kyc;
use App\Policies\KycPolicy;
use App\Policies\AdminKycPolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::policy(Kyc::class, KycPolicy::class);

        // Admin Kyc Policy - use admin guard for authorization
        Gate::define('admin-kyc', function ($user, string $ability, Kyc $kyc) {
            // return Gate::guard('admin')->check() && Gate::guard('admin')->allows($ability, $kyc);
            return auth('admin')->user() && app(AdminKycPolicy::class)->{$ability}(auth('admin')->user(), $kyc);
        });
    }
}
