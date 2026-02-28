<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;

trait FileUploadTrait
{
    /**
     * Upload a file and return its stored path.
     */
    public function uploadFile(
        UploadedFile $file,
        string $directory = 'uploads',
        string $disk = 'public'
    ): ?string {
        if (! $file->isValid()) {
            return null;
        }

        return $file->store($directory, $disk);
    }
}
