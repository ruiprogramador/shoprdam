<?php

namespace App\Payments\Stripe;

use App\Domain\Wallet\Exceptions\TransactionAlreadyReversedException;
use App\Domain\Wallet\Exceptions\TransactionNotPendingException;
use App\Domain\Wallet\WalletTransactionReference;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;

/**
 * Translates Stripe events into Wallet operations. This is the only place
 * that is allowed to know both Stripe's vocabulary (PaymentIntent, Charge,
 * event types) and the Wallet's vocabulary (record/confirm/markFailed/reverse).
 */
class StripeEventDispatcher
{
    public function __construct(
        private readonly WalletTransactionService $walletTransactionService,
    ) {}

    public function dispatch(Event $event): void
    {
        match ($event->type) {
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),
            'payment_intent.payment_failed' => $this->handlePaymentIntentPaymentFailed($event),
            'payment_intent.canceled' => $this->handlePaymentIntentCanceled($event),
            'charge.refunded' => $this->handleChargeRefunded($event),
            default => Log::info("Unhandled Stripe event type: {$event->type}"),
        };
    }

    private function handlePaymentIntentSucceeded(Event $event): void
    {
        $paymentIntent = $event->data->object;

        $transaction = $this->findOriginalTransactionByPaymentIntentId($paymentIntent->id);

        if (! $transaction) {
            return;
        }

        try {
            // Wrapped so a failure in markOrder() (after confirm() already
            // succeeded) rolls back the Wallet mutation too, instead of
            // leaving the transaction completed with the Order stuck
            // pending — a state a replayed webhook could never repair,
            // since confirm() would then just hit its "already settled"
            // no-op. WalletTransactionService::confirm() opens its own
            // transaction internally; nesting here uses a savepoint.
            DB::transaction(function () use ($transaction) {
                $this->walletTransactionService->confirm($transaction);
                $this->markOrder($transaction, 'paid');
            });
        } catch (TransactionNotPendingException) {
            // Duplicate webhook delivery for an already-settled transaction: no-op.
            return;
        }
    }

    /**
     * A failed payment attempt does not kill the PaymentIntent: Stripe lets
     * the customer retry with a different payment method on the same
     * PaymentIntent id, which can still end in payment_intent.succeeded.
     * Stripe fires `payment_intent.canceled` as its own, separate event when
     * the PaymentIntent is actually done — that's the only thing allowed to
     * terminally fail the transaction (see handlePaymentIntentCanceled()).
     * This handler is informational only: it never mutates the Wallet or the
     * Order, on purpose.
     */
    private function handlePaymentIntentPaymentFailed(Event $event): void
    {
        $paymentIntent = $event->data->object;

        Log::info(
            "Payment attempt failed for PaymentIntent {$paymentIntent->id} ".
            "(status: {$paymentIntent->status}): ".
            ($paymentIntent->last_payment_error?->message ?? 'no error message provided').
            '. The transaction is left pending in case of a retry.'
        );
    }

    private function handlePaymentIntentCanceled(Event $event): void
    {
        $paymentIntent = $event->data->object;

        $transaction = $this->findOriginalTransactionByPaymentIntentId($paymentIntent->id);

        if (! $transaction) {
            return;
        }

        try {
            // See handlePaymentIntentSucceeded() for why this is wrapped.
            DB::transaction(function () use ($transaction, $paymentIntent) {
                $this->walletTransactionService->markFailed(
                    $transaction,
                    $paymentIntent->last_payment_error?->message,
                );
                $this->markOrder($transaction, 'failed');
            });
        } catch (TransactionNotPendingException) {
            // Duplicate webhook delivery for an already-settled transaction: no-op.
            return;
        }
    }

    private function handleChargeRefunded(Event $event): void
    {
        $charge = $event->data->object;

        if (! is_string($charge->payment_intent) || $charge->payment_intent === '') {
            Log::warning("Ignoring charge.refunded for Charge {$charge->id}: no PaymentIntent reference.");

            return;
        }

        $original = $this->findOriginalTransactionByPaymentIntentId($charge->payment_intent);

        if (! $original || ! $original->isCompleted()) {
            return;
        }

        // `amount_refunded` is the Charge's cumulative refunded total, not the
        // amount of this individual event. reverse() always reverses the full
        // original amount, so we only act once that cumulative total reaches
        // it (the Charge is now fully refunded); earlier partial-refund
        // events on the way there are ignored, not accounted for.
        $expectedRefundedMinorUnits = StripeAmount::toMinorUnits($original->amount);

        if ((int) $charge->amount_refunded !== $expectedRefundedMinorUnits) {
            Log::warning(
                "Ignoring charge.refunded for PaymentIntent {$charge->payment_intent}: ".
                "charge is refunded {$charge->amount_refunded}/{$expectedRefundedMinorUnits} so far. ".
                'Reversal only happens once the Charge is fully refunded; partial reversals are not supported.'
            );

            return;
        }

        try {
            // See handlePaymentIntentSucceeded() for why this is wrapped.
            DB::transaction(function () use ($original, $charge) {
                $this->walletTransactionService->reverse(
                    original: $original,
                    reversalCategorySlug: 'customer_refund',
                    reference: new WalletTransactionReference('stripe', $charge->id),
                );
                $this->markOrder($original, 'refunded');
            });
        } catch (TransactionAlreadyReversedException) {
            // Duplicate webhook delivery for an already-reversed transaction: no-op.
            return;
        }
    }

    /**
     * The original sale transaction is created with the Order set as its
     * `referenceable` (see StripePaymentService). Reusing that link means the
     * dispatcher never has to trust or parse metadata coming back from Stripe.
     */
    private function findOriginalTransactionByPaymentIntentId(string $paymentIntentId): ?StoreWalletTransaction
    {
        return StoreWalletTransaction::query()
            ->where('external_provider', 'stripe')
            ->where('external_reference', $paymentIntentId)
            ->whereNull('related_transaction_id')
            ->first();
    }

    private function markOrder(StoreWalletTransaction $transaction, string $statusSlug): void
    {
        $order = $transaction->referenceable;

        if (! $order instanceof Order) {
            return;
        }

        $order->update([
            'order_status_id' => OrderStatus::bySlugOrFail($statusSlug)->id,
        ]);
    }
}
