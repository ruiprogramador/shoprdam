<?php

namespace App\Listeners;

use App\Events\KycExpired;
use App\Notifications\KycExpiredNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NotifyVendorKycExpired
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(KycExpired $event): void
    {
        $event->kyc->user->notify(new KycExpiredNotification($event->kyc));
    }
}
