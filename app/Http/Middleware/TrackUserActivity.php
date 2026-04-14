<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackUserActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip activity tracking for background/Inertia requests to prevent session race conditions
        // We only track on full page loads or standard navigation (not partial reloads)
        if ($request->header('X-Inertia-Partial-Data') || $request->is('api/*')) {
            return $next($request);
        }

        if (auth('web')->check()) {
            auth('web')->user()->update([
                'last_activity_at' => now(),
            ]);

            if (function_exists('tenant') && tenant()) {
                tenant()->update([
                    'last_activity_at' => now(),
                ]);
            }
        }

        return $next($request);
    }
}
