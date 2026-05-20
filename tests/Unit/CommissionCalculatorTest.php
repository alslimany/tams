<?php

use App\Services\Finance\CommissionCalculator;
use App\Services\Finance\RouteInternationalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('it calculates domestic commission correctly', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    DB::connection($connection)->table('airports')->insert([
        ['iata_code' => 'MJI', 'name' => json_encode(['en' => 'MJI']), 'city' => json_encode(['en' => 'MJI']), 'country' => json_encode(['en' => 'LY']), 'created_at' => now(), 'updated_at' => now()],
        ['iata_code' => 'TIP', 'name' => json_encode(['en' => 'TIP']), 'city' => json_encode(['en' => 'TIP']), 'country' => json_encode(['en' => 'LY']), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $calculator = app(CommissionCalculator::class);

    $result = $calculator->calculate(
        tenantProvider: [
            'commission_domestic' => 5,
            'commission_international' => 10,
        ],
        origin: 'MJI',
        destination: 'TIP',
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

    DB::connection($connection)->table('airports')->insert([
        ['iata_code' => 'MJI', 'name' => json_encode(['en' => 'MJI']), 'city' => json_encode(['en' => 'MJI']), 'country' => json_encode(['en' => 'LY']), 'created_at' => now(), 'updated_at' => now()],
        ['iata_code' => 'IST', 'name' => json_encode(['en' => 'IST']), 'city' => json_encode(['en' => 'IST']), 'country' => json_encode(['en' => 'TR']), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $calculator = app(CommissionCalculator::class);

    $result = $calculator->calculate(
        tenantProvider: [
            'commission_domestic' => 5,
            'commission_international' => 10,
        ],
        origin: 'MJI',
        destination: 'IST',
        netFare: 199.99,
    );

    expect($result['percent'])->toBe(10.0)
        ->and($result['amount'])->toBe(20.0)
        ->and($result['net_after_commission'])->toBe(179.99);
});

test('it supports legacy domestic and international commission rate fields', function () {
    $connection = config('tenancy.database.central_connection', config('database.default', 'sqlite'));

    DB::connection($connection)->table('airports')->insert([
        ['iata_code' => 'MJI', 'name' => json_encode(['en' => 'MJI']), 'city' => json_encode(['en' => 'MJI']), 'country' => json_encode(['en' => 'LY']), 'created_at' => now(), 'updated_at' => now()],
        ['iata_code' => 'CAI', 'name' => json_encode(['en' => 'CAI']), 'city' => json_encode(['en' => 'CAI']), 'country' => json_encode(['en' => 'EG']), 'created_at' => now(), 'updated_at' => now()],
        ['iata_code' => 'TIP', 'name' => json_encode(['en' => 'TIP']), 'city' => json_encode(['en' => 'TIP']), 'country' => json_encode(['en' => 'LY']), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $routeService = app(RouteInternationalService::class);
    $calculator = new CommissionCalculator($routeService);

    $domestic = $calculator->calculate(
        tenantProvider: [
            'domestic_commission_rate' => 4,
            'international_commission_rate' => 8,
        ],
        origin: 'MJI',
        destination: 'TIP',
        netFare: 100,
    );

    $international = $calculator->calculate(
        tenantProvider: [
            'domestic_commission_rate' => 4,
            'international_commission_rate' => 8,
        ],
        origin: 'MJI',
        destination: 'CAI',
        netFare: 100,
    );

    expect($domestic['percent'])->toBe(4.0)
        ->and($domestic['amount'])->toBe(4.0)
        ->and($international['percent'])->toBe(8.0)
        ->and($international['amount'])->toBe(8.0);
});
