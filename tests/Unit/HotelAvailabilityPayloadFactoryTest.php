<?php

use App\Services\Hotels\HotelApiException;
use App\Services\Hotels\HotelAvailabilityPayloadFactory;

test('it builds a 3T-ready availability payload from structured rooms', function () {
    $factory = new HotelAvailabilityPayloadFactory;

    $payload = $factory->make([
        'city' => 'Paris, France',
        'city_id' => 215,
        'check_in' => '2026-08-01',
        'check_out' => '2026-08-05',
        'rooms' => [
            ['adult' => 2, 'children' => [5, 8]],
            ['adult' => 1, 'children' => []],
        ],
        'language' => 'fr-FR',
    ], page: 2);

    expect($payload)
        ->checkIn->toBe('2026-08-01')
        ->checkOut->toBe('2026-08-05')
        ->city->toBe('Paris')
        ->cityId->toBe(215)
        ->language->toBe('fr_FR')
        ->onlyAvailableHotels->toBeTrue()
        ->channel->toBe('b2b')
        ->page->toBe(2)
        ->and($payload['occupancies'])->toBe([
            '1' => [
                'adult' => '2',
                'child' => ['value' => 2, 'age' => '5,8'],
            ],
            '2' => [
                'adult' => '1',
                'child' => ['value' => 0, 'age' => ''],
            ],
        ]);
});

test('it accepts flat rooms shortcut when children are zero', function () {
    $factory = new HotelAvailabilityPayloadFactory;

    $rooms = $factory->normalizeRooms([
        'rooms' => 2,
        'adults' => 2,
        'children' => 0,
    ]);

    expect($rooms)->toBe([
        ['adult' => 2, 'children' => []],
        ['adult' => 2, 'children' => []],
    ]);
});

test('it rejects flat children without ages', function () {
    $factory = new HotelAvailabilityPayloadFactory;

    $factory->normalizeRooms([
        'rooms' => 1,
        'adults' => 2,
        'children' => 1,
    ]);
})->throws(HotelApiException::class);
