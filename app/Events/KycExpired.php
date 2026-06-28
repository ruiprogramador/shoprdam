<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Kyc;

class KycExpired
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Kyc $kyc)
    {
    }
}