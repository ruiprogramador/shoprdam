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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // One Payment per Order: the single financial obligation an
            // Order can have. Deliberately has no amount/currency columns —
            // it always reads those from its Order relation, the same way
            // App\Payments\Stripe\StripePaymentProvider derives them fresh
            // on every attempt/retry today, rather than trusting a
            // snapshot that could drift from the Order's own values.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('status', 20)->default('pending');

            $table->timestamps();

            // `current_payment_attempt_id` is added by the
            // create_payment_attempts_table migration that follows this
            // one — it has to reference payment_attempts.id, which can't
            // exist yet here (the two tables reference each other).
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
