<?php

namespace App\Http\Resources;

use App\Models\Kyc;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        return array(
            'id'                    => $this->id,
            'full_name'             => $this->full_name,
            'date_of_birth'         => $this->date_of_birth->format('Y-m-d'),
            'gender_id'             => $this->gender_id,
            'gender_other'          => $this->gender_other,
            'address_line1'         => $this->address_line1,
            'address_line2'         => $this->address_line2,
            'postal_code'           => $this->postal_code,
            'rejection_reason'      => $this->rejection_reason,
            'reviewed_at'           => $this->reviewed_at,
            'expires_at'            => $this->expires_at,
            'created_at'            => $this->created_at,
            'updated_at'            => $this->updated_at,

            /**
             * State Flags
             */
            'is_expired'            => $this->isExpired(),
            'is_expiring_soon'      => $this->isExpiringSoon(),
            'can_be_reviewed'       => $this->canBeReviewed(),
            'can_be_approved'       => $this->canBeApproved(),
            'can_be_rejected'       => $this->canBeRejected(),
            'can_be_resubmitted'    => $this->canBeResubmitted(),

            /**
             * Relationships
             */
            'status'    => KycStatusResource::make($this->whenLoaded('kycStatus')),
            'country'   => CountryResource::make($this->whenLoaded('country')),
            'state'     => StateResource::make($this->whenLoaded('state')),
            'city'      => CityResource::make($this->whenLoaded('city')),
            'gender'    => GenderResource::make($this->whenLoaded('gender')),
            'documents' => KycDocumentResource::collection($this->whenLoaded('documents')),
            'history'   => KycHistoryResource::collection($this->whenLoaded('history')),
            'user'      => $this->whenLoaded('user', fn () => [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ]),
            'reviewer' => $this->whenLoaded('reviewer', fn () => [
                'id'   => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ]),

        );
    }
}
