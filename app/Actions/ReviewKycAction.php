<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\Kyc;
use App\Models\KycStatus;
use App\Models\Admin;
use App\Models\KycHistory;
use Illuminate\Support\Facades\DB;
use Exception;

class ReviewKycAction
{
    use AsAction;

    /**
     * Review a KYC record by approving or rejecting it.
     *
     * @throws Exception
     */
    public function execute(Kyc $kyc, Admin $reviewer, string $action, ?string $notes = null): Kyc
    {
        $status = match ($action) {
            'approve' => KycStatus::findBySlug('approved'),
            'reject'  => KycStatus::findBySlug('rejected'),
            default   => throw new Exception("Invalid review action: {$action}"),
        };

        if (!$status) {
            throw new Exception("KYC status '{$action}' not found.");
        }

        $kyc->transitionToStatus($status, $reviewer, $notes);

        if ($action === 'approve') {
            $kyc->update(['expires_at' => now()->addYears(config('kyc.expiration_years', 1))]); // Set expiration date for approved KYC
            // event(new KycApproved($kyc)); // Trigger KYC approved event for notifications, etc.
        }else{
            // event(new KycRejected($kyc)); // Trigger KYC rejected event for notifications, etc.
        }

        return $kyc->fresh();
    }
}
