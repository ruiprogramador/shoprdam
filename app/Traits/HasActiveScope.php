<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasActiveScope
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}