<?php

use App\Services\Finance\RouteInternationalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Insert minimal airport rows into the central airports table for testing.
 *
 * @param  array<string, string>  $airports  ['IATA_CODE' => 'ISO2_COUNTRY']
 */
function insertTestAirports(string $connection, array $airports): void
{
    foreach ($airports as $iata => $countryIso) {
        DB::connection($connection)->table('airports')->insert([
            'iata_code' => $iata,
            'name' => json_encode(['en' => $iata]),
            'city' => json_encode(['en' => $iata]),
            'country' => json_encode(['en' => $countryIso]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

test('it returns true when route countries are different', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    insertTestAirports($connection, ['MJI' => 'LY', 'IST' => 'TR']);

    $service = app(RouteInternationalService::class);

    expect($service->isInternational('MJI', 'IST'))->toBeTrue();
});

test('it returns false when route countries are the same', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    insertTestAirports($connection, ['MJI' => 'LY', 'TIP' => 'LY']);

    $service = app(RouteInternationalService::class);

    expect($service->isInternational('MJI', 'TIP'))->toBeFalse();
});

test('it handles airports where country.en is a full name by falling back to country.fr', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    // MJI has country.en = "Libya" (full name) and country.fr = "LY" — mirrors real data
    DB::connection($connection)->table('airports')->insert([
        'iata_code' => 'MJI',
        'name' => json_encode(['en' => 'Mitiga International Airport']),
        'city' => json_encode(['en' => 'Tripoli']),
        'country' => json_encode(['en' => 'Libya', 'fr' => 'LY']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection($connection)->table('airports')->insert([
        'iata_code' => 'IST',
        'name' => json_encode(['en' => 'Istanbul Airport']),
        'city' => json_encode(['en' => 'Istanbul']),
        'country' => json_encode(['en' => 'TR']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(RouteInternationalService::class);

    expect($service->isInternational('MJI', 'IST'))->toBeTrue();
});

test('it returns false when airport is not found in airports table', function () {
    $service = app(RouteInternationalService::class);

    // No airports seeded — unknown codes fall back to domestic
    expect($service->isInternational('XXX', 'YYY'))->toBeFalse();
});

test('country lookup is cached for 24 hours', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    Cache::flush();

    insertTestAirports($connection, ['MJI' => 'LY', 'IST' => 'TR']);

    $service = app(RouteInternationalService::class);

    expect($service->isInternational('MJI', 'IST'))->toBeTrue();

    DB::connection($connection)->table('airports')->whereIn('iata_code', ['MJI', 'IST'])->delete();

    // Still true — served from cache
    expect($service->isInternational('MJI', 'IST'))->toBeTrue();

    $this->travel(25)->hours();

    // Cache expired, airports deleted → falls back to false
    expect($service->isInternational('MJI', 'IST'))->toBeFalse();
});
