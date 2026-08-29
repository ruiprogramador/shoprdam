<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\DTOs\ProviderEventOutcome;
use App\Domain\Payments\Enums\EventApplicationOutcome;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Enums\ProviderEventType;
use App\Domain\Payments\MinorUnits;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\ProviderEventTranslatorManager;
use App\Domain\Wallet\Exceptions\TransactionAlreadyReversedException;
use App\Domain\Wallet\Exceptions\TransactionNotPendingException;
use App\Domain\Wallet\WalletTransactionReference;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies a provider-neutral ProviderEventOutcome to the Wallet/Payment/Order
 * — the provider-agnostic replacement for the old
 * App\Payments\Stripe\StripeEventDispatcher. This is the only place allowed
 * to know both the payments domain's vocabulary (ProviderEventOutcome,
 * PaymentAttemptStatus) and the Wallet's vocabulary
 * (record/confirm/markFailed/reverse); a provider's own webhook controller
 * translates its native event into a ProviderEventOutcome and hands it to
 * apply() — it never mutates the Wallet or a Payment itself.
 *
 * Provider webhooks are at-least-once and give no ordering guarantee, so a
 * terminal event can arrive before the local claim it needs even exists —
 * or, for a refund, after the claim exists but before the sale is
 * `completed`. When apply() can't resolve an event yet for either reason,
 * it persists a minimal, secret-free reconstruction of it to
 * `payment_provider_events` (storeUnmatchedEvent()) instead of dropping it.
 * Once a matching local claim exists (or the sale becomes `completed`),
 * replayUnmatchedEvents() re-runs the *exact same* apply() logic against
 * the stored event. See docs/wallet/integrations.md for the full
 * crash-window walkthrough.
 */
class PaymentEventProcessor
{
    public function __construct(
        private readonly WalletTransactionService $walletTransactionService,
        private readonly ProviderEventTranslatorManager $translators,
    ) {}

    public function apply(ProviderEventOutcome $outcome): EventApplicationOutcome
    {
        return match ($outcome->type) {
            ProviderEventType::Succeeded => $this->applySucceeded($outcome),
            ProviderEventType::Failed => $this->applyFailed($outcome),
            ProviderEventType::Refunded => $this->applyRefunded($outcome),
            ProviderEventType::Informational => tap(EventApplicationOutcome::Applied, function () use ($outcome) {
                Log::info("Informational provider event, no state change: {$outcome->provider}/{$outcome->eventType}");
            }),
            ProviderEventType::Unrecognized => tap(EventApplicationOutcome::Ignored, function () use ($outcome) {
                Log::info("Unhandled {$outcome->provider} event type: {$outcome->eventType}");
            }),
        };
    }

    /**
     * Re-applies every not-yet-resolved event stored for a (provider,
     * reference) pair, in the order they were originally received. Call
     * this any time a local claim for that reference might newly exist
     * (PaymentService::finalizeAttempt(), covering both a fresh claim and
     * a reconciliation-recovered one) or a transaction tied to it might
     * have just become `completed` (applySucceeded() below, covering a
     * refund that arrived before confirmation).
     */
    public function replayUnmatchedEvents(string $provider, string $reference): void
    {
        $this->replay($provider, $reference, null);
    }

