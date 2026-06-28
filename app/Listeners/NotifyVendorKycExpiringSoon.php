<?php

namespace App\Listeners;

use App\Events\KycExpiringSoon;
use App\Notifications\KycExpiringSoonNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NotifyVendorKycExpiringSoon implements ShouldQueue
{
    public function handle(KycExpiringSoon $event): void
    {
        $event->kyc->loadMissing('user');
        $event->kyc->user->notify(new KycExpiringSoonNotification($event->kyc));
    }
}
