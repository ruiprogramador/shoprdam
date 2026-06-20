<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsObject;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OptimizeImage
{
    use AsObject;

    protected ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Otimiza a imagem original, guarda a versão .webp e remove o ficheiro antigo.
     */
    public function handle(string $originalPath, string $destinationFolder): string
    {
        if (!Storage::disk('public')->exists($originalPath)) {
            throw new \Exception("Ficheiro original não encontrado: {$originalPath}");
        }

        // 1. Abre o stream real do ficheiro
        $imageStream = Storage::disk('public')->readStream($originalPath);
        
        // 2. Descodifica usando o Intervention v4
        $image = $this->manager->decodeStream($imageStream);

        // 3. Gerar nome único para o ficheiro .webp
        $filename = Str::random(40) . '.webp';
        $optimizedPath = $destinationFolder . '/' . $filename;

        // 4. Redimensionar (max 1200px largura mantendo proporção) e Codificar para WebP (qualidade 100%)
        $image->scale(width: 1200);
        $encoded = $image->encodeUsingFileExtension('webp', quality: 100);

        // 5. Guardar a nova imagem otimizada no Storage
        Storage::disk('public')->put($optimizedPath, (string) $encoded);

        // 6. Fechar o stream explicitamente para libertar o ficheiro no Windows
        if (is_resource($imageStream)) {
            fclose($imageStream);
        }

        // 7. Remover a imagem original não-otimizada que o Trait guardou
        Storage::disk('public')->delete($originalPath);

        // Devolve apenas o caminho do novo ficheiro .webp
        return $optimizedPath;
    }
}