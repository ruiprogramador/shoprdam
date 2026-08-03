<?php

namespace App\Payments\Stripe;

use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\PaymentIntentAttemptStatus;
use App\Enums\TransactionSource;
use App\Models\Order;
use App\Models\OrderPaymentIntent;
use App\Models\OrderPaymentIntentAttempt;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class StripePaymentService
{
    private StripeClient $client;

    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletTransactionService $walletTransactionService,
    ) {
        $this->client = new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Create a Stripe PaymentIntent for an order and record the matching
     * pending wallet transaction up front, referenced by the PaymentIntent id.
     * The webhook only ever needs to confirm/fail/reverse that reference —
     * it never records a transaction from scratch.
     *
     * An Order may have at most one PaymentIntent, enforced by the unique
     * `order_id` on `order_payment_intents`. Two calls for the same Order —
     * a plain repeat call, or a genuine race between two requests — must not
     * end up with two PaymentIntents and two pending Wallet transactions for
     * one Order. The deterministic idempotency key makes Stripe itself
     * return the same PaymentIntent for both; the local unique constraint is
     * what actually decides whether record() runs, and is treated as the
     * source of truth for which PaymentIntent belongs to this Order — not
     * an assumption that Stripe's response always matches it.
     *
     * The losing side's recovery query — reading back which PaymentIntent
     * actually got claimed — runs after the transaction, not inside a catch
     * nested within it. DB::transaction() always rolls back before
     * rethrowing (see Illuminate\Database\Concerns\ManagesTransactions),
     * so this doesn't depend on being able to run a query inside a
     * transaction that already failed a statement — which e.g. PostgreSQL
     * won't allow (it aborts the whole transaction until rollback), even
     * though MySQL and SQLite tolerate it. Recovering only after the
     * rollback keeps this correct regardless of which of those the app
     * runs on, without needing to match a specific engine's constraint
     * violation SQLSTATE.
     *
     * If record() fails after the PaymentIntent and the claim row are both
     * created, the same rollback undoes the claim row too — so a later
     * retry isn't permanently locked out, it just calls Stripe again (same
     * idempotency key, same PaymentIntent) and tries once more.
     *
     * An OrderPaymentIntentAttempt row is written *before* the Stripe call,
     * in its own fast local commit — durable proof "we were about to ask
     * Stripe for this Order" that survives a crash between Stripe
     * responding and the transaction below committing. That's the gap
     * documented in docs/wallet/integrations.md: without this row, nothing
     * distinguishes "never attempted" from "attempted, Stripe has a
     * PaymentIntent, but the local write never landed". Attempts left
     * `pending` past a threshold are what
     * App\Console\Commands\ReconcileOrphanedPaymentIntents retries, simply
     * by calling this method again — the idempotency key means that either
     * recreates the request or picks up the original result, and the claim
     * logic below already makes that call safe to repeat.
     */
    public function createPaymentIntentForOrder(Order $order): PaymentIntent
    {
        $currencyCode = $order->currency->code;
        $paymentIntent = $this->requestPaymentIntent($order);

        try {
            DB::transaction(function () use ($order, $paymentIntent, $currencyCode) {
                OrderPaymentIntent::create([
                    'order_id' => $order->id,
                    'payment_intent_id' => $paymentIntent->id,
                ]);

                $wallet = $this->walletService->getOrCreateWallet($order->store, $currencyCode);

                $this->walletTransactionService->record(
                    wallet: $wallet,
                    categorySlug: 'sale',
                    amount: $order->amount,
                    reference: new WalletTransactionReference('stripe', $paymentIntent->id),
                    options: [
                        'status' => 'pending',
                        'referenceable' => $order,
                        'source' => TransactionSource::Api,
                        'description' => "Order #{$order->id}",
                    ],
                );

                OrderPaymentIntentAttempt::where('order_id', $order->id)
                    ->update(['status' => PaymentIntentAttemptStatus::Claimed]);
            });

            $claimedPaymentIntentId = $paymentIntent->id;
        } catch (QueryException $e) {
            // The transaction above is already rolled back at this point.
            // A claim row existing here means this call lost a race (or
            // it's a plain repeat call) against the order_id unique
            // constraint; anything else (e.g. a genuinely unrelated
            // integrity violation) leaves no claim behind, so it's
            // rethrown instead of being misread as "the known race".
            $claimedPaymentIntentId = OrderPaymentIntent::where('order_id', $order->id)->value('payment_intent_id');

            if ($claimedPaymentIntentId === null) {
                throw $e;
            }
        }

        // The common case: this call's own PaymentIntent is the claimed one,
        // no extra request needed. Only re-fetched if this call lost a race
        // against another claim.
        return $claimedPaymentIntentId === $paymentIntent->id
            ? $paymentIntent
            : $this->client->paymentIntents->retrieve($claimedPaymentIntentId);
    }

    /**
     * Writes the `pending` attempt row and calls Stripe — the part of
     * createPaymentIntentForOrder() that happens *before* the local claim
     * and Wallet transaction. Shared with
     * {@see self::createOrphanedAttemptForTesting()}, which stops right
     * here on purpose.
     */
    private function requestPaymentIntent(Order $order): PaymentIntent
    {
        $idempotencyKey = "order-{$order->id}-payment-intent";

        try {
            OrderPaymentIntentAttempt::create([
                'order_id' => $order->id,
                'idempotency_key' => $idempotencyKey,
                'status' => PaymentIntentAttemptStatus::Pending,
            ]);
        } catch (QueryException $e) {
            if (! OrderPaymentIntentAttempt::where('order_id', $order->id)->exists()) {
                throw $e;
            }
        }

        return $this->client->paymentIntents->create(
            [
                'amount' => StripeAmount::toMinorUnits($order->amount),
                'currency' => strtolower($order->currency->code),
                'metadata' => [
                    'order_id' => (string) $order->id,
                ],
            ],
            [
                'idempotency_key' => $idempotencyKey,
            ],
        );
    }

    /**
     * Test-tooling only — used exclusively by `app:stripe-test-order
     * --simulate-orphan` to manually exercise the recovery path documented
     * in docs/wallet/integrations.md ("Recovering orphaned
     * PaymentIntents"). Reproduces the real crash window on purpose: it
     * asks Stripe for a PaymentIntent and writes the `pending` attempt row,
     * then stops — deliberately skipping the claim + Wallet transaction
     * that createPaymentIntentForOrder() normally records straight after.
     * Never call this from production code paths; nothing here settles a
     * payment or should be reachable outside of manual validation.
     */
    public function createOrphanedAttemptForTesting(Order $order): PaymentIntent
    {
        return $this->requestPaymentIntent($order);
    }
}
