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
        Schema::create('payment_provider_events', function (Blueprint $table) {
            $table->id();

            // Provider webhooks are at-least-once: this is what makes
            // storing an event idempotent regardless of redelivery count.
            $table->string('provider', 40);
            $table->string('provider_event_id');
            $table->string('event_type', 64);

            // Searchable regardless of whether a local attempt has claimed
            // this reference yet — see
            // App\Domain\Payments\Services\PaymentEventProcessor::replayUnmatchedEvents().
            $table->string('provider_reference');

            // Only the fields each provider's translator decided are needed
            // to replay the event later (see e.g.
            // App\Payments\Stripe\StripeEventTranslator) — never the raw
            // provider payload, so nothing sensitive (API secrets, a
            // PaymentIntent's client_secret, unrelated customer data) ends
            // up here.
            $table->json('payload');

            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('replay_attempts')->default(0);
            $table->text('last_replay_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);

            // Replay is always scoped to "pending events for this provider
            // reference".
            $table->index(['provider', 'provider_reference', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_provider_events');
    }
};
