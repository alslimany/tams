<?php

use App\DTOs\Airline\RoundTripPriceRequest;
use App\DTOs\Airline\RoundTripPriceResult;
use App\Services\Airline\Videcom\Airlines\GlobalAirline;
use App\Services\Airline\Videcom\VidecomClient;
use Tests\TestCase;

uses(TestCase::class);

test('price round trip parses segmented fares and computes return leg price', function () {
    $client = new class extends VidecomClient
    {
        public array $commands = [];

        public function __construct() {}

        public function runCommand(string $command): string
        {
            $this->commands[] = $command;

            return <<<'XML'
<PNR>
    <FareQuote>
        <FQItin Seg="1" Cur="LYD" Total="210.00" Tax1="20" Tax2="0" Tax3="0" />
        <FQItin Seg="2" Cur="LYD" Total="180.00" Tax1="18" Tax2="0" Tax3="0" />
    </FareQuote>
</PNR>
XML;
        }
    };

    $provider = new class(['base_url' => 'https://booking.gair.test']) extends GlobalAirline
    {
        protected function applyAccuratePricing($option, int $adults, int $children, int $infants): void {}
    };

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    $result = $provider->priceRoundTrip(new RoundTripPriceRequest(
        outboundSegment: [
            'flight_number' => '0754',
            'class' => 'Y',
            'date' => '2026-05-01 09:00:00',
            'origin' => 'MJI',
            'destination' => 'IST',
        ],
        returnSegment: [
            'flight_number' => '0755',
            'class' => 'Y',
            'date' => '2026-05-10 20:00:00',
            'origin' => 'IST',
            'destination' => 'MJI',
        ],
        passengers: [
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ],
        outboundPrice: 210.0,
    ));

    expect($result)
        ->toBeInstanceOf(RoundTripPriceResult::class)
        ->and($result->returnLegPrice)->toBe(180.0)
        ->and($result->totalPrice)->toBe(390.0)
        ->and($result->currency)->toBe('LYD')
        ->and($client->commands[0] ?? '')->toContain('QQ1');
});

test('price round trip derives return leg from combined fare when provider returns one total', function () {
    $client = new class extends VidecomClient
    {
        public function __construct() {}

        public function runCommand(string $command): string
        {
            return <<<'XML'
<PNR>
    <FareQuote>
        <FQItin Seg="1" Cur="LYD" Total="390.00" Tax1="38" Tax2="0" Tax3="0" />
    </FareQuote>
</PNR>
XML;
        }
    };

    $provider = new class(['base_url' => 'https://booking.gair.test']) extends GlobalAirline
    {
        protected function applyAccuratePricing($option, int $adults, int $children, int $infants): void {}
    };

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    $result = $provider->priceRoundTrip(new RoundTripPriceRequest(
        outboundSegment: [
            'flight_number' => '0754',
            'class' => 'Y',
            'date' => '2026-05-01 09:00:00',
            'origin' => 'MJI',
            'destination' => 'IST',
        ],
        returnSegment: [
            'flight_number' => '0755',
            'class' => 'Y',
            'date' => '2026-05-10 20:00:00',
            'origin' => 'IST',
            'destination' => 'MJI',
        ],
        passengers: [
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ],
        outboundPrice: 210.0,
    ));

    expect($result->returnLegPrice)->toBe(180.0)
        ->and($result->totalPrice)->toBe(390.0)
        ->and($result->currency)->toBe('LYD');
});
