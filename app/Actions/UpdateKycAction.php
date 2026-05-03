<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\Kyc;
use App\Models\KycDocument;
use App\Models\KycStatus;
use Illuminate\Support\Facades\DB;

class UpdateKycAction
{
    use AsAction;

    public function __construct(
        private readonly StoreKycDocumentAction $storeDocument,
    ) {}

    /**
     * Update an existing KYC record and optionally add new documents.
     */
    public function execute(Kyc $kyc, array $data): Kyc
    {
        return DB::transaction(function () use ($kyc, $data) {
            // Update KYC details
            $kyc->update(array_filter([
                'full_name'     => $data['full_name'] ?? null,
                'gender_id'     => $data['gender_id'] ?? null,
                'gender_other'  => $data['gender_other'] ?? null,
                'country_id'    => $data['country_id'] ?? null,
                'state_id'      => $data['state_id'] ?? null,
                'city_id'       => $data['city_id'] ?? null,
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'postal_code'   => $data['postal_code'] ?? null,
            ], fn ($value) => $value !== null));

            if (!empty($data['documents'])) {
                foreach ($data['documents'] as $documentData) {
                    $this->storeDocument->execute($kyc, $documentData);
                }
 
                // Transition back to pending if rejected or expired
                if ($kyc->canBeResubmitted()) {
                    $pendingStatus = KycStatus::findBySlug('pending');
                    $kyc->transitionToStatus($pendingStatus);
                }
            }

            // event(new KycSubmitted($kyc)); // Trigger KYC updated event for notifications, etc.
            return $kyc->fresh();
        });
    }
}
