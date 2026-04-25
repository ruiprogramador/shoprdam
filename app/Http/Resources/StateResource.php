<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StateResource extends JsonResource
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
            'id'           => $this->id,
            'name'         => $this->name,
            'country_id'   => $this->country_id,
            'country_code' => $this->country_code,
            'country'      => CountryResource::make($this->whenLoaded('country')),
        ];
    }
}
