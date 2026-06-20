<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait FileUploadTrait
{
    public function handleFileUpload(
        ?UploadedFile $file,
        ?string $oldPath = null,
        string $directory = 'uploads',
        string $disk = 'public',
        bool $deleteOldIfReplacing = true
    ): ?string {
        if (!$file || !$file->isValid()) {
            return null;
        }

        $filename = $file->hashName();
        $path = $directory . '/' . $filename;

        Storage::disk($disk)->put($path, file_get_contents($file));

        return $path; // 'profile_images/filename.jpg' — sem prefixo do disco
    }
}
