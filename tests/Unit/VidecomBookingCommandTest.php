<?php

use App\Services\Airline\Videcom\BaseVidecomAirline;
use App\Services\Airline\Videcom\VidecomClient;

test('create booking does not include a manual time limit by default', function () {
    $client = new class([]) extends VidecomClient
    {
        public string $lastCommand = '';

        public function __construct(array $config) {}

        public function runCommand(string $command): string
        {
            $this->lastCommand = $command;

            return '<PNR><Locator>ABC123</Locator></PNR>';
        }
    };

    $provider = new class(['base_url' => 'http://test']) extends BaseVidecomAirline
    {
        public function getIataCode(): string
        {
            return 'YI';
        }

        public function getName(): string
        {
            return 'Oya';
        }

        public function getVidecomCode(): string
        {
            return 'OYA';
        }

        public function getAncillaryCatalog(array $flight = [], array $searchParams = []): array
        {
            return [];
        }

        public function setClient(VidecomClient $client): void
        {
            $this->client = $client;
        }
    };

    $provider->setClient($client);
    $provider->createBooking([
        'passengers' => [
            [
                'type' => 'adult',
                'first_name' => 'ABDULLAH',
                'last_name' => 'ABDULLAH',
                'gender' => 'M',
                'dob' => '1992-05-07',
                'passport_number' => 'HPPLRF3K',
                'passport_expiry' => '2029-10-01',
            ],
        ],
        'contact' => [
            'email' => 'alslimany@gmail.com',
            'phone' => '911388788',
        ],
        'itinerary' => [
            [
                'flt_no' => '0510',
                'class' => 'Y',
                'date' => '2026-04-12 17:45:00',
                'origin' => 'MJI',
                'dest' => 'SEB',
            ],
        ],
        'extras' => [
            'selected_services' => [],
            'seats' => [
                0 => [
                    1 => '14B',
                ],
            ],
        ],
    ]);

    expect($client->lastCommand)->not->toContain('^8/')
        ->and($client->lastCommand)->toContain('^E^*R~x');
});
