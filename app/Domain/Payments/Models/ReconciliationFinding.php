<?php

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\ReconciliationCategory;
use App\Domain\Payments\Enums\ReconciliationResolutionReason;
use App\Domain\Payments\Enums\ReconciliationSeverity;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bounded episode of provider-reconciliation evidence — see
 * docs/financial/RECONCILIATION.md §9/§10/§18 for the full design this
 * model implements exactly. Purely observational: nothing in this domain
 * ever derives a Wallet mutation or a Payment/PaymentAttempt/Order status
 * change from a row here — see
 * App\Domain\Payments\Services\ReconciliationFindingRepository (the only
 * class allowed to write to this table) and
 * tests/Architecture/ReconciliationNoFinancialMutationTest.
 *
 * `active_identity` is application-managed CAS/dedup state
 * (docs/financial/RECONCILIATION.md §9.1) — never set directly by calling
 * code outside ReconciliationFindingRepository.
 */
class ReconciliationFinding extends Model
{
    protected $table = 'payment_reconciliation_findings';

    protected $fillable = [
        'payment_attempt_id',
        'provider',
        'provider_reference',
        'active_identity',
        'category',
        'severity',
        'local_state',
        'remote_state',
        'local_amount_minor_units',
        'remote_amount_minor_units',
        'local_currency',
        'remote_currency',
        'local_correlation_id',
        'remote_correlation_id',
        'status',
        'first_observed_at',
        'last_observed_at',
        'observation_count',
        'resolved_at',
        'resolution_reason',
        'acknowledged_at',
        'acknowledged_by',
        'evidence',
    ];

    protected $casts = [
        'category' => ReconciliationCategory::class,
        'severity' => ReconciliationSeverity::class,
        'status' => ReconciliationStatus::class,
        'resolution_reason' => ReconciliationResolutionReason::class,
        'first_observed_at' => 'datetime',
        'last_observed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'evidence' => 'array',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'acknowledged_by');
    }
}
