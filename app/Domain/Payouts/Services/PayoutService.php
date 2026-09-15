<?php

namespace App\Domain\Payouts\Services;

use App\Domain\Payouts\DTOs\ProviderTransferResult;
use App\Domain\Payouts\Enums\PayoutAttemptStatus;
use App\Domain\Payouts\Enums\PayoutStatus;
use App\Domain\Payouts\Exceptions\PayoutAlreadyResolvedException;
use App\Domain\Payouts\Exceptions\PayoutAttemptMismatchException;
use App\Domain\Payouts\Exceptions\PayoutHasUnresolvedAttemptException;
use App\Domain\Payouts\Exceptions\PayoutIdempotencyKeyReusedException;
use App\Domain\Payouts\Exceptions\PayoutWalletNotFoundException;
use App\Domain\Payouts\Models\Payout;
use App\Domain\Payouts\Models\PayoutAttempt;
use App\Domain\Payouts\PayoutProviderManager;
use App\Domain\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Domain\Wallet\Exceptions\InvalidTransactionAmountException;
use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\Store;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Requests, and later executes, a store's withdrawal from its Wallet.
 * `request()` is the only place a Payout is ever created, and it always
 * creates the Payout together with its reservation debit — see the method's
 * own docblock for the exact atomicity/idempotency algorithm.
 *
 * The reservation debit is a completed `withdrawal` ledger transaction,
 * posted immediately — an accounting reservation, not evidence any transfer
 * has actually executed. That's what makes "does this store have enough
 * available balance" the exact same question the ledger already answers for
 * every other debit category (chargeback, penalty, manual_debit): the same
 * `SELECT ... FOR UPDATE` on the wallet row that already protects those
 * protects this too — no separate reserved_balance bookkeeping was
 * introduced. See docs discussion captured in this branch's own history for
 * why that was rejected.
 */
