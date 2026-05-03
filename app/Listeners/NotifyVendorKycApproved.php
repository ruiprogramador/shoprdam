<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Notifications\KycApprovedNotification;

class NotifyVendorKycApproved implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        $event->kyc->user->notify(new KycApprovedNotification($event->kyc));
    }
}