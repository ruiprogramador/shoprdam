<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Events\KycSubmitted;
use App\Listeners\NotifyAdminKycSubmitted;

class KycSubmittedServiceProvider extends ServiceProvider
{
    protected $listen = [
        KycSubmitted::class => [
            NotifyAdminKycSubmitted::class,
        ],
    ];
}