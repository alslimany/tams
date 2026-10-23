<?php

use App\DTOs\Airline\RoundTripPriceRequest;
use App\DTOs\Airline\RoundTripPriceResult;
use App\Exceptions\NoFareAvailableException;
use App\Models\TenantProvider;
use App\Services\Airline\AgencyProviderResolver;
use App\Services\Airline\AirlineProviderInterface;
use App\Services\Airline\FlightDurationCalculator;
use App\Services\Airline\FlightOfferPresenter;
use App\Services\Airline\RoundTripOfferSearchService;
use App\Services\Airline\RoundTripPriceManager;

function roundTripNoFareOfferService(): RoundTripOfferSearchService
{
    $priceManager = new class extends RoundTripPriceManager
    {
        public function priceWithCaching(AirlineProviderInterface $provider, string $airlineCode, RoundTripPriceRequest $request): RoundTripPriceResult
        {
            throw new NoFareAvailableException('Provider returned no round-trip fare quote: <PNR/>');
        }
    };

    return new RoundTripOfferSearchService(
        app(AgencyProviderResolver::class),
        $priceManager,
        new FlightOfferPresenter(new FlightDurationCalculator),
    );
}

function roundTripNoFareFlight(string $flightNumber, string $origin, string $destination, string $departure, string $arrival, float $total): array
{
    return [
        'flight_number' => $flightNumber,
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'departure_airport' => $origin,
        'arrival_airport' => $destination,
        'departure_time' => $departure,
        'arrival_time' => $arrival,
        'segments' => [
            [
                'flight_number' => $flightNumber,
                'class' => 'Y',
                'departure_airport' => $origin,
                'arrival_airport' => $destination,
                'departure_time' => $departure,
                'arrival_time' => $arrival,
            ],
        ],
        'pricing' => ['total' => $total, 'currency' => 'LYD'],
    ];
}

afterEach(function () {
    \Mockery::close();
});

test('round-trip offer building drops a same-token pair the provider refuses to quote', function () {
    \Mockery::mock('alias:App\\Services\\Airline\\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn(\Mockery::mock(AirlineProviderInterface::class));

    $provider = new TenantProvider;
    $provider->setAttribute('id', 7);
    $provider->setAttribute('provider_type', 'videcom');
    $provider->setAttribute('airline_code', 'YI');
    $provider->setAttribute('airline_name', 'Oya');
    $provider->setAttribute('account_name', 'A1');
    $provider->setAttribute('credentials', ['base_url' => 'http://test', 'currency' => 'LYD']);

    $outbound = roundTripNoFareFlight('0500', 'MJI', 'IST', '2026-05-01 10:00:00', '2026-05-01 14:00:00', 200.0);
    $return = roundTripNoFareFlight('0501', 'IST', 'MJI', '2026-05-10 18:00:00', '2026-05-10 22:00:00', 999.0);

    $method = new ReflectionMethod(RoundTripOfferSearchService::class, 'buildOffer');

    $offer = $method->invoke(roundTripNoFareOfferService(), $provider, $provider, $outbound, $return, []);

    // Selling 200 + 999 as one PNR would ticket a round trip Videcom refused.
    expect($offer)->toBeNull();
});
