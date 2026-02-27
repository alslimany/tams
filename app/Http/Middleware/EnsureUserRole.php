<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $role
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        $authorized = match ($role) {
            'admin' => $user->isAdmin(),
            'manager' => $user->isManager(),
            'agent' => $user->isAgent(),
            default => false,
        };

        if (!$authorized) {
            abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}
