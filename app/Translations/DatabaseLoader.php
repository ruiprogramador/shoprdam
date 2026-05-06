<?php

namespace App\Translations;

use App\Models\Translation;
use Illuminate\Translation\FileLoader;
use Illuminate\Support\Facades\Cache;

class DatabaseLoader extends FileLoader
{
    /**
     * Load the messages for the given locale and group.
     * First loads from files (fallback), then overrides with DB translations.
     */
    public function load($locale, $group, $namespace = null): array
    {
        // Load file-based translations as fallback
        $fileTranslations = parent::load($locale, $group, $namespace);

        // Skip DB for vendor namespaced translations
        if ($namespace && $namespace !== '*') {
            return $fileTranslations;
        }

        // Load from DB with cache
        $dbTranslations = $this->loadFromDatabase($locale, $group);

        // DB translations override file translations
        return array_merge($fileTranslations, $dbTranslations);
    }

    private function loadFromDatabase(string $locale, string $group): array
    {
        $cacheKey = "translations.{$locale}.{$group}";

        return Cache::remember($cacheKey, now()->addHour(), function () use ($locale, $group) {
            return Translation::query()
                ->where('locale', $locale)
                ->where('group', $group)
                ->whereNotNull('text')
                ->pluck('text', 'key')
                ->toArray();
        });
    }
}