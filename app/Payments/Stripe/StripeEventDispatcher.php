<?php

namespace App\Payments\Stripe;

use App\Domain\Wallet\Exceptions\TransactionAlreadyReversedException;
use App\Domain\Wallet\Exceptions\TransactionNotPendingException;
use App\Domain\Wallet\WalletTransactionReference;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletTransactionService;
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
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event),
            'charge.refunded' => $this->handleChargeRefunded($event),
            default => Log::info("Unhandled Stripe event type: {$event->type}"),
        };
    }

    private function handlePaymentIntentSucceeded(Event $event): void
    {
        $paymentIntent = $event->data->object;

        $transaction = $this->findTransactionByReference($paymentIntent->id);

        if (! $transaction) {
            return;
        }

        try {
            $this->walletTransactionService->confirm($transaction);
        } catch (TransactionNotPendingException) {
            // Duplicate webhook delivery for an already-settled transaction: no-op.
        }

        $this->markOrder($transaction, 'paid');
    }

    private function handlePaymentIntentFailed(Event $event): void
    {
        $paymentIntent = $event->data->object;

        $transaction = $this->findTransactionByReference($paymentIntent->id);

        if (! $transaction) {
            return;
        }

        try {
            $this->walletTransactionService->markFailed(
                $transaction,
                $paymentIntent->last_payment_error?->message,
            );
        } catch (TransactionNotPendingException) {
            // Duplicate webhook delivery for an already-settled transaction: no-op.
        }

        $this->markOrder($transaction, 'failed');
    }

    private function handleChargeRefunded(Event $event): void
    {
        $charge = $event->data->object;

        $original = $this->findTransactionByReference($charge->payment_intent);

        if (! $original || ! $original->isCompleted()) {
            return;
        }

        try {
            $this->walletTransactionService->reverse(
                original: $original,
                reversalCategorySlug: 'customer_refund',
                reference: new WalletTransactionReference('stripe', $charge->id),
            );
        } catch (TransactionAlreadyReversedException) {
            // Duplicate webhook delivery for an already-reversed transaction: no-op.
        }

        $this->markOrder($original, 'refunded');
    }

    /**
     * The original sale transaction is created with the Order set as its
     * `referenceable` (see StripePaymentService). Reusing that link means the
     * dispatcher never has to trust or parse metadata coming back from Stripe.
     */
    private function findTransactionByReference(string $paymentIntentId): ?StoreWalletTransaction
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
