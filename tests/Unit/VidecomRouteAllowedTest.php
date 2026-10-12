<?php

use App\Services\Airline\Videcom\Airlines\GlobalAirline;
use App\Services\Airline\Videcom\VidecomClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Build a Videcom airline whose account is restricted to the given airports.
 */
function routeAllowedProvider(?array $airports): GlobalAirline
{
    $config = ['base_url' => 'https://booking.gair.test'];

    if ($airports !== null) {
        $config['airports'] = $airports;
    }

    $client = new class extends VidecomClient
    {
        public array $commands = [];

        public function __construct() {}

        public function runCommand(string $command): string
        {
            $this->commands[] = $command;

            return '<PNR><Availability /></PNR>';
        }
    };

    $provider = new class($config) extends GlobalAirline
    {
        protected function applyAccuratePricing($option, int $adults, int $children, int $infants): void {}
    };

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    return $provider;
}

test('a route is allowed when the account has no airport restriction', function () {
    $provider = routeAllowedProvider(null);

    expect(fn () => $provider->searchAvailability([
        'origin' => 'MJI',
        'destination' => 'TUN',
        'date' => '2026-05-01',
    ]))->not->toThrow(Exception::class);
});

test('airport restrictions match regardless of stored casing', function () {
    $provider = routeAllowedProvider(['mji', 'tun']);

    expect(fn () => $provider->searchAvailability([
        'origin' => 'MJI',
        'destination' => 'TUN',
        'date' => '2026-05-01',
    ]))->not->toThrow(Exception::class);
});

test('a route outside the restricted airports is still rejected', function () {
    $provider = routeAllowedProvider(['mji', 'tun']);

    expect(fn () => $provider->searchAvailability([
        'origin' => 'IST',
        'destination' => 'AMS',
        'date' => '2026-05-01',
    ]))->toThrow(Exception::class, 'Route IST-AMS is not allowed for this airline account.');
});
