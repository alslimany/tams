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
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Set locale from session, fallback to app default
        $locale = session('locale', config('app.locale', 'en'));

        // Ensure the locale is supported
        $supportedLocales = ['en', 'ar', 'fr'];
        if (! in_array($locale, $supportedLocales)) {
            $locale = config('app.locale', 'en');
        }

        App::setLocale($locale);

        // Set Carbon locale for dates if needed
        // Carbon::setLocale($locale);

        return $next($request);
    }
}
