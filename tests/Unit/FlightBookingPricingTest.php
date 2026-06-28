<?php

use App\Services\Airline\FlightBookingPricing;

test('summarize includes seat and ancillary totals in grand total', function () {
    $provider = new class
    {
        public function getSeatMap(string $fltNo, string $date): array
        {
            return [
                'seats' => [
                    ['code' => '12A', 'price' => 25.0],
                    ['code' => '14C', 'price' => 15.0],
                ],
            ];
        }

        public function getAncillaryCatalog(array $flight = [], array $searchParams = []): array
        {
            return [
                [
                    'code' => 'BAG1',
                    'label' => 'Extra Bag',
                    'enabled' => true,
                    'unit_price' => 30.0,
                    'pricing_mode' => 'per_passenger',
                    'min_quantity' => 1,
                    'max_quantity' => 1,
                    'default_quantity' => 1,
                ],
            ];
        }
    };

    $flight = [
        'pricing' => ['total' => 450, 'currency' => 'LYD'],
        'segments' => [[
            'flight_number' => '500',
            'departure_time' => '2026-06-15 10:00',
            'departure_airport' => 'MJI',
            'arrival_airport' => 'IST',
            'class' => 'Y',
        ]],
    ];

    $summary = app(FlightBookingPricing::class)->summarize(
        $provider,
        $flight,
        [['type' => 'adult']],
        [
            'seats' => [
                0 => [1 => '12A'],
                1 => [1 => '14C'],
            ],
            'selected_services' => [
                ['code' => 'BAG1', 'quantity' => 1, 'passengers' => [0]],
            ],
        ],
    );

    expect($summary['base_fare'])->toBe(450.0)
        ->and($summary['seats']['total'])->toBe(40.0)
        ->and($summary['ancillaries']['total'])->toBe(30.0)
        ->and($summary['grand_total'])->toBe(520.0)
        ->and($summary['seats']['lines'])->toHaveCount(2);
});
