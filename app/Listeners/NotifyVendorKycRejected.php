<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Notifications\KycRejectedNotification;

class NotifyVendorKycRejected implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        $event->kyc->user->notify(new KycRejectedNotification($event->kyc));
    }
}
