<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use App\Models\TranslationValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;
use Stichoza\GoogleTranslate\GoogleTranslate;
// Log
use Illuminate\Support\Facades\Log;

class TranslationController extends Controller
{
    public function index(Request $request): Response
    {
        $translations = QueryBuilder::for(Translation::class)
            ->with(['values.updater'])   // todas as locales, sem filtro
            ->allowedFilters(
                AllowedFilter::callback('group', fn ($query, $value) =>
                    $query->where('key', 'like', "{$value}.%")
                ),
                AllowedFilter::callback('search', fn ($query, $value) =>
                    $query->where(fn ($q) =>
                        $q->where('key', 'like', "%{$value}%")
                        ->orWhere('label', 'like', "%{$value}%")
                        ->orWhereHas('values', fn ($q2) =>
                            $q2->where('value', 'like', "%{$value}%")
                        )
                    )
                ),
            )
            ->allowedSorts(
                AllowedSort::field('key'),
                AllowedSort::field('updated_at'),
            )
            ->defaultSort('key')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return Inertia::render('Admin/Translations/Index', [
            'translations' => $translations,
            'locales'      => Translation::availableLocales(),
            'groups'       => Translation::availableGroups(),
            'filters'      => $request->only(['filter', 'sort', 'per_page']),
        ]);
    }

    /**
     * Devolve todos os valores (por locale) para uma dada key.
     */
    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'key' => ['required', 'string'],
        ]);

        $translation = Translation::where('key', $request->key)
            ->with(['values.updater'])
            ->firstOrFail();

        // Keyed por locale para o front-end
        $values = $translation->values->keyBy('locale');

        return response()->json([
            'translation' => $translation,
            'values'      => $values,
        ]);
    }

    /**
     * Cria ou actualiza o TranslationValue para uma locale específica.
     * O Translation (a key) deve já existir.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'key'         => ['required', 'string', 'max:255'],
            'label'       => ['nullable', 'string', 'max:255'],
            'locale'      => ['required', 'string', 'max:10'],
            'value_short' => ['nullable', 'string'],
            'value'       => ['required', 'string'],
            'value_long'  => ['nullable', 'string'],
            'value_html'  => ['nullable', 'string'],
            'status'      => ['nullable', 'in:missing,auto,done'],
        ]);

        $translation = Translation::firstOrCreate(
            ['key' => $request->key],
            ['label' => $request->input('label', $request->key)]
        );

        TranslationValue::updateOrCreate(
            [
                'translation_id' => $translation->id,
                'locale'         => $request->locale,
            ],
            [
                'value_short'   => $request->value_short,
                'value'         => $request->value,
                'value_long'    => $request->value_long,
                'value_html'    => $request->value_html,
                'status'        => $request->input('status', 'done'),
                'updated_by'    => auth('admin')->id(),
                'translated_at' => now(),
            ]
        );

        if ($request->boolean('is_last')) {
            
            #region auto-translation
            $values = Translation::values();

            // Tentamos descobrir qual foi a língua que tu preencheste (a nossa origem)
            $sourceValue = $values->first(fn($v) => !empty($v->value) && $v->status !== 'auto');

            // Se encontramos uma língua preenchida manualmente, vamos usá-la como base para traduzir as vazias
            if ($sourceValue) {
                foreach ($values as $targetValue) {
                    // Se esta língua específica estiver vazia ou marcada como "missing"
                    if (empty($targetValue->value) || $targetValue->status === 'missing') {
                        try {
                            // Configura o tradutor automático (Ex: de PT para EN)
                            $tr = new GoogleTranslate($targetValue->locale);
                            $tr->setSource($sourceValue->locale);

                            // Traduz o texto principal
                            $targetValue->value = $tr->translate($sourceValue->value);

                            // Traduz o Short se a origem tiver
                            if (!empty($sourceValue->value_short)) {
                                $targetValue->value_short = GoogleTranslate::trans($sourceValue->value_short, $targetValue->locale, $sourceValue->locale);
                            }

                            // Traduz o Long se a origem tiver
                            if (!empty($sourceValue->value_long)) {
                                $targetValue->value_long = GoogleTranslate::trans($sourceValue->value_long, $targetValue->locale, $sourceValue->locale);
                            }

                            // Mantém o HTML original (para não quebrar tags)
                            $targetValue->value_html = $sourceValue->value_html;
                            
                            // Marca o status como auto e guarda
                            $targetValue->status = 'auto';
                            $targetValue->updated_by = auth('admin')->id();
                            $targetValue->translated_at = now();
                            $targetValue->save();

                        }
                        #region se o Google falhar, não quero que isso quebre o processo de guardar a tradução manual 
                        catch (\Exception $e) {
                            Log::error("Auto-translation failed for TranslationValue ID {$targetValue->id}: " . $e->getMessage());
                            
                            // Opcional: marcar este valor como "auto" mesmo sem tradução, para tentar traduzir novamente no futuro
                            $targetValue->status = 'auto';
                            $targetValue->updated_by = auth('admin')->id();
                            $targetValue->translated_at = now();
                            $targetValue->save();
                        }
                        #endregion
                    }
                }
            }
            #endregion
            
            #region auto-clear cache para a key/locale
            $this->clearCache($translation);
            #endregion
            
            #region redireccionamento para a listagem com mensagem de sucesso
            return back()->with('success', 'Translation saved successfully.');
            #endregion
        }

        #region redireccionamento simples (sem mensagem)
        return back();
        #endregion
    }

    /**
     * Actualiza um TranslationValue directamente pelo seu id.
     */
    public function update(Request $request, TranslationValue $translationValue): RedirectResponse
    {
        $request->validate([
            'value_short' => ['nullable', 'string'],
            'value'       => ['required', 'string'],
            'value_long'  => ['nullable', 'string'],
            'value_html'  => ['nullable', 'string'],
            'status'      => ['nullable', 'in:missing,auto,done'],
        ]);

        $translationValue->update([
            'value_short'   => $request->value_short,
            'value'         => $request->value,
            'value_long'    => $request->value_long,
            'value_html'    => $request->value_html,
            'status'        => $request->input('status', 'done'),
            'updated_by'    => auth('admin')->id(),
            'translated_at' => now(),
        ]);

        $this->clearCache($translationValue->translation);

        if ($request->boolean('is_last')) {
            return back()->with('success', 'Translation saved successfully.');
        }

        return back();
    }

    /**
     * Apaga um TranslationValue (e o Translation pai se ficar sem valores).
     */
    public function destroy(Translation $translation): RedirectResponse
    {
        $this->clearCache($translation);
        $translation->delete(); // os values apagam-se em cascade (migration)

        return back()->with('success', 'Translation deleted successfully.');
    }

    private function clearCache(Translation $translation): void
    {
        $group = str($translation->key)->before('.');
        foreach (Translation::availableLocales() as $locale) {
            Cache::forget("translations.{$locale}.{$group}");
            Cache::forget("translations.{$locale}");
            Cache::forget("translations.full.{$locale}");
        }
    }
}