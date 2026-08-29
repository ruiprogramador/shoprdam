<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\PaymentProviderContract;
use App\Domain\Payments\Contracts\SupportsCanonicalRetrieval;
use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Exceptions\PaymentAlreadyResolvedException;
use App\Domain\Payments\Exceptions\PaymentAttemptMismatchException;
use App\Domain\Payments\MinorUnits;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\PaymentProviderManager;
use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\Order;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Starts, resumes, and finalizes PaymentAttempts against whichever provider
 * they're for — the provider-agnostic replacement for the old
 * App\Payments\Stripe\StripePaymentService. Talks to a resolved
 * PaymentProviderContract for the remote call and to the Wallet for the
 * matching pending transaction, but never interprets a provider event
 * itself — that's PaymentEventProcessor's job, reached only via a
 * provider's own webhook controller or by replayUnmatchedEvents() here
 * after a claim is (re)confirmed.
 *
 * Two entry points:
 *
 * - startAttempt(): the gated creation path. Locks the Payment row,
 *   creates a new PaymentAttempt only if none is currently non-terminal
 *   (PaymentAttemptStatus::blocksNewAttempt()) and the Payment itself is
 *   still `pending` — otherwise resumes the existing in-flight attempt
 *   instead of creating a second one. This is what makes "one Order, up to
 *   one *active* attempt at a time, replayable across providers/methods"
 *   hold, without opening the double-payment race a second *concurrent*
 *   attempt would.
 * - finalizeAttempt(): given an attempt row that already exists (fresh
 *   from startAttempt(), or a stale `pending`/`claimed` one
 *   App\Console\Commands\ReconcileOrphanedPaymentAttempts is recovering),
 *   calls the provider (idempotently, safe to repeat), validates the
 *   result against the attempt's Payment, and persists the claim + pending
 *   Wallet transaction. Safe to call again on an already-claimed attempt —
 *   it skips straight to replaying any queued provider events.
 *
 * The durable attempt row is always written *before* the provider is ever
 * contacted, in its own fast commit — closes the crash window between the
 * provider creating a remote payment and the local write that would
 * otherwise be the only record of it. See
 * docs/wallet/integrations.md ("Recovering orphaned payment attempts").
 */
