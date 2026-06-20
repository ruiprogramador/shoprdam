<?php

namespace App\Jobs;

use App\Actions\OptimizeImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ProcessImageOptimization implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Model $model;
    public string $attribute;
    public string $folder;

    public function __construct(Model $model, string $attribute, string $folder)
    {
        $this->model = $model;
        $this->attribute = $attribute; // 'image'
        $this->folder = $folder;       // 'profile_images'
    }

    public function handle(): void
    {
        $currentPath = $this->model->{$this->attribute};

        if (!$currentPath) return;

        try {
            $newOptimizedPath = OptimizeImage::run($currentPath, $this->folder);

            $this->model->update([
                $this->attribute => $newOptimizedPath
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao otimizar imagem: ' . $e->getMessage());
            throw $e; // Horizon vai fazer retry
        }
    }
}