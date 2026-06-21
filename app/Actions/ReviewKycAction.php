<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\Kyc;
use App\Models\KycStatus;
use App\Models\Admin;
use App\Events\KycApproved;
use App\Events\KycRejected;

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
            $kyc->update(['expires_at' => now()->addYears((int)config('kyc.expiration_years', 1))]); // Set expiration date for approved KYC
        }

        match ($action) {
            'approve' => KycApproved::dispatch($kyc),
            'reject'  => KycRejected::dispatch($kyc),
        };

        return $kyc->fresh();
    }
}
