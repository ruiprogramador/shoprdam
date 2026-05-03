<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\User;
use App\Models\Kyc;
use App\Models\KycStatus;
use Illuminate\Support\Facades\DB;
use App\Actions\StoreKycDocumentAction;

class StoreKycAction
{
    use AsAction;

    public function __construct(
        private readonly StoreKycDocumentAction $storeDocument,
    ) {}

    /**
     * Create a new KYC record with its documents.
     */
    public function execute(User $user, array $data): Kyc
    {
        return DB::transaction(function () use ($user, $data) {
            $pendingStatus = KycStatus::findBySlug('pending');
            $kyc = Kyc::create([
                'user_id'       => $user->id,
                'kyc_status_id' => $pendingStatus->id,
                'full_name'     => $data['full_name'],
                'date_of_birth' => $data['date_of_birth'],
                'gender_id'     => $data['gender_id'],
                'gender_other'  => $data['gender_other'] ?? null,
                'country_id'    => $data['country_id'],
                'state_id'      => $data['state_id'],
                'city_id'       => $data['city_id'],
                'address_line1' => $data['address_line1'],
                'address_line2' => $data['address_line2'] ?? null,
                'postal_code'   => $data['postal_code'],
            ]);

            // Create associated documents
            foreach ($data['documents'] as $docData) {
                // $this->storeDocument($kyc, $docData);
                $this->storeDocument->execute($kyc, $docData);
            }

            // event(new KycSubmitted($kyc)); // Trigger KYC submitted event for notifications, etc.
            return $kyc;
        });
    }
}
