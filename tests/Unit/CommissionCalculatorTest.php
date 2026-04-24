<?php

use App\Services\Finance\CommissionCalculator;
use App\Services\Finance\RouteInternationalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('it calculates domestic commission correctly', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    DB::connection($connection)->table('airport_countries')->insert([
        'country_code' => 'LY',
        'country_name' => 'Libya',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $calculator = app(CommissionCalculator::class);

    $result = $calculator->calculate(
        tenantProvider: [
            'commission_domestic' => 5,
            'commission_international' => 10,
        ],
        origin: 'LY',
        destination: 'LY',
        netFare: 200,
    );

    expect($result)->toBe([
        'percent' => 5.0,
        'amount' => 10.0,
        'net_after_commission' => 190.0,
    ]);
});

test('it calculates international commission correctly', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    DB::connection($connection)->table('airport_countries')->insert([
        ['country_code' => 'LY', 'country_name' => 'Libya', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['country_code' => 'TR', 'country_name' => 'Turkey', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $calculator = app(CommissionCalculator::class);

    $result = $calculator->calculate(
        tenantProvider: [
            'commission_domestic' => 5,
            'commission_international' => 10,
        ],
        origin: 'LY',
        destination: 'TR',
        netFare: 199.99,
    );

    expect($result['percent'])->toBe(10.0)
        ->and($result['amount'])->toBe(20.0)
        ->and($result['net_after_commission'])->toBe(179.99);
});

test('it supports legacy domestic and international commission rate fields', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    DB::connection($connection)->table('airport_countries')->insert([
        ['country_code' => 'LY', 'country_name' => 'Libya', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['country_code' => 'EG', 'country_name' => 'Egypt', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $routeService = app(RouteInternationalService::class);
    $calculator = new CommissionCalculator($routeService);

    $domestic = $calculator->calculate(
        tenantProvider: [
            'domestic_commission_rate' => 4,
            'international_commission_rate' => 8,
        ],
        origin: 'LY',
        destination: 'LY',
        netFare: 100,
    );

    $international = $calculator->calculate(
        tenantProvider: [
            'domestic_commission_rate' => 4,
            'international_commission_rate' => 8,
        ],
        origin: 'LY',
        destination: 'EG',
        netFare: 100,
    );

    expect($domestic['percent'])->toBe(4.0)
        ->and($domestic['amount'])->toBe(4.0)
        ->and($international['percent'])->toBe(8.0)
        ->and($international['amount'])->toBe(8.0);
});
