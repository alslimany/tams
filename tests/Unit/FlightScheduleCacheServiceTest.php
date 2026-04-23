<?php

use App\Services\GlobalCache\FlightScheduleCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('flight schedule cache stores and retrieves lowest price for date', function () {
    $service = app(FlightScheduleCacheService::class);

    $service->storePrice('YI', 'MJI', 'BEN', '2026-06-10', 'Y', 220.00, 'LYD', 24);
    $service->storePrice('YI', 'MJI', 'BEN', '2026-06-10', 'Y', 180.00, 'LYD', 24);

    $hint = $service->getLowestPrice('MJI', 'BEN', '2026-06-10');

    expect($hint)->not->toBeNull()
        ->and($hint['price'])->toBe(180.0)
        ->and($hint['currency'])->toBe('LYD')
        ->and($hint['airline'])->toBe('YI');
});

test('expired schedule cache entries are ignored', function () {
    $service = app(FlightScheduleCacheService::class);

    $entry = $service->storePrice('YI', 'MJI', 'BEN', '2026-06-10', 'Y', 220.00, 'LYD', 1);
    $entry->expires_at = now()->subMinute();
    $entry->save();

    expect($service->getLowestPrice('MJI', 'BEN', '2026-06-10'))->toBeNull();
});
