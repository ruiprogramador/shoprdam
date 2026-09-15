<?php

namespace App\Domain\Payouts\Models;

use App\Domain\Payouts\Enums\PayoutStatus;
use App\Models\Store;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nnjeim\World\Models\Currency;

/**
 * The financial aggregate/intent for a store's withdrawal — never a
 * concrete attempt against a provider (see PayoutAttempt for that). `amount`
 * and `currency_id` are an immutable snapshot taken once at creation (see
 * App\Domain\Payouts\Services\PayoutService::request()) — settlement and
 * reconciliation always validate against these persisted values, never
 * re-derive them from the (mutable) StoreWallet.
 *
 * `debit_transaction_id` points at the `withdrawal` ledger transaction
 * posted atomically with this row — a *reservation*, not proof a transfer
 * ever executed. See this model's own `status` docblock
 * (App\Domain\Payouts\Enums\PayoutStatus) for exactly when that reservation
 * is released.
 *
 * Financial history: append-only. Nothing in this domain ever deletes a
 * Payout — see tests/Architecture/PayoutFinancialHistoryAppendOnlyTest.
 */
class Payout extends Model
{
    protected $fillable = [
        'store_id',
        'store_wallet_id',
        'amount',
        'currency_id',
        'idempotency_key',
        'status',
        'current_payout_attempt_id',
        'debit_transaction_id',
        'requested_by',
        'requested_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => PayoutStatus::class,
        'amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(StoreWallet::class, 'store_wallet_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function debitTransaction(): BelongsTo
    {
        return $this->belongsTo(StoreWalletTransaction::class, 'debit_transaction_id');
    }

    public function currentAttempt(): BelongsTo
    {
        return $this->belongsTo(PayoutAttempt::class, 'current_payout_attempt_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PayoutAttempt::class);
    }
}
