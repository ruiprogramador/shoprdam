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
        Schema::create('payout_attempts', function (Blueprint $table) {
            $table->id();

            // Many attempts per Payout over time (a Failed attempt does not
            // block a new one — see PayoutAttemptStatus::blocksNewAttempt()),
            // deliberately not unique. Restrict, not cascade: financial
            // history must never disappear because its parent Payout row
            // was removed — see
            // tests/Architecture/PayoutFinancialHistoryAppendOnlyTest.
            $table->foreignId('payout_id')->constrained()->restrictOnDelete();

            $table->string('provider', 40);

            // Technical identity of this attempt within the provider's own
            // reference space (for ManualPayoutProvider: derived from this
            // row's own idempotency_key once it exists). Correlation only —
            // never evidence a transfer actually happened. Populated only
            // after claim; null while the durable pre-call row exists but
            // hasn't been claimed yet.
            $table->string('provider_reference')->nullable();

            // The REAL bank/SEPA reference an operator enters as evidence a
            // transfer actually happened — never to be confused with
            // provider_reference above. Written exactly once, only inside
            // PayoutEventProcessor::applySucceeded(), guarded by
            // ->whereNull('external_transfer_reference') in the same
            // conditional UPDATE that sets status => Succeeded — immutable
            // by construction, never overwritten afterwards.
            $table->string('external_transfer_reference')->nullable();

            // Deterministic, derived from this row's own id after insert —
            // mirrors payment_attempts.idempotency_key exactly (see
            // PayoutService::createDurableAttempt()). Never a logical
            // counter computed ahead of the insert.
            $table->string('idempotency_key');

            // pending|claimed|succeeded|failed|needs_attention — see
            // App\Domain\Payouts\Enums\PayoutAttemptStatus. Failed does NOT
            // block a new attempt for the same Payout, and does NOT by
            // itself release the Payout's reservation — see
            // PayoutAttemptStatus's own docblock and
            // App\Domain\Payouts\Services\PayoutService::abandon().
            $table->string('status', 20)->default('pending');

            // Reconciliation lease — same shape and purpose as
            // payment_attempts.locked_until.
            $table->timestamp('locked_until')->nullable();

            $table->unsignedInteger('recovery_attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->text('last_recovery_error')->nullable();

            $table->timestamps();

            // Mirrors payment_attempts.(provider, provider_reference) —
            // multiple not-yet-claimed attempts (still null) may coexist
            // under a unique index.
            $table->unique(['provider', 'provider_reference']);

            // A real bank reference may never be reused to confirm a
            // different payout under the same provider — see the column's
            // own comment above.
            $table->unique(['provider', 'external_transfer_reference']);

            $table->unique(['provider', 'idempotency_key']);

            $table->index(['status', 'created_at']);
            $table->index(['status', 'locked_until']);
        });

        // payouts.current_payout_attempt_id has to be added here, after
        // payout_attempts exists — the two tables reference each other.
        // restrictOnDelete(), not nullOnDelete(): a PayoutAttempt is
        // append-only financial history (see this table's own comments
        // above) — deleting the attempt a Payout currently points to and
        // silently nulling the pointer would let that history disappear
        // without a trace. This, together with payout_attempts.payout_id's
        // own restrictOnDelete() above, makes it impossible at the database
        // level to delete either a Payout or a PayoutAttempt while any
        // relationship between them still exists.
        Schema::table('payouts', function (Blueprint $table) {
            $table->foreignId('current_payout_attempt_id')->nullable()->after('status')
                ->constrained('payout_attempts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_payout_attempt_id');
        });

        Schema::dropIfExists('payout_attempts');
    }
};
