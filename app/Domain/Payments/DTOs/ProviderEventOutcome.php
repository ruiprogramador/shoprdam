<?php

namespace App\Domain\Payments\DTOs;

use App\Domain\Payments\Enums\ProviderEventType;

/**
 * A provider adapter's translation of one native webhook event into the
 * vocabulary PaymentEventProcessor understands. Built once by the
 * provider's ProviderEventTranslator and consumed identically whether it
 * came from a live webhook delivery or a replay from `payment_provider_events`
 * — see App\Domain\Payments\Services\PaymentEventProcessor.
 *
 * `providerReference` identifies the original payment (used to look up the
 * local PaymentAttempt/Wallet transaction); `reversalReference` — only set
 * for `Refunded` — identifies the refund itself (e.g. a Stripe Charge id),
 * a different reference than the original sale, matching
 * WalletTransactionService::reverse()'s own reference semantics.
 *
 * `replayPayload` is the minimal, allow-listed, secret-free reconstruction
 * the translator decided is sufficient to replay this exact event later —
 * opaque to the processor, only ever round-tripped through the provider's
 * own translator again on replay.
 */
final readonly class ProviderEventOutcome
{
    public function __construct(
        public string $provider,
        public string $eventId,
        public string $eventType,
        public ProviderEventType $type,
        public ?string $providerReference,
        public ?string $reversalReference = null,
        public ?int $refundedAmountMinorUnits = null,
        public ?string $failureReason = null,
        public array $replayPayload = [],
    ) {}
}
