<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        // TEMPORARY DEBUG
        Log::info('=== BREADCRUMB DEBUG ===');
        Log::info('Route name: ' . ($request->route()?->getName() ?? 'NO ROUTE'));
        Log::info('Breadcrumbs generated: ' . json_encode($breadcrumbs));
        Log::info('========================');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'breadcrumbs' => $breadcrumbs,
            'test_value' => 'HELLO FROM MIDDLEWARE', // Test value
        ];
    }
    // protected function generateBreadcrumbs(Request $request)
    // {
    //     /* $segments = $request->segments();
    //     $breadcrumbs = [];
    //     $url = '';

    //     foreach ($segments as $segment) {
    //         $url .= '/' . $segment;
    //         $breadcrumbs[] = [
    //             'name' => ucfirst(str_replace('-', ' ', $segment)),
    //             'url' => $url,
    //         ];
    //     }

    //     return $breadcrumbs; */

    //     $route = $request->route();

    //     if (!$route || !method_exists($route, 'getName')) {
    //         return [];
    //     }

    //     /* $name = $route->getName();
    //     $segments = explode('.', $name);
    //     $breadcrumbs = [];
    //     $path = '';

    //     foreach ($segments as $segment) {
    //         $path .= ($path ? '.' : '') . $segment;
    //         $breadcrumbs[] = [
    //             'name' => ucfirst(str_replace('-', ' ', $segment)),
    //             'url' => route($path),
    //             'label' => $segment,
    //         ];
    //     }
    //     // dd($breadcrumbs);
    //     return $breadcrumbs; */

    //     $routeName = $route->getName();

    //     // Check if home route exists
    //     $homeUrl = $this->route_exists('home') ? route('home') : url('/');

    //     // Map of route names to breadcrumbs
    //     $breadcrumbMap = [
    //         'login' => [
    //             ['label' => 'Home', 'url' => $homeUrl],
    //             ['label' => 'Login', 'url' => null],
    //         ],
    //         'register' => [
    //             ['label' => 'Home', 'url' => $homeUrl],
    //             ['label' => 'Register', 'url' => null],
    //         ],
    //         'contact' => [
    //             ['label' => 'Home', 'url' => $homeUrl],
    //             ['label' => 'Contact Us', 'url' => null],
    //         ],
    //         'about' => [
    //             ['label' => 'Home', 'url' => $homeUrl],
    //             ['label' => 'About Us', 'url' => null],
    //         ],
    //         // Add more routes as needed
    //     ];

    //     // Return the breadcrumbs for this route, or empty array if not found
    //     return $breadcrumbMap[$routeName] ?? [];
    // }


    protected function generateBreadcrumbs(Request $request): array
    {
        $route = $request->route();

        if (!$route || !$route->getName()) {
            return [];
        }

        $routeName = $route->getName();
        $segments = explode('.', $routeName);

        $breadcrumbs = [];

        // ✅ Always add Home first (if not already on home)
        if ($routeName !== 'home' && $this->route_exists('home')) {
            $breadcrumbs[] = [
                'label' => 'Home',
                'url'   => route('home'),
            ];
        }

        $currentRoute = '';

        foreach ($segments as $segment) {
            $currentRoute .= ($currentRoute ? '.' : '') . $segment;

            $breadcrumbs[] = [
                'label' => ucfirst(str_replace('-', ' ', $segment)),
                'url'   => $this->route_exists($currentRoute) ? route($currentRoute) : null,
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
