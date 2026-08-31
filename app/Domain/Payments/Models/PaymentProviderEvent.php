<?php

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\ProviderEventStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A provider webhook event that PaymentEventProcessor couldn't resolve
 * locally yet when it arrived — no PaymentAttempt claimed this provider
 * reference yet, or (a refund) one did but its Wallet transaction wasn't
 * `completed` yet. See PaymentEventProcessor::storeUnmatchedEvent() and
 * ::replayUnmatchedEvents(), and docs/wallet/integrations.md ("Recovering
 * orphaned payment attempts").
 *
 * `payload` is whatever minimal, allow-listed reconstruction the owning
 * provider's ProviderEventTranslator decided is sufficient to replay this
 * event — never the raw provider payload, so nothing sensitive ever ends
 * up here.
 */
class PaymentProviderEvent extends Model
{
    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'provider_reference',
        'payload',
        'status',
        'replay_attempts',
        'last_replay_error',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => ProviderEventStatus::class,
        'processed_at' => 'datetime',
    ];
}
