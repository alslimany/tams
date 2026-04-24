<?php

use App\Services\Finance\RouteInternationalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('it returns true when route countries are different', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    DB::connection($connection)->table('airport_countries')->insert([
        ['country_code' => 'LY', 'country_name' => 'Libya', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['country_code' => 'TR', 'country_name' => 'Turkey', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $service = app(RouteInternationalService::class);

    expect($service->isInternational('LY', 'TR'))->toBeTrue();
});

test('it returns false when route countries are the same', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    DB::connection($connection)->table('airport_countries')->insert([
        'country_code' => 'LY',
        'country_name' => 'Libya',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(RouteInternationalService::class);

    expect($service->isInternational('LY', 'LY'))->toBeFalse();
});

test('country lookup is cached for 24 hours', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    Cache::flush();

    DB::connection($connection)->table('airport_countries')->insert([
        ['country_code' => 'LY', 'country_name' => 'Libya', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['country_code' => 'TR', 'country_name' => 'Turkey', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $service = app(RouteInternationalService::class);

    expect($service->isInternational('LY', 'TR'))->toBeTrue();

    DB::connection($connection)->table('airport_countries')->whereIn('country_code', ['LY', 'TR'])->delete();

    expect($service->isInternational('LY', 'TR'))->toBeTrue();

    $this->travel(25)->hours();

    expect($service->isInternational('LY', 'TR'))->toBeFalse();
});