class PaymentService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletTransactionService $walletTransactionService,
        private readonly PaymentProviderManager $providers,
        private readonly PaymentEventProcessor $eventProcessor,
    ) {}

    /**
     * Start a new attempt for an Order's Payment, or resume the one
     * already in flight — then immediately calls the provider and
     * finalizes it (see finalizeAttempt()).
     *
     * @throws PaymentAlreadyResolvedException if the Payment is no longer `pending`
     */
    public function startAttempt(Order $order, string $provider, string $method): PaymentAttempt
    {
        return $this->finalizeAttempt($this->createDurableAttempt($order, $provider, $method));
    }

    /**
     * Finish an attempt that already exists: call the provider (if it
     * hasn't already been claimed), validate the result, persist the claim
     * + pending Wallet transaction, then replay any provider events queued
     * for this reference before the claim existed.
     *
     * Idempotent — calling this again on an already-`claimed` attempt
     * skips straight to the replay step, which is itself a safe no-op once
     * nothing is left `pending` to replay.
     */
    public function finalizeAttempt(PaymentAttempt $attempt): PaymentAttempt
    {
        if ($attempt->provider_reference === null) {
            $this->claimProviderReference($attempt);
        }

        $attempt = $attempt->fresh();

        $this->eventProcessor->replayUnmatchedEvents($attempt->provider, $attempt->provider_reference);

        return $attempt;
    }

    /**
     * Test-tooling only — used exclusively by `app:stripe-test-order
     * --simulate-orphan` to manually exercise the recovery path documented
     * in docs/wallet/integrations.md. Reproduces the real crash window on
     * purpose: writes the durable attempt row and calls the provider, then
     * stops — deliberately skipping the claim + Wallet transaction that
     * finalizeAttempt() normally records straight after. Never call this
     * from production code paths.
     *
     * Returns the raw, deliberately *not persisted* provider result (not
     * the attempt) — its own `provider_reference` is intentionally left
     * null, since that's the exact state ReconcileOrphanedPaymentAttempts
     * recovers from; the caller only needs the id to print recovery
     * instructions for.
     */
    public function createOrphanedAttemptForTesting(Order $order, string $provider, string $method): ProviderPaymentResult
    {
        $attempt = $this->createDurableAttempt($order, $provider, $method);

        // createDurableAttempt() only ever returns an existing attempt
        // (instead of a fresh one) when one is already blocking a new one
        // for this Payment — not expected for the fresh Order this tool
        // always creates first, but resolved the same safe way
        // finalizeAttempt() would if it somehow did happen: call the
        // provider again under the attempt's own idempotency key.
        return $this->providers->driver($attempt->provider)->createOrGetPayment($attempt);
    }

    /**
     * Locks the Payment row for the Order (creating it if this is the
     * first attempt ever), and either creates a fresh durable
     * PaymentAttempt row or returns the one already blocking a new one —
     * never both at once. The lock is released before any remote call is
     * ever made (see the class docblock): holding a DB row lock across an
     * HTTP call to a payment provider is exactly the anti-pattern this
     * design avoids.
     */
    private function createDurableAttempt(Order $order, string $provider, string $method): PaymentAttempt
    {
        $payment = $this->findOrCreatePayment($order);

        return DB::transaction(function () use ($payment, $provider, $method) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status !== PaymentStatus::Pending) {
                throw new PaymentAlreadyResolvedException(
                    "Payment #{$locked->id} for Order #{$locked->order_id} is already {$locked->status->value}; no new attempt can be started."
                );
            }

            if ($locked->current_payment_attempt_id !== null) {
                $current = PaymentAttempt::find($locked->current_payment_attempt_id);

                if ($current !== null && $current->status->blocksNewAttempt()) {
                    return $current;
                }
            }

            $attempt = PaymentAttempt::create([
                'payment_id' => $locked->id,
                'provider' => $provider,
                'method' => $method,
                // Placeholder until the attempt has its own id to derive a
                // deterministic key from — see the forceFill below. Every
                // retry of *this* attempt reuses the same key; a later,
                // different attempt (new provider/method after this one
                // fails) gets its own.
                'idempotency_key' => '',
                'status' => PaymentAttemptStatus::Pending,
            ]);

            $attempt->forceFill([
                'idempotency_key' => "payment-{$locked->id}-attempt-{$attempt->id}",
            ])->save();

            $locked->update(['current_payment_attempt_id' => $attempt->id]);

            return $attempt;
        });
    }

    private function findOrCreatePayment(Order $order): Payment
    {
        try {
            return Payment::create([
                'order_id' => $order->id,
                'status' => PaymentStatus::Pending,
            ]);
        } catch (QueryException $e) {
            // Lost the order_id race (or this is a plain repeat call) —
            // same "insert, recover on the known unique-constraint race"
            // pattern used throughout this domain.
            $payment = Payment::where('order_id', $order->id)->first();

            if ($payment === null) {
                throw $e;
            }

            return $payment;
        }
    }

    /**
     * Calls the provider (idempotent — safe to repeat under the same
     * attempt's deterministic idempotency key), validates the result
     * against the attempt's Payment, and persists the claim + pending
     * Wallet transaction in one transaction.
     *
     * The persistence step is a conditional `UPDATE ... WHERE provider_reference
     * IS NULL`, not a plain save — if two processes are finalizing the
     * *same* attempt row concurrently (e.g. a reconciliation lease expired
     * and got reclaimed while the original worker was still mid-call),
     * only one UPDATE can win. The loser reads back whichever reference
     * actually got persisted and, if it differs from its own result,
     * fetches and validates *that* one before ever trusting it — the
     * database's row, not either caller's own provider response, is the
     * source of truth for which reference this attempt claimed.
     */
    private function claimProviderReference(PaymentAttempt $attempt): void
    {
        $provider = $this->providers->driver($attempt->provider);
        $result = $provider->createOrGetPayment($attempt);

        $this->assertResultMatchesAttempt($result, $attempt);

        $won = false;

        DB::transaction(function () use ($attempt, $result, &$won) {
            $affected = PaymentAttempt::where('id', $attempt->id)
                ->whereNull('provider_reference')
                ->update([
                    'provider_reference' => $result->providerReference,
                    'status' => PaymentAttemptStatus::Claimed,
                ]);

            $won = $affected === 1;

            if (! $won) {
                return;
            }

            $payment = Payment::with('order.store', 'order.currency')->findOrFail($attempt->payment_id);
            $order = $payment->order;
            $wallet = $this->walletService->getOrCreateWallet($order->store, $order->currency->code);

            $this->walletTransactionService->record(
                wallet: $wallet,
                categorySlug: 'sale',
                amount: $order->amount,
                reference: new WalletTransactionReference($attempt->provider, $result->providerReference),
                options: [
                    'status' => 'pending',
                    'referenceable' => $order,
                    'source' => TransactionSource::Api,
                    'description' => "Order #{$order->id}",
                ],
            );
        });

        if ($won) {
            return;
        }

        $canonicalReference = $attempt->fresh()->provider_reference;

        if ($canonicalReference === $result->providerReference) {
            // This call's own (already-validated) result is what won —
            // nothing more to check.
            return;
        }

        $this->assertResultMatchesAttempt(
            $this->retrieveCanonical($provider, $canonicalReference, $attempt),
            $attempt,
        );
    }

    /**
     * @throws PaymentAttemptMismatchException if the provider can't retrieve the canonical
     *                                         reference to validate it — failing closed rather
     *                                         than trusting an unvalidated reference
     */
    private function retrieveCanonical(PaymentProviderContract $provider, string $reference, PaymentAttempt $attempt): ProviderPaymentResult
    {
        if (! $provider instanceof SupportsCanonicalRetrieval) {
            throw new PaymentAttemptMismatchException(
                "Attempt #{$attempt->id}'s own result lost the provider_reference race for provider '{$attempt->provider}', ".
                'which cannot retrieve a payment by reference to validate the canonical one — failing closed.'
            );
        }

        return $provider->retrieveByReference($reference);
    }

    /**
     * The idempotency key is deterministic and attempt-scoped, but a
     * replayed request only ever proves "the provider returned *a* payment
     * for this key" — not that it still matches this Payment's Order.
     * Checking amount, currency, and correlation id here, *before*
     * claimProviderReference() ever persists anything, is what stops a
     * mismatched remote payment from ever being recorded as this Payment's
     * — instead of assuming "the provider gave it back, so it must be right."
     */
    private function assertResultMatchesAttempt(ProviderPaymentResult $result, PaymentAttempt $attempt): void
    {
        $payment = Payment::with('order.currency')->findOrFail($attempt->payment_id);
        $order = $payment->order;

        $expectedAmount = MinorUnits::fromDecimal($order->amount);
        $expectedCurrency = strtolower($order->currency->code);
        $expectedCorrelationId = (string) $order->id;

        if ($result->amountMinorUnits === $expectedAmount
            && strtolower($result->currency) === $expectedCurrency
            && $result->correlationId === $expectedCorrelationId) {
            return;
        }

        throw new PaymentAttemptMismatchException(
            "Provider payment {$result->providerReference} does not match Payment #{$attempt->payment_id} (Order #{$order->id}): ".
            "expected amount={$expectedAmount} currency={$expectedCurrency} correlationId={$expectedCorrelationId}, ".
            "got amount={$result->amountMinorUnits} currency={$result->currency} correlationId=".($result->correlationId ?? 'null'),
        );
    }
}
