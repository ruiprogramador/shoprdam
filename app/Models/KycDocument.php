<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class KycDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'kyc_id',
        'document_type_id',
        'document_side_id',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    /**
     * Relations
     */
    public function kyc(): BelongsTo
    {
        return $this->belongsTo(Kyc::class, 'kyc_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function documentSide(): BelongsTo
    {
        return $this->belongsTo(DocumentSide::class, 'document_side_id');
    }

    /**
     * Helpers
     */
    public function getUrlAttribute(): ?string
    {
        if (!$this->storage_disk || !$this->storage_path) {
            return null;
        }

        $disk = Storage::disk($this->storage_disk);

        return method_exists($disk, 'temporaryUrl')
            ? $disk->temporaryUrl($this->storage_path, now()->addMinutes(15))
            : $disk->url($this->storage_path);
    }
}
