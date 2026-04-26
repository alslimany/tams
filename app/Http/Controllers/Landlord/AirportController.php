<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class AirportController extends Controller
{
    /**
     * Display a listing of airports with filtering.
     */
    public function index(Request $request): Response
    {
        $query = Airport::query();

        // Apply filters
        if ($request->filled('iata_code')) {
            $query->where('iata_code', 'like', '%'.$request->iata_code.'%');
        }

        if ($request->filled('country')) {
            $query->whereJsonContains('country->en', $request->country);
        }

        if ($request->filled('city')) {
            $query->whereJsonContains('city->en', $request->city);
        }

        $airports = $query->orderBy('iata_code')
            ->paginate(20)
            ->through(function ($airport) {
                return [
                    'id' => $airport->id,
                    'name' => $airport->getTranslations('name'),
                    'city' => $airport->getTranslations('city'),
                    'country' => $airport->getTranslations('country'),
                    'iata_code' => $airport->iata_code,
                    'icao_code' => $airport->icao_code,
                    'latitude' => $airport->latitude,
                    'longitude' => $airport->longitude,
                    'elevation_ft' => $airport->elevation_ft,
                    'type' => $airport->type,
                    'created_at' => $airport->created_at,
                    'updated_at' => $airport->updated_at,
                ];
            });

        return Inertia::render('Landlord/Airports/Index', [
            'airports' => $airports,
            'filters' => $request->only(['iata_code', 'country', 'city']),
        ]);
    }

    /**
     * Show the form for creating a new airport.
     */
    public function create(): Response
    {
        return Inertia::render('Landlord/Airports/Create');
    }

    /**
     * Store a newly created airport in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|array',
            'name.en' => 'required|string|max:255',
            'name.ar' => 'required|string|max:255',
            'name.fr' => 'required|string|max:255',
            'city' => 'required|array',
            'city.en' => 'required|string|max:255',
            'city.ar' => 'required|string|max:255',
            'city.fr' => 'required|string|max:255',
            'country' => 'required|array',
            'country.en' => 'required|string|max:255',
            'country.ar' => 'required|string|max:255',
            'country.fr' => 'required|string|max:255',
            'iata_code' => 'required|string|size:3|unique:airports,iata_code',
            'icao_code' => 'nullable|string|size:4|unique:airports,icao_code',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'elevation_ft' => 'nullable|integer',
            'type' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        Airport::create($request->only([
            'name', 'city', 'country', 'iata_code', 'icao_code',
            'latitude', 'longitude', 'elevation_ft', 'type',
        ]));

        return redirect()->route('landlord.airports.index')
            ->with('success', 'Airport created successfully.');
    }

    /**
     * Display the specified airport.
     */
    public function show(Airport $airport): Response
    {
        return Inertia::render('Landlord/Airports/Show', [
            'airport' => [
                'id' => $airport->id,
                'name' => $airport->getTranslations('name'),
                'city' => $airport->getTranslations('city'),
                'country' => $airport->getTranslations('country'),
                'iata_code' => $airport->iata_code,
                'icao_code' => $airport->icao_code,
                'latitude' => $airport->latitude,
                'longitude' => $airport->longitude,
                'elevation_ft' => $airport->elevation_ft,
                'type' => $airport->type,
                'data' => $airport->data,
                'created_at' => $airport->created_at,
                'updated_at' => $airport->updated_at,
            ],
        ]);
    }

    /**
     * Show the form for editing the specified airport.
     */
    public function edit(Airport $airport): Response
    {
        return Inertia::render('Landlord/Airports/Edit', [
            'airport' => [
                'id' => $airport->id,
                'name' => $airport->getTranslations('name'),
                'city' => $airport->getTranslations('city'),
                'country' => $airport->getTranslations('country'),
                'iata_code' => $airport->iata_code,
                'icao_code' => $airport->icao_code,
                'latitude' => $airport->latitude,
                'longitude' => $airport->longitude,
                'elevation_ft' => $airport->elevation_ft,
                'type' => $airport->type,
                'data' => $airport->data,
            ],
        ]);
    }

    /**
     * Update the specified airport in storage.
     */
    public function update(Request $request, Airport $airport)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|array',
            'name.en' => 'required|string|max:255',
            'name.ar' => 'required|string|max:255',
            'name.fr' => 'required|string|max:255',
            'city' => 'required|array',
            'city.en' => 'required|string|max:255',
            'city.ar' => 'required|string|max:255',
            'city.fr' => 'required|string|max:255',
            'country' => 'required|array',
            'country.en' => 'required|string|max:255',
            'country.ar' => 'required|string|max:255',
            'country.fr' => 'required|string|max:255',
            'iata_code' => 'required|string|size:3|unique:airports,iata_code,'.$airport->id,
            'icao_code' => 'nullable|string|size:4|unique:airports,icao_code,'.$airport->id,
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'elevation_ft' => 'nullable|integer',
            'type' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $airport->update($request->only([
            'name', 'city', 'country', 'iata_code', 'icao_code',
            'latitude', 'longitude', 'elevation_ft', 'type',
        ]));

        return redirect()->route('landlord.airports.index')
            ->with('success', 'Airport updated successfully.');
    }

    /**
     * Remove the specified airport from storage.
     */
    public function destroy(Airport $airport)
    {
        $airport->delete();

        return redirect()->route('landlord.airports.index')
            ->with('success', 'Airport deleted successfully.');
    }
}
