<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\DTOs\ProviderEventOutcome;

/**
 * Translates a provider's own native webhook event object into the generic
 * vocabulary PaymentEventProcessor understands — the "translation boundary"
 * a provider's webhook controller sits behind. This is the only place in a
 * provider adapter allowed to know that provider's event shape/vocabulary
 * (e.g. `Stripe\Event`, `payment_intent.succeeded`); everything past
 * translate() is generic.
 *
 * The native event type is intentionally not part of this interface's
 * signature (PHP can't express "some provider-specific type" generically) —
 * each implementation accepts its own concrete SDK type and is only ever
 * called by that same provider's own webhook controller and replay path.
 */
interface ProviderEventTranslator
{
    /**
     * @param  mixed  $nativeEvent  this provider's own SDK event object
     */
    public function translate(mixed $nativeEvent): ProviderEventOutcome;

    /**
     * Rebuilds this provider's native event object from a
     * `payment_provider_events.payload` row previously produced by
     * translate()'s `ProviderEventOutcome::$replayPayload` — must not
     * trust the stored payload blindly: replaying it goes through the
     * exact same translate() + PaymentEventProcessor::apply() path a live
     * delivery would, including whatever validation that involves.
     */
    public function reconstructFromReplayPayload(array $payload): mixed;
}
