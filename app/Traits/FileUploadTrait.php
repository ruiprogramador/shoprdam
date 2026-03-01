<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait FileUploadTrait
{
    /**
     * Upload a file and return its stored path.
     */
    public function handleFileUpload(
        ?UploadedFile $file,
        ?string $oldPath = null,
        string $directory = 'uploads',
        string $disk = 'public',
        bool $deleteOldIfReplacing = true
    ): ?string {

        if (!$file) {
            return null;
        }
        if (!$file->isValid()) {
            return null;
        }

        // Store new file
        $newPath = $file->store($directory, $disk);

        // Delete old file if replacing
        if ($deleteOldIfReplacing && $oldPath) {
            Storage::disk($disk)->delete($oldPath);
        }

        return $newPath;
    }
}
