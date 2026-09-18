<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of feat/financial-reconciliation — see
 * docs/financial/RECONCILIATION.md §18 for the full design rationale this
 * schema implements exactly. Additive only: no existing table, column, or
 * constraint is touched.
 *
 * A row is a bounded *episode*: the continuous span from when a mismatch
 * between local Payments-domain state and a provider's own canonical
 * retrieval result was first observed until it either resolved or a new,
 * unrelated episode later opens for the same (provider, provider_reference).
 * See App\Domain\Payments\Models\ReconciliationFinding and
 * App\Domain\Payments\Services\ReconciliationFindingRepository.
 *
 * This table is observational/audit/triage state only — never a financial
 * ledger, never a settlement queue, never a provider-event inbox (that's
 * `payment_provider_events`). Nothing in this domain ever derives a Wallet
 * mutation or a Payment/PaymentAttempt/Order status change from a row here;
 * see tests/Architecture/ReconciliationNoFinancialMutationTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_findings', function (Blueprint $table) {
            $table->id();

            // Nullable: Phase 1 (Direction 1, local -> provider) always
            // starts from a known local attempt, so this is never null in
            // Phase 1's own writes. Left nullable so a future Direction-2
            // phase (provider -> local, a provider reference with no local
            // attempt at all) doesn't need a breaking schema change — see
            // docs/financial/RECONCILIATION.md §18. restrictOnDelete(),
            // never cascadeOnDelete()/nullOnDelete(): financial-adjacent
            // audit evidence must never silently disappear or lose its own
            // subject — mirrors payment_recovery_actions.payment_attempt_id
            // exactly (see database/migrations/2026_09_08_100000_create_payment_recovery_actions_table.php).
            $table->foreignId('payment_attempt_id')->nullable()
                ->constrained('payment_attempts')->restrictOnDelete();

            $table->string('provider', 40);
            $table->string('provider_reference');

            // The entire CAS/dedup mechanism (docs/financial/RECONCILIATION.md
            // §9.1): "{provider}:{provider_reference}" while the episode is
            // open, NULL once resolved — the exact same "nullable column
            // under a unique index" idiom payment_attempts.provider_reference
            // already relies on (every driver treats multiple NULLs as
            // distinct under a unique index), applied here so any number of
            // *resolved* historical episodes for the same reference can
            // coexist while the database itself refuses a second
            // simultaneously-open one. Portable across SQLite/MySQL/PostgreSQL,
            // unlike a native partial unique index (MySQL has none).
            $table->string('active_identity')->nullable()->unique();

            // One of App\Domain\Payments\Enums\ReconciliationCategory's
            // finite values — mutable while the episode stays open (refined
            // evidence about one ongoing incident), frozen once resolved.
            $table->string('category', 48);
            $table->string('severity', 20);

            // The local PaymentAttemptStatus / provider's own raw status
            // string, as observed — never coerced into each other's
            // vocabulary.
            $table->string('local_state', 40)->nullable();
            $table->string('remote_state', 40)->nullable();

            // Integer minor units, matching ProviderPaymentResult exactly —
            // never a float, matching this domain's money-handling rule end
            // to end.
            $table->unsignedBigInteger('local_amount_minor_units')->nullable();
            $table->unsignedBigInteger('remote_amount_minor_units')->nullable();
            $table->string('local_currency', 3)->nullable();
            $table->string('remote_currency', 3)->nullable();
            $table->string('local_correlation_id')->nullable();
            $table->string('remote_correlation_id')->nullable();

            // open|resolved only — see App\Domain\Payments\Enums\ReconciliationStatus.
            // No `actionable` column: Phase 1 has no concept for it to
            // express (docs/financial/RECONCILIATION.md §10/§14). No
            // `locked_until` column: no correctness invariant depends on a
            // lease at the finding-row level (§11.1) — unique(active_identity)
            // already owns the one guarantee that matters, and duplicate
            // provider GETs are read-only and idempotent.
            $table->string('status', 20);

            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');
            $table->unsignedInteger('observation_count')->default(1);

            $table->timestamp('resolved_at')->nullable();
            // The single value Phase 1 code ever writes
            // (App\Domain\Payments\Enums\ReconciliationResolutionReason) — no
            // second value reserved against an undesigned future phase.
            $table->string('resolution_reason', 40)->nullable();

            // Independent human-attention axis (§10) — never changes
            // `status`/`resolved_at`. nullOnDelete(): an admin account being
            // deleted later must never delete the fact that *someone*
            // acknowledged this, mirrors payment_recovery_actions.admin_id
            // exactly.
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()
                ->constrained('admins')->nullOnDelete();

            // Small, bounded, already-sanitized supplementary fields only —
            // never a raw provider payload or secret, mirroring
            // payment_provider_events.payload's own allow-listed philosophy.
            $table->json('evidence')->nullable();

            $table->timestamps();

            // The only query pattern Phase 1 actually has: "open episodes,
            // ordered by age."
            $table->index(['status', 'created_at']);

            // Historical episodes for the same reference are expected and
            // must stay queryable together — deliberately non-unique
            // (unique(active_identity) above is the uniqueness mechanism).
            $table->index(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_findings');
    }
};
