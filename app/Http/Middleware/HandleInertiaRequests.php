<?php

namespace App\Http\Middleware;

use App\Models\Translation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $breadcrumbs = $this->generateBreadcrumbs($request);
        $locale      = App::getLocale();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
                'info'    => fn () => $request->session()->get('info'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            'breadcrumbs'  => $breadcrumbs,
            'locale'       => $locale,
            'translations' => fn () => Cache::remember(
                "translations.full.{$locale}",
                now()->addHour(),
                fn () => Translation::getFullForLocale($locale)
            ),
        ];
    }

    protected function generateBreadcrumbs(Request $request): array
    {
        $route = $request->route();

        if (!$route || !$route->getName()) {
            return [];
        }

        $routeName  = $route->getName();
        $segments   = explode('.', $routeName);
        $parameters = $route->parameters();

        $breadcrumbs = [];

        if ($routeName !== 'home' && $this->route_exists('home')) {
            $breadcrumbs[] = [
                'label' => 'Home',
                'url'   => route('home'),
            ];
        }

        $currentRoute = '';

        foreach ($segments as $segment) {
            $currentRoute .= ($currentRoute ? '.' : '') . $segment;

            $url = null;

            if ($this->route_exists($currentRoute)) {
                try {
                    $url = route($currentRoute, $parameters);
                } catch (\Throwable $e) {
                    $url = null;
                }
            }

            $breadcrumbs[] = [
                'label' => ucfirst(str_replace('-', ' ', $segment)),
                'url'   => $url,
            ];
        }

        return $breadcrumbs;
    }

    /**
     * Check if a route exists
     */
    protected function route_exists(string $name): bool
    {
        return \Illuminate\Support\Facades\Route::has($name);
    }
}