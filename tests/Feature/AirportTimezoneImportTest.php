<?php

use App\Models\Airport;
use App\Services\Airline\FlightDurationCalculator;
use App\Services\Airline\FlightOfferPresenter;

function createAirportWithTimezone(string $iata, string $timezone, string $name): Airport
{
    return Airport::query()->create([
        'iata_code' => $iata,
        'name' => ['en' => $name],
        'city' => ['en' => $name],
        'country' => ['en' => 'Test Country'],
        'timezone' => $timezone,
    ]);
}

test('airport timezone import skips numeric offsets and updates named zones', function () {
    Airport::query()->create([
        'iata_code' => 'MJI',
        'name' => ['en' => 'Mitiga'],
        'city' => ['en' => 'Tripoli'],
        'country' => ['en' => 'Libya'],
    ]);

    Airport::query()->create([
        'iata_code' => 'POM',
        'name' => ['en' => 'Port Moresby'],
        'city' => ['en' => 'Port Moresby'],
        'country' => ['en' => 'PG'],
    ]);

    $path = database_path('data/airports_timezones.json');

    $this->artisan('airports:import-timezones', ['--file' => $path])
        ->assertSuccessful();

    expect(Airport::query()->where('iata_code', 'MJI')->value('timezone'))->toBe('Africa/Tripoli')
        ->and(Airport::query()->where('iata_code', 'POM')->value('timezone'))->toBeNull();

    expect(FlightDurationCalculator::isValidTimezone('10'))->toBeFalse();
});

test('flight duration uses airport timezones instead of naive local clock diff', function () {
    createAirportWithTimezone('MJI', 'Africa/Tripoli', 'Mitiga');
    createAirportWithTimezone('TUN', 'Africa/Tunis', 'Tunis Carthage');

    $calculator = app(FlightDurationCalculator::class);
    $calculator->clearCache();

    $minutes = $calculator->minutesBetween(
        'MJI',
        'TUN',
        '2026-06-28 10:00:00',
        '2026-06-28 10:15:00',
    );

    expect($minutes)->toBe(75);
});

test('flight duration falls back to provider minutes when timezones are missing', function () {
    $calculator = app(FlightDurationCalculator::class);
    $calculator->clearCache();

    expect($calculator->minutesBetween('AAA', 'BBB', '2026-06-28 10:00:00', '2026-06-28 10:15:00', 90))
        ->toBe(90);
});

test('flight offer presenter recalculates segment duration with timezones', function () {
    createAirportWithTimezone('MJI', 'Africa/Tripoli', 'Mitiga');
    createAirportWithTimezone('TUN', 'Africa/Tunis', 'Tunis Carthage');

    app(FlightDurationCalculator::class)->clearCache();

    $presented = app(FlightOfferPresenter::class)->present([
        'airline_code' => '5S',
        'segments' => [[
            'departure_airport' => 'MJI',
            'arrival_airport' => 'TUN',
            'departure_time' => '2026-06-28 10:00:00',
            'arrival_time' => '2026-06-28 10:15:00',
            'duration' => 15,
        ]],
    ], forApi: true);

    expect($presented['segments'][0]['duration'])->toBe('PT1H15M')
        ->and($presented['duration'])->toBe('PT1H15M');
});
