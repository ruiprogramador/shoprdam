<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\Admin;
use App\Notifications\KycUpdatedNotification;

class NotifyAdminKycUpdated implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        Admin::each(function (Admin $admin) use ($event) {
            $admin->notify(new KycUpdatedNotification($event->kyc));
        });
    }
}
