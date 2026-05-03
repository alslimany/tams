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
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        $roles = collect(explode(',', $role))
            ->map(fn (string $value): string => trim($value))
            ->filter();

        $authorized = $roles->contains(function (string $allowedRole) use ($user): bool {
            return match ($allowedRole) {
                'admin' => $user->isAdmin(),
                'manager' => $user->isManager(),
                'agent' => $user->isAgent(),
                default => false,
            };
        });

        if (! $authorized) {
            abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}
