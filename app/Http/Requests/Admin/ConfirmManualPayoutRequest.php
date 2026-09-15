<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shapes an operator's manual-confirmation payload for
 * App\Http\Controllers\Admin\PayoutRecoveryController::confirm(). Never
 * accepts `provider_reference` — that technical identity always comes from
 * the route-bound PayoutAttempt itself (see the controller), never from
 * client input, closing off "confirm a different attempt than the one the
 * URL names" as an attack surface entirely.
 *
 * `external_transfer_reference` — the REAL SEPA/bank reference proving a
 * transfer happened — is required exactly when the outcome is `succeeded`:
 * a rejected transfer may have no such reference at all, but a claimed
 * success must always carry evidence (see PayoutAttempt's own docblock for
 * why this must never be confused with the technical provider_reference).
 */
class ConfirmManualPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership/eligibility is checked by the controller against
        // PayoutRecoveryPolicy — this request only shapes the payload.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::in(['succeeded', 'failed'])],
            'external_transfer_reference' => ['required_if:outcome,succeeded', 'nullable', 'string', 'max:100'],
            'amount' => ['required', 'regex:/^\d+\.\d{2}$/'],
            'currency' => ['required', 'string', 'size:3'],
            'executed_at' => ['required', 'date', 'before_or_equal:now'],
            'failure_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
