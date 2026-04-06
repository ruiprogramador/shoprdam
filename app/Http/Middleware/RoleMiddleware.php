<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, /*$role*/ ...$roles): Response
    {
        /* if (!auth()->check() || strtolower(auth()->user()->userType->name) !== $role) {
            // dd("User does not have the required role: $role. Access denied.");
            // abort(403);
            if (!auth()->check()) {
                return redirect()->route('login');
            }
            return redirect()->route('home')->with('error', 'You do not have the required role to access this page.');
        } */

        if (!auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // Allow multiple roles
        if (!$user->hasAnyRole($roles)) {
            return redirect()
                ->route('home')
                ->with('error', 'You do not have permission to access this page.');
        }

        return $next($request);
    }
}
