<?php

namespace App\Http\Requests\Vendor;

use App\Domain\Payments\PaymentMethodCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accepts exactly one client-controlled field: the payment method the
 * vendor picked. `provider`, `amount`, and `currency` are never read from
 * the request anywhere in this flow — App\Http\Controllers\Vendor\OrderPaymentController
 * resolves the provider server-side via PaymentMethodCatalog, and amount/currency
 * always come from the Order (see PaymentService::assertResultMatchesAttempt()).
 * Any of those fields sent by a client are simply ignored, not merely unused.
 */
class SelectPaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is checked by the controller (OrderPolicy) against the
        // route-model-bound Order — this request only shapes the payload.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::in(array_keys(app(PaymentMethodCatalog::class)->methods()))],
        ];
    }
}
