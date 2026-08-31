<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `payment_attempts.idempotency_key` is already deterministic and
     * attempt-scoped (`payment-{payment_id}-attempt-{attempt_id}` — see
     * PaymentService::createDurableAttempt()), so the application-level
     * collision risk is already effectively zero. This migration closes the
     * remaining gap: nothing at the database layer actually enforces it, so
     * a future bug in key generation (or a manual/backfilled row) could
     * silently collide. Deliberately scoped to `(provider, idempotency_key)`,
     * not a bare unique on `idempotency_key` — different providers may
     * legitimately mint keys from the same application-side shape
     * independently of one another; namespacing by provider is what
     * `payment_attempts.(provider, provider_reference)` and
     * `payment_provider_events.(provider, provider_event_id)` already do for
     * the same reason.
     *
     * Preflight check before adding the constraint: if any duplicate
     * `(provider, idempotency_key)` pair already exists, this migration
     * refuses to run rather than adding a constraint that fails
     * unpredictably against production data, or — worse — silently
     * deleting/merging PaymentAttempt rows to force it through. Financial
     * history is never auto-deduplicated by guessing; a real duplicate here
     * needs a human to look at the specific rows and decide what happened
     * before this constraint can be added safely.
     */
    public function up(): void
    {
        $duplicates = DB::table('payment_attempts')
            ->select('provider', 'idempotency_key', DB::raw('COUNT(*) as duplicate_count'))
            ->groupBy('provider', 'idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $examples = $duplicates->take(5)
                ->map(fn ($row) => "{$row->provider}/{$row->idempotency_key} (x{$row->duplicate_count})")
                ->implode(', ');

            throw new RuntimeException(
                'Cannot add UNIQUE(provider, idempotency_key) to payment_attempts: '.
                "{$duplicates->count()} duplicate (provider, idempotency_key) pair(s) already exist ".
                "(e.g. {$examples}). Resolve these rows manually — do NOT let this migration silently ".
                'delete or merge PaymentAttempts — then re-run this migration.'
            );
        }

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->unique(['provider', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropUnique(['provider', 'idempotency_key']);
        });
    }
};
