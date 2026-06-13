<?php

namespace App\Translations;

use App\Models\Translation;
use Illuminate\Translation\FileLoader;
use Illuminate\Support\Facades\Cache;

class DatabaseLoader extends FileLoader
{
    public function load($locale, $group, $namespace = null): array
    {
        $fileTranslations = parent::load($locale, $group, $namespace);

        if ($namespace && $namespace !== '*') {
            return $fileTranslations;
        }

        $dbTranslations = $this->loadFromDatabase($locale, $group);

        return array_merge($fileTranslations, $dbTranslations);
    }

    private function loadFromDatabase(string $locale, string $group): array
    {
        $cacheKey = "translations.{$locale}.{$group}";

        return Cache::remember($cacheKey, now()->addHour(), function () use ($locale, $group) {
            return Translation::with(['values' => fn ($q) => $q->where('locale', $locale)])
                ->where('key', 'like', "{$group}.%")
                ->get()
                ->mapWithKeys(function ($translation) use ($group) {
                    $subKey = substr($translation->key, strlen($group) + 1);
                    $value  = $translation->values->first()?->value;

                    return $value ? [$subKey => $value] : [];
                })
                ->toArray();
        });
    }
}