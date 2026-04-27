<?php

namespace App\Http\Controllers;

use App\Models\Airport;
use Illuminate\Http\Request;

class AirportController extends Controller
{
    public function search(Request $request)
    {
        $query = strtoupper(trim((string) $request->input('q', '')));
        $airports = [];
        $commonAirportCodes = ['MJI', 'TIP', 'BEN', 'SEB', 'IST', 'SAW', 'CAI', 'DXB', 'JED', 'TUN'];

        $baseQuery = Airport::query()
            ->whereNotNull('iata_code')
            ->where('iata_code', '!=', '');

        if ($query === '') {
            $sortIndex = array_flip($commonAirportCodes);

            $airports = $baseQuery
                ->whereIn('iata_code', $commonAirportCodes)
                ->get()
                ->sortBy(fn ($airport) => $sortIndex[$airport->iata_code] ?? PHP_INT_MAX)
                ->take(10)
                ->values();

            return response()->json($this->transformAirports($airports));
        }

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        if (strlen($query) == 3) {
            $airports = $baseQuery
                ->where(function ($searchQuery) use ($query) {
                    $searchQuery
                        ->where('iata_code', 'LIKE', $query.'%');
                })
                ->orderBy('iata_code', 'asc')
                ->limit(10)
                ->get();
        } else {

            $airports = $baseQuery
                ->where(function ($searchQuery) use ($query) {
                    $searchQuery
                        ->where('iata_code', 'LIKE', $query.'%')
                        ->orWhere('name->en', 'LIKE', '%'.$query.'%')
                        ->orWhere('name->ar', 'LIKE', '%'.$query.'%')
                        ->orWhere('city->en', 'LIKE', '%'.$query.'%')
                        ->orWhere('city->ar', 'LIKE', '%'.$query.'%')
                        ->orWhere('country->en', 'LIKE', '%'.$query.'%')
                        ->orWhere('country->ar', 'LIKE', '%'.$query.'%');
                })
                ->orderBy('iata_code', 'asc')
                ->limit(10)
                ->get();
        }

        return response()->json($this->transformAirports($airports));
    }

    private function transformAirports($airports)
    {
        return $airports->map(function ($airport) {
            return [
                'id' => $airport->id,
                'iata_code' => $airport->iata_code,
                'name' => $airport->name,
                'city' => $airport->city,
                'country' => $airport->country,
                'display_name' => ($airport->iata_code ? $airport->iata_code.' - ' : '').$airport->name.', '.$airport->city.', '.$airport->country,
            ];
        });
    }
}
