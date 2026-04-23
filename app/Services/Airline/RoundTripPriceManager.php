<?php

namespace App\Services\Airline;

use App\DTOs\Airline\RoundTripPriceRequest;
use App\DTOs\Airline\RoundTripPriceResult;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

class RoundTripPriceManager
{
    public function priceWithCaching(AirlineProviderInterface $provider, string $airlineCode, RoundTripPriceRequest $request): RoundTripPriceResult
    {
        $cacheKey = $this->buildCacheKey($airlineCode, $request);
        $cacheTtl = now()->addMinutes(15);
        $cacheTags = $this->buildCacheTags($airlineCode, $request);

        if (Cache::getStore() instanceof TaggableStore) {
            /** @var RoundTripPriceResult $result */
            $result = Cache::tags($cacheTags)->remember($cacheKey, $cacheTtl, function () use ($provider, $request): RoundTripPriceResult {
                /** @var RoundTripPriceResult $response */
                $response = $provider->priceRoundTrip($request);

                return $response;
            });

            return $result;
        }

        /** @var RoundTripPriceResult $result */
        $result = Cache::remember($cacheKey, $cacheTtl, function () use ($provider, $request): RoundTripPriceResult {
            /** @var RoundTripPriceResult $response */
            $response = $provider->priceRoundTrip($request);

            return $response;
        });

        return $result;
    }

    public function clearForRoute(string $airlineCode, string $origin, string $destination): void
    {
        if (! Cache::getStore() instanceof TaggableStore) {
            return;
        }

        Cache::tags([
            'roundtrip_prices',
            'airline_'.strtoupper($airlineCode),
            'route_'.strtoupper($origin).'_'.strtoupper($destination),
        ])->flush();
    }

    protected function buildCacheKey(string $airlineCode, RoundTripPriceRequest $request): string
    {
        return 'roundtrip_price:'.strtoupper($airlineCode).':'.md5(json_encode([
            'outbound' => $request->outboundSegment,
            'return' => $request->returnSegment,
            'passengers' => $request->passengers,
            'outbound_price' => $request->outboundPrice,
        ], JSON_THROW_ON_ERROR));
    }

    protected function buildCacheTags(string $airlineCode, RoundTripPriceRequest $request): array
    {
        $origin = strtoupper((string) ($request->outboundSegment['origin'] ?? ''));
        $destination = strtoupper((string) ($request->outboundSegment['destination'] ?? ''));

        return [
            'roundtrip_prices',
            'airline_'.strtoupper($airlineCode),
            'route_'.$origin.'_'.$destination,
        ];
    }
}
