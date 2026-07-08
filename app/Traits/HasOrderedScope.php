<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasOrderedScope
{
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}