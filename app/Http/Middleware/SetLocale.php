<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        App::setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        // 1. Authenticated user locale
        if ($user = $request->user()) {
            return $user->locale ?? config('app.locale', 'en');
        }

        // 2. Session locale
        if ($request->session()->has('locale')) {
            return $request->session()->get('locale');
        }

        // 3. Browser locale
        $browserLocale = substr($request->getPreferredLanguage(), 0, 2);
        $supported = config('app.supported_locales', ['en', 'pt']);

        if (in_array($browserLocale, $supported)) {
            return $browserLocale;
        }

        // 4. App default
        return config('app.locale', 'en');
    }
}