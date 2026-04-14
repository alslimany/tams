<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTenantOperationalStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (function_exists('tenant') && tenant()) {
            $status = tenant()->status ?? 'active';

            if ($status !== 'active') {
                auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                $message = match ($status) {
                    'frozen' => 'Your agency account is currently frozen. Please contact the platform administrator.',
                    'suspended' => 'Your agency account is currently suspended. Please contact the platform administrator.',
                    default => 'Your agency account is not currently available.',
                };

                return redirect()->route('login')->withErrors([
                    'email' => $message,
                ]);
            }
        }

        return $next($request);
    }
}
