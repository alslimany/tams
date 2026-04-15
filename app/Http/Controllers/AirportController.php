<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Airport;

class AirportController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->input('q');
        
        if (empty($query)) {
            return response()->json([]);
        }

        $queryLength = strlen($query);

        if ($queryLength < 3) {
            return response()->json([]);
        }

        // Search by name, city, country, or iata/icao code, but exclude any without IATA code
        $airports = Airport::whereNotNull('iata_code')
            ->where('iata_code', '!=', '')
            ->where(function ($q) use ($query, $queryLength) {
                if ($queryLength === 3) {
                    // Exactly 3 chars: filter by IATA only
                    $q->where('iata_code', 'LIKE', $query . '%');
                } else {
                    // More than 3 chars: search with iata and the other filters
                    $q->where('iata_code', 'LIKE', $query . '%')
                        ->orWhere('name->en', 'LIKE', '%' . $query . '%')
                        ->orWhere('name->ar', 'LIKE', '%' . $query . '%')
                        ->orWhere('city->en', 'LIKE', '%' . $query . '%')
                        ->orWhere('city->ar', 'LIKE', '%' . $query . '%')
                        ->orWhere('country->en', 'LIKE', '%' . $query . '%')
                        ->orWhere('country->ar', 'LIKE', '%' . $query . '%');
                }
            })
            ->orderBy('iata_code', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($airport) {
                // Ensure name is returned based on current app locale, default to en
                return [
                    'id' => $airport->id,
                    'iata_code' => $airport->iata_code,
                    'name' => $airport->name,
                    'city' => $airport->city,
                    'country' => $airport->country,
                    'display_name' => ($airport->iata_code ? $airport->iata_code . ' - ' : '') . $airport->name . ', ' . $airport->city . ', ' . $airport->country
                ];
            });

        return response()->json($airports);
    }
}
