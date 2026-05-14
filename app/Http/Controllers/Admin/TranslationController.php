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

        $this->clearCache($request->locale, $request->key);

        if ($request->boolean('is_last')) {
            return back()->with('success', 'Translation saved successfully.');
        }

        return back();
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

        $this->clearCache(
            $translationValue->locale,
            $translationValue->translation->key
        );

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