<?php

namespace App\Jobs;

use App\Models\Translation;
use App\Models\TranslationValue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Illuminate\Support\Facades\Log;

class TranslateLanguages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; 

    protected int $translationId;
    protected int $adminId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $translationId, int $adminId)
    {
        $this->translationId = $translationId;
        $this->adminId = $adminId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Job Iniciado para a Translation ID: {$this->translationId}");

        $translation = Translation::find($this->translationId);
        if (!$translation) {
            Log::error("Job Abortado: A Translation mãe ID {$this->translationId} não foi encontrada na BD.");
            return;
        }

        $existingValues = $translation->values()->get();

        // LOG 2
        Log::info("Linhas encontradas na BD para esta tradução: " . $existingValues->pluck('locale')->implode(', '));

        $sourceValue = $existingValues->first(fn($v) => !empty($v->value) && $v->status !== 'auto');

        if (!$sourceValue) {
            Log::warning("Job abortado: Nenhuma língua de origem manual encontrada para a Translation ID: {$this->translationId}. Valores existentes: " . $existingValues->pluck('locale')->implode(', '));
            return;
        }

        Log::info("Língua de origem detetada com sucesso: {$sourceValue->locale}. A iniciar loop de tradução...");

        $allSystemLocales = Translation::testLocales();

        foreach ($allSystemLocales as $locale) {
            // Saltamos as línguas de origem para não traduzir PT para PT ou EN para EN
            if ($locale === $sourceValue->locale) {
                continue;
            }

            $targetValue = $existingValues->firstWhere('locale', $locale);

            if (!$targetValue) {
                $targetValue = new TranslationValue([
                    'translation_id' => $translation->id,
                    'locale'         => $locale,
                    'status'         => 'missing'
                ]);
            }

            if (empty($targetValue->value) || $targetValue->status === 'missing') {
                try {
                    $tr = new GoogleTranslate();
                    $tr->setSource($sourceValue->locale);
                    $tr->setTarget($locale);

                    // USANDO REFLECTION PARA FORÇAR O VERIFY => FALSE NO CLIENTE INTERNO DO GUZZLE
                    try {
                        $reflection = new \ReflectionClass($tr);
                        $clientProp = $reflection->getProperty('client');
                        $clientProp->setAccessible(true);
                        $internalClient = $clientProp->getValue($tr);

                        if ($internalClient instanceof \GuzzleHttp\Client) {
                            $configProp = (new \ReflectionClass($internalClient))->getProperty('config');
                            $configProp->setAccessible(true);
                            $config = $configProp->getValue($internalClient);
                            $config['verify'] = false;
                            $configProp->setValue($internalClient, $config);
                        }
                    } catch (\Exception $refException) {
                        // Se a reflection falhar por algum motivo, deixa o log mas não para o Job
                        Log::warning("Não foi possível injetar o bypass do SSL via Reflection: " . $refException->getMessage());
                    }

                    // Traduz o texto principal
                    $targetValue->value = $tr->translate($sourceValue->value);

                    // Traduz o Short se a origem tiver
                    if (!empty($sourceValue->value_short)) {
                        $targetValue->value_short = $tr->translate($sourceValue->value_short);
                    } else {
                        $targetValue->value_short = null;
                    }

                    // Traduz o Long se a origem tiver
                    if (!empty($sourceValue->value_long)) {
                        $targetValue->value_long = $tr->translate($sourceValue->value_long);
                    } else {
                        $targetValue->value_long = null;
                    }

                    $targetValue->value_html = $sourceValue->value_html;
                    $targetValue->status = 'auto';
                    $targetValue->updated_by = $this->adminId;
                    $targetValue->translated_at = now();
                    $targetValue->save();

                    // Pequena pausa para segurança
                    usleep(500000); 

                } catch (\Exception $e) {
                    Log::error("Falha real no Google Translate para o idioma {$locale}: " . $e->getMessage());
                    
                    $targetValue->status = 'missing';
                    $targetValue->updated_by = $this->adminId;
                    $targetValue->save();

                    continue; 
                }
            }
        }

        // Toca no model para limpar caches associadas através de Observers se necessário
        $translation->touch(); 
    }
}