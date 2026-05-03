<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);

        return [
            'id'          => $this->id,
            'notes'       => $this->notes,
            /* 'from_status' => KycStatusResource::make($this->whenLoaded('fromStatus')),
            'to_status'   => KycStatusResource::make($this->whenLoaded('toStatus')), */

            'from_status' => $this->whenLoaded('fromStatus', fn () =>
                $this->fromStatus
                    ? KycStatusResource::make($this->fromStatus)->resolve()
                    : null
            ),
            'to_status' => $this->whenLoaded('toStatus', fn () =>
                KycStatusResource::make($this->toStatus)->resolve()
            ),
            'reviewer' => $this->whenLoaded('reviewer', fn () =>
                $this->reviewer ? [
                    'id'   => $this->reviewer->id,
                    'name' => $this->reviewer->name,
                ] : null
            ),
            'created_at'  => $this->created_at,
        ];
    }
}
