<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Events\KycSubmitted;
use App\Models\Admin;
use App\Notifications\KycSubmittedNotification;

class NotifyAdminKycSubmitted implements ShouldQueue
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
    public function handle(KycSubmitted $event): void
    {
        Admin::each(function (Admin $admin) use ($event) {
            $admin->notify(new KycSubmittedNotification($event->kyc));
        });
    }
}
