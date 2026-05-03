<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use App\Events\KycSubmitted;
use App\Models\Admin;
use App\Notifications\KycSubmittedNotification;

class NotifyAdminKycSubmitted implements ShouldQueue
{
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
