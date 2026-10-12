<?php

use App\DTOs\Airline\RoundTripPriceRequest;
use App\Exceptions\NoFareAvailableException;
use App\Services\Airline\Videcom\Airlines\GlobalAirline;
use App\Services\Airline\Videcom\VidecomClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Build a Videcom airline whose next pricing command returns the given response.
 */
function roundTripNoFareProvider(string $response): GlobalAirline
{
    $client = new class($response) extends VidecomClient
    {
        public function __construct(private readonly string $stubResponse) {}

        public function runCommand(string $command): string
        {
            return $this->stubResponse;
        }
    };

    $provider = new class(['base_url' => 'https://booking.gair.test']) extends GlobalAirline
    {
        protected function applyAccuratePricing($option, int $adults, int $children, int $infants): void {}
    };

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    return $provider;
}

function roundTripNoFareRequest(): RoundTripPriceRequest
{
    return new RoundTripPriceRequest(
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
        passengers: ['adults' => 1, 'children' => 0, 'infants' => 0],
        outboundPrice: 210.0,
    );
}

test('round-trip pricing reports a fareless response as a no-fare condition', function () {
    $provider = roundTripNoFareProvider(<<<'XML'
<PNR>
    <Itinerary>
        <Seg FlightNo="0754" />
    </Itinerary>
</PNR>
XML);

    expect(fn () => $provider->priceRoundTrip(roundTripNoFareRequest()))
        ->toThrow(NoFareAvailableException::class);
});

test('round-trip pricing keeps a non-XML provider response diagnosable', function () {
    $provider = roundTripNoFareProvider("<html><body>\nSession limit reached</body></html>");

    try {
        $provider->priceRoundTrip(roundTripNoFareRequest());
        $this->fail('Expected NoFareAvailableException was not thrown.');
    } catch (NoFareAvailableException $exception) {
        expect($exception)->toBeInstanceOf(Exception::class)
            ->and($exception->getMessage())->toContain('Session limit reached')
            ->and(strlen($exception->getMessage()))->toBeLessThan(300);
    }
});
