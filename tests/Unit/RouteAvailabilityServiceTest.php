<?php

use App\Services\GlobalCache\RouteAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('route availability becomes false after three consecutive empty checks', function () {
    $service = app(RouteAvailabilityService::class);

    $service->recordResult('YI', 'MJI', 'BEN', false);
    $service->recordResult('YI', 'MJI', 'BEN', false);

    expect($service->hasFlights('YI', 'MJI', 'BEN'))->toBeNull();

    $service->recordResult('YI', 'MJI', 'BEN', false);

    expect($service->hasFlights('YI', 'MJI', 'BEN'))->toBeFalse();
});

test('route availability resets after a successful result', function () {
    $service = app(RouteAvailabilityService::class);

    $service->recordResult('YI', 'MJI', 'BEN', false);
    $service->recordResult('YI', 'MJI', 'BEN', false);
    $service->recordResult('YI', 'MJI', 'BEN', false);

    expect($service->hasFlights('YI', 'MJI', 'BEN'))->toBeFalse();

    $service->recordResult('YI', 'MJI', 'BEN', true);

    expect($service->hasFlights('YI', 'MJI', 'BEN'))->toBeTrue();
});