    /**
     * A row is only marked `applied` *after* apply() returns for it (see
     * replay() below) — while a Succeeded-type row is mid-apply, it is
     * therefore still `pending` in the database. applySucceeded() below
     * needs to trigger a nested replay (to resolve a refund queued ahead
     * of confirmation), but if that nested call considered *every* pending
     * event, it would re-select the very Succeeded row already being
     * processed higher up the call stack — applying it again, which
     * (since the transaction is now already completed) would just hit the
     * TransactionNotPendingException no-op path and immediately trigger
     * *another* nested replay, forever. Excluding Succeeded-type events
     * from this call site is what makes it safe: nothing a Refunded-type
     * apply() does ever triggers a nested replay itself, so it can't
     * re-enter this way.
     */
    private function replay(string $provider, string $reference, ?ProviderEventType $excludeType): void
    {
        PaymentProviderEvent::query()
            ->where('provider', $provider)
            ->where('provider_reference', $reference)
            ->where('status', ProviderEventStatus::Pending)
            ->orderBy('id')
            ->get()
            ->each(function (PaymentProviderEvent $stored) use ($provider, $excludeType) {
                $translator = $this->translators->driver($provider);
                $nativeEvent = $translator->reconstructFromReplayPayload($stored->payload);
                $outcome = $translator->translate($nativeEvent);

                if ($excludeType !== null && $outcome->type === $excludeType) {
                    // Still pending on purpose — see this method's docblock.
                    return;
                }

                try {
                    $applied = $this->apply($outcome);

                    if ($applied === EventApplicationOutcome::Applied) {
                        $stored->update([
                            'status' => ProviderEventStatus::Applied,
                            'processed_at' => now(),
                        ]);
                    }

                    // Unresolved: left pending on purpose (e.g. a refund
                    // still waiting on the sale to confirm) — a later
                    // trigger will pick it up again.
                } catch (Throwable $e) {
                    $stored->increment('replay_attempts');
                    $stored->update(['last_replay_error' => $e->getMessage()]);

                    throw $e;
                }
            });
    }

    private function applySucceeded(ProviderEventOutcome $outcome): EventApplicationOutcome
    {
        $transaction = $this->findTransaction($outcome->provider, $outcome->providerReference);

        if ($transaction === null) {
            $this->storeUnmatchedEvent($outcome);

            return EventApplicationOutcome::Unresolved;
        }

        try {
            // Wrapped so a failure in markSettled() (after confirm()
            // already succeeded) rolls back the Wallet mutation too,
            // instead of leaving the transaction completed with the Order
            // stuck pending — a state a replayed webhook could never
            // repair, since confirm() would then just hit its
            // "already settled" no-op. WalletTransactionService::confirm()
            // opens its own transaction internally; nesting here uses a
            // savepoint.
            DB::transaction(function () use ($transaction) {
                $this->walletTransactionService->confirm($transaction);
                $this->markSettled($transaction, PaymentStatus::Paid, PaymentAttemptStatus::Succeeded, 'paid');
            });
        } catch (TransactionNotPendingException) {
            // Duplicate webhook delivery for an already-settled transaction: no-op.
        }

        // A refund may have arrived (and been queued) before this event
        // confirmed the transaction it needs — see the class docblock.
        // Excludes Succeeded-type events; see replay()'s docblock for why.
        $this->replay($outcome->provider, $outcome->providerReference, ProviderEventType::Succeeded);

        return EventApplicationOutcome::Applied;
    }

    private function applyFailed(ProviderEventOutcome $outcome): EventApplicationOutcome
    {
        $transaction = $this->findTransaction($outcome->provider, $outcome->providerReference);

        if ($transaction === null) {
            $this->storeUnmatchedEvent($outcome);

            return EventApplicationOutcome::Unresolved;
        }

        try {
            // See applySucceeded() for why this is wrapped.
            DB::transaction(function () use ($transaction, $outcome) {
                $this->walletTransactionService->markFailed($transaction, $outcome->failureReason);
                // Payment.status is left untouched (null) — a terminally
                // failed *attempt* doesn't mean the *Payment* is done: per
                // PaymentAttemptStatus::Failed::blocksNewAttempt() (false),
                // the customer may still retry with another provider/method
                // and this same Payment ends up `paid`. Only applySucceeded()
                // and applyRefunded() reach a Payment-level terminal state.
                $this->markSettled($transaction, null, PaymentAttemptStatus::Failed, 'failed');
            });
        } catch (TransactionNotPendingException) {
            // Duplicate webhook delivery for an already-settled transaction: no-op.
        }

        return EventApplicationOutcome::Applied;
    }

