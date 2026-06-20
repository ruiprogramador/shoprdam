<?php

namespace App\Actions;

use App\Traits\FileUploadTrait;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateProfileImage
{
    use AsObject, FileUploadTrait;

    public function handle(Model $user, UploadedFile $file): string
    {
        $oldImagePath = $user->getOriginal('image');

        $rawPath = $this->handleFileUpload(
            file: $file,
            directory: 'profile_images',
            disk: 'public'
        );

        if ($oldImagePath) {
            Storage::disk('public')->delete($oldImagePath);
        }

        return OptimizeImage::run($rawPath, 'profile_images');
    }
}