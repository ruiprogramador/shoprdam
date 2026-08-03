<?php

namespace App\Enums;

enum PaymentIntentAttemptStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case NeedsAttention = 'needs_attention';
}
