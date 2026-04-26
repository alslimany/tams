<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSufficientWalletBalance
{
    /**
     * Prevent booking attempts if the agency wallet balance is insufficient
     * for agencies that use master agency supply.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only check on booking store requests.
        if (! $request->isMethod('POST') || ! $request->routeIs('flights.store')) {
            return $next($request);
        }

        // Skip if tenant uses own airline credentials (they pay the airline directly).
        if (tenant() && tenant()->usesOwnAirlineCredentials()) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Determine the currency and estimated total from the request.
        $currency = $request->string('currency', 'LYD')->toString();
        $estimatedTotal = (float) $request->input('grand_total', 0);

        if ($estimatedTotal <= 0) {
            return $next($request);
        }

        $wallet = $user->getOrCreateCurrencyWallet($currency);
        $balance = (float) ($wallet->balance / 100);

        if ($balance < $estimatedTotal) {
            return back()->withErrors([
                'wallet' => __('Insufficient wallet balance. Available: :balance :currency, Required: :required :currency', [
                    'balance' => number_format($balance, 2),
                    'currency' => $currency,
                    'required' => number_format($estimatedTotal, 2),
                ]),
            ])->withInput();
        }

        return $next($request);
    }
}
