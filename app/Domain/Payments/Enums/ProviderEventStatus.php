<?php

namespace App\Domain\Payments\Enums;

enum ProviderEventStatus: string
{
    /**
     * Stored because, at the time it arrived, PaymentEventProcessor could
     * not yet resolve it locally — either no attempt claimed this provider
     * reference yet, or (a refund) one exists but its Wallet transaction
     * isn't `completed` yet. Eligible for replay any time
     * PaymentEventProcessor::replayUnmatchedEvents() runs for that
     * (provider, reference) pair.
     */
    case Pending = 'pending';

    /**
     * Replayed with an outcome that will never change on a later replay
     * (settled, or permanently a no-op). Terminal: never picked up by
     * replayUnmatchedEvents() again.
     */
    case Applied = 'applied';
}
