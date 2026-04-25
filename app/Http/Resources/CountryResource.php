<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
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
            'name'        => $this->name,
            'iso2'        => $this->iso2,
            'iso3'        => $this->iso3,
            'phone_code'  => $this->phone_code,
            'region'      => $this->region,
            'subregion'   => $this->subregion,
            'status'      => $this->status,
            'flag_emoji'  => $this->flag_emoji,
        ];
    }
}