class PayoutService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletTransactionService $walletTransactionService,
        private readonly PayoutProviderManager $providers,
    ) {}

    /**
     * Reserves `$amount` of a store's wallet in `$currencyCode` against a
     * new Payout — atomically: either both the Payout row and its
     * `withdrawal` debit exist, or neither does.
     *
     * PostgreSQL-safe idempotent-insert algorithm: rather than INSERT-then-
     * catch-the-unique-violation (the pattern `WalletTransactionService`/
     * `PaymentService::findOrCreatePayment()` use elsewhere in this domain),
     * this uses `insertOrIgnore()` — `INSERT ... ON CONFLICT DO NOTHING` on
     * Postgres, `INSERT IGNORE` on MySQL — which never raises an error at
     * all, so it can safely sit inside the same open transaction as the
     * balance-checked `record()` call that follows it. Catching a
     * constraint violation mid-transaction is safe on MySQL but leaves a
     * PostgreSQL transaction aborted for every statement after it; since
     * this method's atomicity requirement (Payout row + debit, both-or-
     * neither) means the insert and the debit *must* share one transaction,
     * the catch-and-recover idiom isn't an option here.
     *
     * A genuinely concurrent request for the same (store, idempotency_key)
     * blocks on the unique index until the first caller's transaction
     * resolves: if it committed, this call's own insertOrIgnore is silently
     * skipped and the SELECT below reads back the already-completed Payout
     * (including its debit_transaction_id) — a pure, no-op replay. If it
     * rolled back (e.g. insufficient funds), the conflicting row no longer
     * exists and this call proceeds as a fresh insert.
     *
     * @throws PayoutWalletNotFoundException if the store has no wallet in `$currencyCode`
     * @throws PayoutIdempotencyKeyReusedException if `$idempotencyKey` already names a
     *                                             Payout with a different wallet/amount
     * @throws InsufficientWalletBalanceException if the
     *                                            wallet's available balance is less than `$amount`
     * @throws InvalidTransactionAmountException if `$amount` is not positive
     */
    public function request(
        Store $store,
        string $currencyCode,
        string $amount,
        string $idempotencyKey,
        ?User $requestedBy = null,
    ): Payout {
        $wallet = $this->walletService->getWallet($store, $currencyCode);

        if ($wallet === null) {
            throw new PayoutWalletNotFoundException(
                "Store #{$store->id} has no wallet in currency '{$currencyCode}'."
            );
        }

        return DB::transaction(function () use ($store, $wallet, $amount, $idempotencyKey, $requestedBy) {
            DB::table('payouts')->insertOrIgnore([
                'store_id' => $store->id,
                'store_wallet_id' => $wallet->id,
                'amount' => $amount,
                'currency_id' => $wallet->currency_id,
                'idempotency_key' => $idempotencyKey,
                'status' => PayoutStatus::Reserved->value,
                'requested_by' => $requestedBy?->id,
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $payout = Payout::where('store_id', $store->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();

            // Validate consistency *before* ever treating this as a replay —
            // an idempotency key naming a Payout with a different
            // wallet/amount is a caller bug (or a maliciously reused key),
            // never a "nothing more to do" situation, whether or not that
            // existing Payout has already been debited.
            if ((int) $payout->store_wallet_id !== $wallet->id || bccomp($payout->amount, $amount, 2) !== 0) {
                throw new PayoutIdempotencyKeyReusedException(
                    "Idempotency key '{$idempotencyKey}' for store #{$store->id} was already used ".
                    'for a different wallet or amount — refusing to reuse it for this request.'
                );
            }

            if ($payout->debit_transaction_id !== null) {
                // Pure replay of an already-completed request — nothing more to do.
                return $payout;
            }

            $transaction = $this->walletTransactionService->record(
                wallet: $wallet,
                categorySlug: 'withdrawal',
                amount: $amount,
                reference: new WalletTransactionReference('internal', "payout-{$payout->id}"),
                options: [
                    'status' => 'completed',
                    'referenceable' => $payout,
                    'source' => $requestedBy !== null ? TransactionSource::Api : TransactionSource::System,
                    'description' => "Payout #{$payout->id} reservation",
                ],
            );
            // If record() throws (insufficient funds, invalid amount), this
            // whole transaction rolls back — including the insertOrIgnore
            // insert above, via the same transaction/savepoint — so no
            // Payout row is ever left behind without its debit.

            $payout->update(['debit_transaction_id' => $transaction->id]);

            return $payout->fresh();
        });
    }

    /**
     * Starts a new attempt for a Payout, calls the provider, and finalizes
     * it — mirrors PaymentService::startAttempt()'s two-step shape.
     */
    public function startAttempt(Payout $payout, string $provider): PayoutAttempt
    {
        return $this->finalizeAttempt($this->createDurableAttempt($payout, $provider));
    }

    /**
     * Locks the Payout row and either creates a fresh durable PayoutAttempt
     * or returns the one already blocking a new one — never both at once.
     * The lock is released before any remote call is ever made — see the
     * class docblock and PaymentService::createDurableAttempt(), which this
     * mirrors exactly except for the terminal-state check (three terminal
     * states here, not one).
     *
     * @throws PayoutAlreadyResolvedException if the Payout is already Succeeded/Cancelled/Failed
     */
    public function createDurableAttempt(Payout $payout, string $provider): PayoutAttempt
    {
        return DB::transaction(function () use ($payout, $provider) {
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($locked->status->isTerminal()) {
                throw new PayoutAlreadyResolvedException(
                    "Payout #{$locked->id} is already {$locked->status->value}; no new attempt can be started."
                );
            }

            if ($locked->current_payout_attempt_id !== null) {
                $current = PayoutAttempt::find($locked->current_payout_attempt_id);

                if ($current !== null && $current->status->blocksNewAttempt()) {
                    return $current;
                }
            }

            $attempt = PayoutAttempt::create([
                'payout_id' => $locked->id,
                'provider' => $provider,
                // Placeholder until the attempt has its own id to derive its
                // real, deterministic key from — mirrors
                // PaymentService::createDurableAttempt() exactly, including
                // why this must be unique per insert rather than a shared
                // constant (see payment_attempts.idempotency_key's own
                // migration comment).
                'idempotency_key' => 'pending-'.Str::uuid(),
                'status' => PayoutAttemptStatus::Pending,
            ]);

            $attempt->forceFill([
                'idempotency_key' => "payout-{$locked->id}-attempt-{$attempt->id}",
            ])->save();

            $locked->update([
                'current_payout_attempt_id' => $attempt->id,
                'status' => PayoutStatus::Processing,
            ]);

            return $attempt;
        });
    }

    /**
     * Calls the provider (idempotent under the attempt's own
     * idempotency_key) and claims its transfer reference via a conditional
     * `UPDATE ... WHERE provider_reference IS NULL` — never a plain save.
     * Unlike PaymentService::claimProviderReference(), this never mutates
     * the Wallet: the reservation debit was already posted at Payout
     * creation, so claiming here is pure correlation bookkeeping.
     *
     * No SupportsCanonicalRetrieval-style "loser retrieves canonical"
     * fallback exists here (see PayoutProviderContract's own docblock for
     * why): every adapter's createTransfer() is required to be
     * idempotent — the same idempotency_key always yields the same
     * reference — so a losing caller's own $result is, by that contract,
     * already identical to whatever won. If it isn't, that is itself a
     * contract violation this method fails closed against
     * (PayoutAttemptMismatchException) rather than silently trusting either
     * value.
     */
    public function finalizeAttempt(PayoutAttempt $attempt): PayoutAttempt
    {
        if ($attempt->provider_reference !== null) {
            return $attempt;
        }

        $result = $this->providers->driver($attempt->provider)->createTransfer($attempt);

        $this->assertResultMatchesAttempt($result, $attempt);

        $won = false;

        DB::transaction(function () use ($attempt, $result, &$won) {
            $affected = PayoutAttempt::where('id', $attempt->id)
                ->whereNull('provider_reference')
                ->update([
                    'provider_reference' => $result->providerReference,
                    'status' => PayoutAttemptStatus::Claimed,
                ]);

            $won = $affected === 1;
        });

        $canonical = $attempt->fresh();

        if ($canonical->provider_reference !== $result->providerReference) {
            throw new PayoutAttemptMismatchException(
                "Attempt #{$attempt->id} claimed provider_reference '{$canonical->provider_reference}', ".
                "which does not match this call's own result '{$result->providerReference}' — ".
                'the provider adapter violated its idempotency contract.'
            );
        }

        return $canonical;
    }

    /**
     * Releases a Payout's reservation exactly once, via a `withdrawal_reversal`
     * — the ONLY place in this domain that ever reverses a payout debit.
     * Never called as a side effect of a single attempt failing (see
     * PayoutAttemptStatus's own docblock) — only as an explicit decision
     * about the Payout aggregate itself, either administrative (Cancelled)
     * or after every attempt tried has come back definitively negative
     * (Failed).
     *
     * @throws PayoutHasUnresolvedAttemptException if the current attempt's outcome at the
     *                                             provider is still unknown (Pending/Claimed/NeedsAttention) —
     *                                             releasing here risks a double-spend if the provider later
     *                                             executes the transfer for real. See the exception's own docblock.
     */
    public function abandon(Payout $payout, PayoutStatus $terminalStatus, string $reason): Payout
    {
        if (! in_array($terminalStatus, [PayoutStatus::Cancelled, PayoutStatus::Failed], true)) {
            throw new InvalidArgumentException('abandon() only accepts Cancelled or Failed as the terminal status.');
        }

        return DB::transaction(function () use ($payout, $terminalStatus, $reason) {
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($locked->status->isTerminal()) {
                return $locked; // already resolved — no-op, never a second reversal.
            }

            $current = $locked->current_payout_attempt_id !== null
                ? PayoutAttempt::find($locked->current_payout_attempt_id)
                : null;

            if ($current !== null && $current->status->acceptsOutcome()) {
                throw new PayoutHasUnresolvedAttemptException(
                    "Payout #{$locked->id}'s current attempt #{$current->id} is still ".
                    "{$current->status->value} — its outcome at the provider is unknown, ".
                    'so the reservation cannot be released without risking a double-spend.'
                );
            }

            $debit = $this->walletTransactionService->reverse(
                original: $locked->debitTransaction,
                reversalCategorySlug: 'withdrawal_reversal',
                description: $reason,
                reference: new WalletTransactionReference('internal', "payout-{$locked->id}-reversal"),
                options: ['referenceable' => $locked],
            );

            $locked->update(['status' => $terminalStatus, 'completed_at' => now()]);

            return $locked->fresh();
        });
    }

    /**
     * The idempotency key is deterministic and attempt-scoped, but a
     * replayed request only ever proves "the provider returned *a* transfer
     * for this key" — not that it still matches this Payout. Mirrors
     * PaymentService::assertResultMatchesAttempt() exactly, checked before
     * finalizeAttempt() ever persists anything.
     */
    private function assertResultMatchesAttempt(ProviderTransferResult $result, PayoutAttempt $attempt): void
    {
        $payout = Payout::with('currency')->findOrFail($attempt->payout_id);

        $expectedCurrency = strtolower($payout->currency->code);
        $expectedCorrelationId = (string) $payout->id;

        if (bccomp($result->amount, $payout->amount, 2) === 0
            && strtolower($result->currency) === $expectedCurrency
            && $result->correlationId === $expectedCorrelationId) {
            return;
        }

        throw new PayoutAttemptMismatchException(
            "Provider transfer {$result->providerReference} does not match Payout #{$payout->id}: ".
            "expected amount={$payout->amount} currency={$expectedCurrency} correlationId={$expectedCorrelationId}, ".
            "got amount={$result->amount} currency={$result->currency} correlationId=".($result->correlationId ?? 'null'),
        );
    }
}
