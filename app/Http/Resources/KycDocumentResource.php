<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycDocumentResource extends JsonResource
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
            'id'            => $this->id,
            'kyc_id'        => $this->kyc_id,
            // 'storage_disk'  => $this->storage_disk,
            'mime_type'     => $this->mime_type,
            'file_size'     => $this->file_size,
            'url'           => $this->url,
            'document_type' => DocumentTypeResource::make($this->whenLoaded('documentType')),
            'document_side' => DocumentSideResource::make($this->whenLoaded('documentSide')),
            'created_at'    => $this->created_at,
        ];
    }
}
