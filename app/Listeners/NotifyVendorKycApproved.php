<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Notifications\KycApprovedNotification;

class NotifyVendorKycApproved implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    /* public function __construct()
    {
        //
    } */

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        $event->kyc->user->notify(new KycApprovedNotification($event->kyc));
    }
}