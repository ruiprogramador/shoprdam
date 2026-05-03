<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\Kyc;
use App\Models\KycDocument;
use Illuminate\Support\Facades\Storage;

class StoreKycDocumentAction
{
    use AsAction;

    /**
     * Store a single document file and create the KycDocument record.
     */
    public function execute(Kyc $kyc, array $documentData): void
    {
        $file = $documentData['file'];
        $disk = config('kyc.storage_disk', 'private');

        // Check if a document with the same type + side already exists
        $existing = $kyc->documents()
            ->where('document_type_id', $documentData['document_type_id'])
            ->where('document_side_id', $documentData['document_side_id'] ?? null)
            ->first();
 
        if ($existing) {
            $this->deleteDocument($existing);
        }

        $filePath = $file->store("kyc/{$kyc->id}/documents", $disk);

        $kyc->documents()->create([
            'document_type_id' => $documentData['document_type_id'],
            'document_side_id' => $documentData['document_side_id'] ?? null,
            'storage_disk'     => $disk,
            'storage_path'     => $filePath,
            'mime_type'        => $file->getMimeType(),
            'file_size'        => $file->getSize(),
        ]);
    }

    /**
     * Hard delete a document from storage and DB.
     */
    private function deleteDocument(KycDocument $document): void
    {
        if ($document->storage_disk && $document->storage_path) {
            Storage::disk($document->storage_disk)->delete($document->storage_path);
        }
 
        $document->forceDelete();
    }
}
