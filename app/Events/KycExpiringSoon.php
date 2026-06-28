<?php

namespace App\Events;

use App\Models\Kyc;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KycExpiringSoon
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Kyc $kyc)
    {
    }
}
