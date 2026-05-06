<?php

namespace App\Providers;

use App\Translations\DatabaseLoader;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\TranslationServiceProvider as BaseTranslationServiceProvider;

class TranslationServiceProvider extends BaseTranslationServiceProvider
{
    protected function registerLoader(): void
    {
        $this->app->singleton('translation.loader', function ($app) {
            return new DatabaseLoader($app['files'], [
                $app['path.lang'],
            ]);
        });
    }
}
