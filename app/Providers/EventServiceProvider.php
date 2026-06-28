<?php

namespace App\Providers;

use App\Events\KycApproved;
use App\Events\KycExpired;
use App\Events\KycExpiringSoon;
use App\Events\KycRejected;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Events\KycSubmitted;
use App\Events\KycUpdated;
use App\Listeners\NotifyAdminKycSubmitted;
use App\Listeners\NotifyAdminKycUpdated;
use App\Listeners\NotifyVendorKycApproved;
use App\Listeners\NotifyVendorKycExpired;
use App\Listeners\NotifyVendorKycExpiringSoon;
use App\Listeners\NotifyVendorKycRejected;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        KycSubmitted::class => [
            NotifyAdminKycSubmitted::class,
        ],
        KycApproved::class => [
            NotifyVendorKycApproved::class,
        ],
        KycRejected::class => [
            NotifyVendorKycRejected::class,
        ],
        KycUpdated::class => [
            NotifyAdminKycUpdated::class,
        ],
        KycExpired::class => [
            NotifyVendorKycExpired::class,
        ],
        KycExpiringSoon::class => [
            NotifyVendorKycExpiringSoon::class,
        ],
    ];
}