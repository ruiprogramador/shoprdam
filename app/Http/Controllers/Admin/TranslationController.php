<?php

namespace App\Http\Controllers\Admin;

 
use App\Http\Controllers\Controller;
use App\Models\Translation;
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
    /**
     * Display all translations.
     */
    public function index(Request $request): Response
    {
        $defaultLocale = auth('admin')->user()->locale ?? 'en';

        $translations = QueryBuilder::for(Translation::class)
            ->with(['creator', 'updater'])
            ->where('locale', request('filter.locale', $defaultLocale))
            ->allowedFilters(
                AllowedFilter::exact('locale'),
                AllowedFilter::exact('group'),
                AllowedFilter::callback('search', fn ($query, $value) =>
                    $query->where(fn ($q) =>
                        $q->where('key', 'like', "%{$value}%")
                        ->orWhere('text_short', 'like', "%{$value}%")
                        ->orWhere('text', 'like', "%{$value}%")
                    )
                ),
            )
            ->allowedSorts(
                AllowedSort::field('group'),
                AllowedSort::field('key'),
                AllowedSort::field('updated_at'),
            )
            ->defaultSort('group')
            ->paginate(request('per_page', 25))
            ->withQueryString();
 
        return Inertia::render('Admin/Translations/Index', [
            'translations' => $translations,
            'locales'      => Translation::availableLocales(),
            'groups'       => Translation::availableGroups(),
            'filters'      => request()->only(['filter', 'sort', 'per_page']),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'group' => ['required', 'string'],
            'key'   => ['required', 'string'],
        ]);
 
        $translations = Translation::query()
            ->where('group', $request->group)
            ->where('key', $request->key)
            ->with(['creator', 'updater'])
            ->get()
            ->keyBy('locale');
 
        return response()->json($translations);
    }

    /**
     * Store a new translation.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'locale'     => ['required', 'string', 'max:10'],
            'group'      => ['required', 'string', 'max:255'],
            'key'        => ['required', 'string', 'max:255'],
            'text_short' => ['nullable', 'string'],
            'text'       => ['required', 'string'],
            'text_long'  => ['nullable', 'string'],
            'text_html'  => ['nullable', 'string'],
        ]);

        Translation::updateOrCreate(
            [
                'locale' => $request->locale,
                'group'  => $request->group,
                'key'    => $request->key,
            ],
            [
                'text_short' => $request->text_short,
                'text'       => $request->text,
                'text_long'  => $request->text_long,
                'text_html'  => $request->text_html,
                'created_by' => auth('admin')->id(),
                'updated_by' => auth('admin')->id(),
            ]
        );

        $this->clearCache($request->locale, $request->group);

        return back()->with('success', 'Translation created successfully.');
    }

    /**
     * Update a translation.
     */
    public function update(Request $request, Translation $translation): RedirectResponse
    {
        $request->validate([
            'text_short' => ['nullable', 'string'],
            'text'       => ['required', 'string'],
            'text_long'  => ['nullable', 'string'],
            'text_html'  => ['nullable', 'string'],
        ]);

        $translation->update([
            'text_short' => $request->text_short,
            'text'       => $request->text,
            'text_long'  => $request->text_long,
            'text_html'  => $request->text_html,
            'updated_by' => auth('admin')->id(),
        ]);

        $this->clearCache($translation->locale, $translation->group);

        return back()->with('success', 'Translation updated successfully.');
    }

    /**
     * Delete a translation.
     */
    public function destroy(Translation $translation): RedirectResponse
    {
        $translation->update(['deleted_by' => auth('admin')->id()]);
        $translation->delete();

        $this->clearCache($translation->locale, $translation->group);

        return back()->with('success', 'Translation deleted successfully.');
    }

    private function clearCache(string $locale, string $group): void
    {
        Cache::forget("translations.{$locale}.{$group}");
        Cache::forget("translations.{$locale}");
    }
}