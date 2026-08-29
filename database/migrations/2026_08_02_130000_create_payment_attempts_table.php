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
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();

            // Many attempts per Payment over time (retry with a different
            // provider/method after a terminal failure) — deliberately not
            // unique. What *is* enforced is that at most one is
            // non-terminal at a time, via payments.current_payment_attempt_id
            // (set/cleared under a row lock — see
            // App\Domain\Payments\Services\PaymentService::startOrResumeAttempt()),
            // not a column constraint here.
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 40);
            $table->string('method', 40);

            // Populated only after the provider responds — null while the
            // durable pre-call row exists but the provider hasn't been
            // reached yet (or that's still unknown, e.g. after a crash).
            $table->string('provider_reference')->nullable();

            $table->string('idempotency_key');
            $table->string('status', 20)->default('pending');

            // Reconciliation lease, deliberately its own column rather than
            // a status value ("recovering") — the attempt's payment-lifecycle
            // status and its reconciliation-ownership state are different
            // concerns (see docs/wallet/integrations.md). Null when no
            // worker currently owns recovering this attempt.
            $table->timestamp('locked_until')->nullable();

            $table->unsignedInteger('recovery_attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->text('last_recovery_error')->nullable();

            $table->timestamps();

            // The final authority on "which attempt owns this provider
            // reference" — mirrors store_wallet_transactions.external_ref_idx
            // (external_provider, external_reference), now with the same
            // provider-namespacing from day one. MySQL/PostgreSQL both treat
            // multiple NULLs as distinct under a unique index, so any number
            // of not-yet-claimed attempts (provider_reference still null)
            // may coexist.
            $table->unique(['provider', 'provider_reference']);

            // The reconciler's candidate query filters pending attempts by
            // (status, created_at) and leased-but-stale attempts by
            // (status, locked_until).
            $table->index(['status', 'created_at']);
            $table->index(['status', 'locked_until']);
        });

        // payments.current_payment_attempt_id has to be added here, after
        // payment_attempts exists — the two tables reference each other.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('current_payment_attempt_id')->nullable()->after('status')
                ->constrained('payment_attempts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_payment_attempt_id');
        });

        Schema::dropIfExists('payment_attempts');
    }
};
