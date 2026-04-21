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
        ->and($client->lastCommand)->toStartWith('i^-1ABDULLAH/ABDULLAHMR^9-1M*+911388788^9-1E*alslimany@gmail.com')
        ->and($client->lastCommand)->toContain('^0YI0510Y12AprMJISEBNN1')
        ->and($client->lastCommand)->toContain('^FG^FS1^MI-ABC TOURS01012^EZT*R^EZRE^*R~x')
        ->and($client->lastCommand)->not->toContain('FDOCS');
});

test('create booking can include docs entry when enabled and passport data is complete', function () {
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
            return 'UZ';
        }

        public function getName(): string
        {
            return 'Buraq Air';
        }

        public function getVidecomCode(): string
        {
            return 'Buraq';
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
                'passport_number' => 'LB1234567',
                'passport_issue_country' => 'LBY',
                'passport_expiry' => '2029-10-01',
                'nationality' => 'LBY',
            ],
        ],
        'contact' => [
            'email' => 'i.abdullah@median.ly',
            'phone' => '+218910000000',
        ],
        'itinerary' => [
            [
                'flt_no' => 'UZ0123',
                'class' => 'H',
                'date' => '2026-04-30',
                'origin' => 'MJI',
                'dest' => 'TUN',
            ],
        ],
        'extras' => [
            'include_docs' => true,
            'selected_services' => [],
        ],
    ]);

    expect($client->lastCommand)
        ->toStartWith('i^-1ABDULLAH/ABDULLAHMR^9-1M*+218910000000^9-1E*i.abdullah@median.ly^0UZ0123H30AprMJITUNNN1')
        ->and($client->lastCommand)->toContain('4-1FDOCS/P/LBY/LB1234567/LBY/07May92/m/01Oct29/ABDULLAH/ABDULLAH/')
        ->and($client->lastCommand)->toContain('^FG^FS1^MI-ABC TOURS01012^EZT*R^EZRE^*R~x');
});

test('pricing command starts with session initializer i', function () {
    $client = new class([]) extends VidecomClient
    {
        public string $lastCommand = '';

        public function __construct(array $config) {}

        public function runCommand(string $command): string
        {
            $this->lastCommand = $command;

            return '<FareQuote />';
        }
    };

    $provider = new class(['base_url' => 'http://test']) extends BaseVidecomAirline
    {
        public function getIataCode(): string
        {
            return 'UZ';
        }

        public function getName(): string
        {
            return 'Buraq Air';
        }

        public function getVidecomCode(): string
        {
            return 'Buraq';
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

    $provider->getPricing([
        [
            'flt_no' => '0123',
            'class' => 'H',
            'date' => '2026-04-30',
            'origin' => 'MJI',
            'dest' => 'TUN',
        ],
    ], [
        [
            'type' => 'adult',
            'first_name' => 'Abdullah',
            'last_name' => 'Ishtiwy',
            'title' => 'MR',
        ],
    ]);

    expect($client->lastCommand)
        ->toStartWith('i^')
        ->toContain('-1ISHTIWY/ABDULLAHMR');
});
