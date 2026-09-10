<?php

namespace App\Policies;

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Models\Admin;

/**
 * There is only one Admin type in this application (see App\Models\Admin —
 * no role/permission column), so "authorized" here means "an authenticated
 * admin", the same model App\Policies\AdminKycPolicy already uses. What
 * actually gates the mutating action is never the admin's identity — it's
 * whether $attempt is in a state App\Http\Controllers\Admin\PaymentRecoveryController
 * (and, underneath it, App\Domain\Payments\Services\PaymentAttemptRecoveryService /
 * PaymentService::finalizeAttempt()) can safely act on at all. A terminal
 * attempt (Succeeded/Failed) or a NeedsAttention one with no provider
 * claim can never pass retry() — see the controller's own docblock for why
 * those are deliberately dead ends here, not bugs.
 */
class PaymentRecoveryPolicy
{
    public function view(Admin $admin, PaymentAttempt $attempt): bool
    {
        return true;
    }

    /** Whether *some* safe recovery action exists for $attempt right now — the controller decides which. */
    public function retry(Admin $admin, PaymentAttempt $attempt): bool
    {
        return $attempt->status === PaymentAttemptStatus::Pending
            || $attempt->provider_reference !== null;
    }
}
