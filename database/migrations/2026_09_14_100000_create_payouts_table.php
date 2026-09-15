<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();

            // Financial history: none of these FKs cascade. A Store/wallet/
            // currency with payout history can never be hard-deleted out
            // from under it — see tests/Architecture/PayoutFinancialHistoryAppendOnlyTest.
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_wallet_id')->constrained('store_wallets')->restrictOnDelete();

            // Immutable snapshot, taken once at creation — never re-derived
            // from the (mutable) StoreWallet at settlement/reconciliation
            // time. See App\Domain\Payouts\Services\PayoutService::request().
            $table->decimal('amount', 18, 2);
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();

            // Idempotency key supplied by the caller (vendor UI), scoped per
            // store — mirrors payment_attempts.(provider, idempotency_key).
            // A repeated request with the same key returns the existing
            // Payout instead of creating a second reservation; see
            // PayoutService::request()'s insertOrIgnore-based algorithm,
            // chosen specifically to stay safe on PostgreSQL (a caught
            // unique-violation there aborts the whole transaction, unlike
            // MySQL).
            $table->string('idempotency_key');

            // reserved|processing|succeeded|cancelled|failed — see
            // App\Domain\Payouts\Enums\PayoutStatus. Only ever written
            // together with current_payout_attempt_id in the same UPDATE,
            // by PayoutService/PayoutEventProcessor — never a bare
            // ->update(['status' => ...]) from anywhere else.
            $table->string('status', 20)->default('reserved');

            // current_payout_attempt_id is added below, after
            // payout_attempts exists — the two tables reference each other
            // (mirrors payments.current_payment_attempt_id in
            // 2026_08_02_130000_create_payment_attempts_table.php).

            // The reservation debit (category 'withdrawal', completed at
            // creation). Set exactly once, atomically with the Payout row
            // itself — see PayoutService::request(). Never null once a
            // Payout is visible outside its own creating transaction.
            $table->foreignId('debit_transaction_id')->nullable()
                ->constrained('store_wallet_transactions')->restrictOnDelete();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(['store_id', 'idempotency_key']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
