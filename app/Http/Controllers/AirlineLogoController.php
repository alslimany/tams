<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class AirlineLogoController extends Controller
{
    /**
     * Fetch, cache, and serve an airline logo.
     */
    public function show($code, Request $request)
    {
        $code = strtoupper($code);

        // Force mapping from IATA (2-char) to ICAO (3-char) because Logostream requires ICAO
        if (strlen($code) === 2) {
            $iataToIcao = [
                'YI' => 'OYA',
                'BM' => 'MNS',
                'UZ' => 'BRQ',
                'YL' => 'LWA',
                'NB' => 'BNL',
                '5S' => 'GAK',
                'FQ' => 'CWN',
            ];

            if (array_key_exists($code, $iataToIcao)) {
                $code = $iataToIcao[$code];
            }
        }

        $variant = $request->query('variant', 'logo');
        $radius = $request->query('radius');

        // Sanitize variant input to prevent path traversal
        if (!in_array($variant, ['default', 'icon', 'tail', 'icon-transparent', 'icon-white', 'logo-bg-white', 'icon-transparent'])) {
            $variant = 'default';
        }

        // Generate filename
        $filename = "{$variant}";
        if ($radius) {
            $filename .= "_r{$radius}";
        }
        $filename .= '.png';

        $storagePath = "airlines/logos/{$code}/{$filename}";

        // Check if cached locally
        if (!Storage::disk('public')->exists($storagePath)) {
            // Fetch from Logostream API
            $apiKey = env('AIRLINE_LOGO_API_KEY');

            if (!$apiKey) {
                abort(500, 'Airline Logo API Key not configured.');
            }

            $queryParams = [
                'key' => $apiKey,
                'variant' => $variant,
            ];

            if ($radius) {
                $queryParams['radius'] = $radius;
            }

            // Always use icao for the Logostream API
            $response = Http::get("https://airlines-api.logostream.dev/airlines/icao/{$code}", $queryParams);

            if ($response->successful()) {
                Storage::disk('public')->put($storagePath, $response->body());
            }
            else {
                abort($response->status(), 'Airline logo not found or API error.');
            }
        }

        return response()->file(Storage::disk('public')->path($storagePath), [
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }
}