    private function applyRefunded(ProviderEventOutcome $outcome): EventApplicationOutcome
    {
        $original = $this->findTransaction($outcome->provider, $outcome->providerReference);

        if ($original === null || ! $original->isCompleted()) {
            // Either genuinely orphaned (no local claim yet) or arrived
            // before the succeeded event confirmed the sale — a refund can
            // never be reversed against a transaction that isn't
            // `completed` yet. Queue it; replayUnmatchedEvents() is re-run
            // from applySucceeded() once that transaction does confirm.
            $this->storeUnmatchedEvent($outcome);

            return EventApplicationOutcome::Unresolved;
        }

        $expected = MinorUnits::fromDecimal($original->amount);

        if ($outcome->refundedAmountMinorUnits !== $expected) {
            Log::warning(
                "Ignoring a refund for {$outcome->provider}/{$outcome->providerReference}: ".
                "refunded {$outcome->refundedAmountMinorUnits}/{$expected} so far. ".
                'Reversal only happens once the refund is full; partial reversals are not supported.'
            );

            // This event's refunded amount is a fixed historical value
            // that will never change on a later replay — permanently ignorable.
            return EventApplicationOutcome::Applied;
        }

        try {
            // See applySucceeded() for why this is wrapped.
            DB::transaction(function () use ($original, $outcome) {
                $this->walletTransactionService->reverse(
                    original: $original,
                    reversalCategorySlug: 'customer_refund',
                    reference: new WalletTransactionReference($outcome->provider, $outcome->reversalReference ?? $outcome->eventId),
                );
                $this->markSettled($original, PaymentStatus::Refunded, null, 'refunded');
            });
        } catch (TransactionAlreadyReversedException) {
            // Duplicate webhook delivery for an already-reversed transaction: no-op.
        }

        return EventApplicationOutcome::Applied;
    }

    /**
     * The original sale transaction is created with the Order set as its
     * `referenceable` (see PaymentService). Reusing that link means this
     * processor never has to trust or parse metadata coming back from a
     * provider.
     */
    private function findTransaction(string $provider, string $reference): ?StoreWalletTransaction
    {
        return StoreWalletTransaction::query()
            ->where('external_provider', $provider)
            ->where('external_reference', $reference)
            ->whereNull('related_transaction_id')
            ->first();
    }

    /**
     * Keeps Order.status (kept for every existing consumer of
     * Order::isPaid()/isFailed()/isRefunded()), Payment.status, and — for
     * a settlement outcome, not a refund — the current PaymentAttempt's
     * status all in sync from the one place that's allowed to change them
     * in response to a provider event.
     *
     * `$paymentStatus` is nullable: Payment.status represents the
     * *aggregate* result of the Payment (has it been paid, refunded — or
     * is it still open for another attempt), never the terminal result of
     * one individual attempt. applyFailed() passes null here for exactly
     * that reason — see its call site.
     */
    private function markSettled(
        StoreWalletTransaction $transaction,
        ?PaymentStatus $paymentStatus,
        ?PaymentAttemptStatus $attemptStatus,
        string $orderStatusSlug,
    ): void {
        $order = $transaction->referenceable;

        if (! $order instanceof Order) {
            return;
        }

        $order->update(['order_status_id' => OrderStatus::bySlugOrFail($orderStatusSlug)->id]);

        $payment = Payment::where('order_id', $order->id)->first();

        if ($payment === null) {
            return;
        }

        if ($paymentStatus !== null) {
            $payment->update(['status' => $paymentStatus]);
        }

        if ($attemptStatus !== null && $payment->current_payment_attempt_id !== null) {
            PaymentAttempt::where('id', $payment->current_payment_attempt_id)
                ->update(['status' => $attemptStatus]);
        }
    }

    /**
     * Persists a minimal, replayable reconstruction of an event this
     * processor couldn't resolve yet. Idempotent on `(provider,
     * provider_event_id)`: a duplicate delivery of the same event (before
     * it's been resolved) is a safe no-op, following the same "insert,
     * recover on the known unique-constraint race" pattern used throughout
     * this domain rather than an upsert that could overwrite an
     * already-stored payload.
     */
    private function storeUnmatchedEvent(ProviderEventOutcome $outcome): void
    {
        try {
            PaymentProviderEvent::create([
                'provider' => $outcome->provider,
                'provider_event_id' => $outcome->eventId,
                'event_type' => $outcome->eventType,
                'provider_reference' => $outcome->providerReference,
                'payload' => $outcome->replayPayload,
                'status' => ProviderEventStatus::Pending,
            ]);
        } catch (QueryException $e) {
            if (! PaymentProviderEvent::where('provider', $outcome->provider)
                ->where('provider_event_id', $outcome->eventId)
                ->exists()) {
                throw $e;
            }
        }
    }
}
