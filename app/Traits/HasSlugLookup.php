<?php

namespace App\Traits;

trait HasSlugLookup
{
    public static function bySlug(string $slug): ?static
    {
        return static::query()
            ->where('slug', $slug)
            ->first();
    }

    public static function bySlugOrFail(string $slug): static
    {
        return static::query()
            ->where('slug', $slug)
            ->firstOrFail();
    }
}
