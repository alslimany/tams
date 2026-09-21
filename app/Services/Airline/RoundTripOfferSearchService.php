<?php

namespace App\Services\Airline;

use App\DTOs\Airline\FlightOption;
use App\DTOs\Airline\RoundTripPriceRequest;
use App\Jobs\ProcessRoundTripSearchChunkJob;
use App\Models\TenantProvider;
use App\Services\Airline\Videcom\BaseVidecomAirline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class RoundTripOfferSearchService
{
    public const SESSION_TTL_MINUTES = 30;

    public const AVAILABILITY_CACHE_MINUTES = 15;

    public function __construct(
        protected AgencyProviderResolver $providerResolver,
        protected RoundTripPriceManager $roundTripPriceManager,
        protected FlightOfferPresenter $flightOfferPresenter,
    ) {}

    /**
     * @param  array{
     *     origin: string,
     *     destination: string,
     *     date: string,
     *     return_date: string,
     *     adults: int,
     *     children?: int,
     *     infants?: int,
     *     cabin_class?: string,
     *     cheap_only?: bool,
     *     provider_ids?: list<int>,
     * }  $params
     * @return array{uuid: string, providers: list<array<string, mixed>>, status: string, search_params: array<string, mixed>}
     */
    public function start(array $params): array
    {
        $uuid = (string) Str::uuid();
        $origin = strtoupper((string) $params['origin']);
        $destination = strtoupper((string) $params['destination']);

        $params = array_merge($params, [
            'origin' => $origin,
            'destination' => $destination,
            'children' => (int) ($params['children'] ?? 0),
            'infants' => (int) ($params['infants'] ?? 0),
            'cabin_class' => $params['cabin_class'] ?? 'all',
            'cheap_only' => (bool) ($params['cheap_only'] ?? false),
            'is_return' => true,
        ]);

        $providers = $this->resolveProviders($params['provider_ids'] ?? null);
        $outboundProviders = $this->filterProvidersByAccountAirports($providers, $origin, $destination);

        $providerList = $outboundProviders->map(fn (TenantProvider $provider): array => [
            'id' => $provider->id,
            'airline_code' => $provider->airline_code,
            'airline_name' => $provider->airline_name,
            'provider_type' => $provider->provider_type,
            'account_name' => $provider->account_name,
            'currency' => data_get($provider->credentials, 'currency'),
        ])->values()->all();

        $session = [
            'params' => $params,
            'status' => $outboundProviders->isEmpty() ? 'complete' : 'pending',
            'providers' => $providerList,
            'chunks_total' => $outboundProviders->count(),
            'chunks_done' => 0,
            'offers' => [],
            'errors' => [],
            'started_at' => now()->toIso8601String(),
        ];

        $this->putSession($uuid, $session);

        $tenantId = (string) (tenant('id') ?? '');

        foreach ($outboundProviders as $outboundProvider) {
            ProcessRoundTripSearchChunkJob::dispatch(
                $tenantId,
                $uuid,
                (int) $outboundProvider->id,
            );
        }

        return [
            'uuid' => $uuid,
            'providers' => $providerList,
            'status' => $session['status'],
            'search_params' => $params,
        ];
    }

    /**
     * @return array{
     *     uuid: string,
     *     status: string,
     *     chunks_total: int,
     *     chunks_done: int,
     *     offers: list<array<string, mixed>>,
     *     errors: list<array<string, mixed>>,
     *     search_params: array<string, mixed>|null,
     * }
     */
    public function results(string $uuid): ?array
    {
        $session = $this->getSession($uuid);

        if ($session === null) {
            return null;
        }

        $offers = $session['offers'] ?? [];

        if ((bool) data_get($session, 'params.cheap_only', false)) {
            $offers = $this->cheapestOffersOnly($offers);
        }

        usort($offers, static function (array $left, array $right): int {
            return ((float) data_get($left, 'pricing.total', 0)) <=> ((float) data_get($right, 'pricing.total', 0));
        });

        return [
            'uuid' => $uuid,
            'status' => (string) ($session['status'] ?? 'pending'),
            'chunks_total' => (int) ($session['chunks_total'] ?? 0),
            'chunks_done' => (int) ($session['chunks_done'] ?? 0),
            'offers' => array_values($offers),
            'errors' => array_values($session['errors'] ?? []),
            'search_params' => $session['params'] ?? null,
        ];
    }

    public function processOutboundChunk(string $uuid, int $outboundProviderId): void
    {
        $session = $this->getSession($uuid);

        if ($session === null) {
            return;
        }

        /** @var array<string, mixed> $params */
        $params = $session['params'] ?? [];
        $origin = strtoupper((string) ($params['origin'] ?? ''));
        $destination = strtoupper((string) ($params['destination'] ?? ''));
        $returnDate = (string) ($params['return_date'] ?? '');

        $allProviders = $this->resolveProviders($params['provider_ids'] ?? null);
        $outboundProvider = $allProviders->firstWhere('id', $outboundProviderId);

        if (! $outboundProvider instanceof TenantProvider) {
            $this->markChunkDone($uuid, [], [[
                'outbound_provider_id' => $outboundProviderId,
                'message' => 'Outbound provider not found.',
            ]]);

            return;
        }

        $outboundAllowed = $this->filterProvidersByAccountAirports(
            collect([$outboundProvider]),
            $origin,
            $destination,
        );

        if ($outboundAllowed->isEmpty()) {
            $this->markChunkDone($uuid, [], []);

            return;
        }

        try {
            $outboundFlights = $this->searchLegCached(
                $outboundProvider,
                $origin,
                $destination,
                (string) ($params['date'] ?? ''),
                $params,
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->markChunkDone($uuid, [], [[
                'outbound_provider_id' => $outboundProviderId,
                'message' => $exception->getMessage(),
            ]]);

            return;
        }

        $returnProviders = $this->filterProvidersByAccountAirports(
            $allProviders,
            $destination,
            $origin,
        );

        $offers = [];
        $errors = [];

        foreach ($returnProviders as $returnProvider) {
            try {
                $returnFlights = $this->searchLegCached(
                    $returnProvider,
                    $destination,
                    $origin,
                    $returnDate,
                    $params,
                    isReturnLeg: true,
                );

                foreach ($outboundFlights as $outboundFlight) {
                    foreach ($returnFlights as $returnFlight) {
                        $offer = $this->buildOffer(
                            $outboundProvider,
                            $returnProvider,
                            $outboundFlight,
                            $returnFlight,
                            $params,
                        );

                        if ($offer !== null) {
                            $offers[] = $offer;
                        }
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
                $errors[] = [
                    'outbound_provider_id' => $outboundProviderId,
                    'return_provider_id' => $returnProvider->id,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $this->markChunkDone($uuid, $offers, $errors);
    }

    /**
     * Shared availability cache for one-way and round-trip searches.
     *
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function searchLegCached(
        TenantProvider $provider,
        string $origin,
        string $destination,
        string $date,
        array $params,
        bool $isReturnLeg = false,
    ): array {
        $adults = (int) ($params['adults'] ?? 1);
        $children = (int) ($params['children'] ?? 0);
        $infants = (int) ($params['infants'] ?? 0);
        $cabinClass = (string) ($params['cabin_class'] ?? 'all');

        $cacheKey = implode(':', [
            'flight_avail',
            (string) $provider->id,
            strtoupper($origin),
            strtoupper($destination),
            $date,
            $adults,
            $children,
            $infants,
            $cabinClass,
            $isReturnLeg ? 'return' : 'outbound',
        ]);

        /** @var list<array<string, mixed>> $flights */
        $flights = Cache::remember(
            $cacheKey,
            now()->addMinutes(self::AVAILABILITY_CACHE_MINUTES),
            function () use ($provider, $origin, $destination, $date, $adults, $children, $infants, $cabinClass, $isReturnLeg): array {
                $airline = ProviderFactory::make($provider);

                $searchParams = [
                    'origin' => strtoupper($origin),
                    'destination' => strtoupper($destination),
                    'date' => $date,
                    'adults' => $adults,
                    'children' => $children,
                    'infants' => $infants,
                    'qty' => $adults + $children,
                    'is_return' => false,
                ];

                $raw = $isReturnLeg && method_exists($airline, 'searchReturnLeg')
                    ? collect($airline->searchReturnLeg($searchParams))
                    : collect($airline->searchAvailability($searchParams));

                $provider->update(['last_used_at' => now()]);

                $filtered = CabinClassFilter::filter($raw->all(), $cabinClass);

                return array_values(array_map(
                    fn (mixed $flight): array => $this->normalizeFlight($flight, $provider),
                    $filtered,
                ));
            },
        );

        return $flights;
    }

    /**
     * @param  array<string, mixed>  $outboundFlight
     * @param  array<string, mixed>  $returnFlight
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    protected function buildOffer(
        TenantProvider $outboundProvider,
        TenantProvider $returnProvider,
        array $outboundFlight,
        array $returnFlight,
        array $params,
    ): ?array {
        $sameToken = (int) $outboundProvider->id === (int) $returnProvider->id;
        $outboundTotal = (float) data_get($outboundFlight, 'pricing.total', 0);
        $returnTotal = (float) data_get($returnFlight, 'pricing.total', 0);
        $currency = (string) data_get($outboundFlight, 'pricing.currency', data_get($returnFlight, 'pricing.currency', 'LYD'));
        $pricingMethod = 'oneway';
        $total = round($outboundTotal + $returnTotal, 2);

        if ($sameToken) {
            try {
                $airline = ProviderFactory::make($outboundProvider);

                if ($airline instanceof BaseVidecomAirline || method_exists($airline, 'priceRoundTrip')) {
                    $passengers = [
                        'adults' => (int) ($params['adults'] ?? 1),
                        'children' => (int) ($params['children'] ?? 0),
                        'infants' => (int) ($params['infants'] ?? 0),
                    ];

                    $pricing = $this->roundTripPriceManager->priceWithCaching(
                        $airline,
                        (string) $outboundProvider->airline_code,
                        new RoundTripPriceRequest(
                            outboundSegment: $this->mapFlightToRoundTripSegment($outboundFlight),
                            returnSegment: $this->mapFlightToRoundTripSegment($returnFlight),
                            passengers: $passengers,
                            outboundPrice: $outboundTotal,
                        ),
                    );

                    $total = round((float) $pricing->totalPrice, 2);
                    $currency = $pricing->currency !== '' ? $pricing->currency : $currency;
                    $pricingMethod = 'roundtrip';
                    $returnTotal = round((float) $pricing->returnLegPrice, 2);
                }
            } catch (Throwable $exception) {
                report($exception);
                $pricingMethod = 'oneway';
            }
        }

        if ($total <= 0) {
            return null;
        }

        $outboundPresented = $this->flightOfferPresenter->present($outboundFlight, forApi: true);
        $returnPresented = $this->flightOfferPresenter->present($returnFlight, forApi: true);

        $itineraries = [$outboundPresented, $returnPresented];
        usort($itineraries, static function (array $left, array $right): int {
            return strcmp(
                (string) ($left['departure_time'] ?? ''),
                (string) ($right['departure_time'] ?? ''),
            );
        });

        $offerId = 'rt_'.md5(implode('|', [
            $outboundProvider->id,
            $returnProvider->id,
            data_get($outboundFlight, 'id', data_get($outboundFlight, 'flight_number')),
            data_get($outboundFlight, 'departure_time'),
            data_get($returnFlight, 'id', data_get($returnFlight, 'flight_number')),
            data_get($returnFlight, 'departure_time'),
            $pricingMethod,
            $total,
        ]));

        return [
            'offer_id' => $offerId,
            'pricing' => [
                'total' => $total,
                'currency' => strtoupper($currency),
                'method' => $pricingMethod,
                'outbound_total' => $outboundTotal,
                'return_total' => $returnTotal,
            ],
            'legs' => [
                [
                    'provider_id' => (int) $outboundProvider->id,
                    'airline_code' => $outboundProvider->airline_code,
                    'itinerary_index' => 0,
                ],
                [
                    'provider_id' => (int) $returnProvider->id,
                    'airline_code' => $returnProvider->airline_code,
                    'itinerary_index' => 1,
                ],
            ],
            'itineraries' => $itineraries,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     * @return list<array<string, mixed>>
     */
    protected function cheapestOffersOnly(array $offers): array
    {
        $cheapest = null;

        foreach ($offers as $offer) {
            $total = (float) data_get($offer, 'pricing.total', 0);

            if ($cheapest === null || $total < (float) data_get($cheapest, 'pricing.total', PHP_FLOAT_MAX)) {
                $cheapest = $offer;
            }
        }

        return $cheapest === null ? [] : [$cheapest];
    }

    /**
     * @param  list<int>|null  $providerIds
     * @return Collection<int, TenantProvider>
     */
    protected function resolveProviders(?array $providerIds): Collection
    {
        $providers = $this->providerResolver->getAllActiveProviders()
            ->filter(fn (TenantProvider $provider): bool => $provider->provider_type === 'videcom')
            ->values();

        if ($providerIds !== null && $providerIds !== []) {
            $ids = array_map('intval', $providerIds);
            $providers = $providers->filter(fn (TenantProvider $provider): bool => in_array((int) $provider->id, $ids, true))->values();
        }

        return $providers;
    }

    /**
     * @param  Collection<int, TenantProvider>  $providers
     * @return Collection<int, TenantProvider>
     */
    protected function filterProvidersByAccountAirports(Collection $providers, string $origin, string $destination): Collection
    {
        $origin = strtoupper($origin);
        $destination = strtoupper($destination);

        $unrestricted = $providers->filter(fn (TenantProvider $p): bool => empty($p->credentials['airports'] ?? []));
        $restricted = $providers->filter(fn (TenantProvider $p): bool => ! empty($p->credentials['airports'] ?? []));

        if ($restricted->isEmpty()) {
            return $providers;
        }

        $normalize = fn (array $airports): array => array_map('strtoupper', $airports);

        $byOrigin = $restricted->filter(fn (TenantProvider $p): bool => in_array($origin, $normalize($p->credentials['airports']), true));

        $matched = $byOrigin->isNotEmpty()
            ? $byOrigin
            : $restricted->filter(fn (TenantProvider $p): bool => in_array($destination, $normalize($p->credentials['airports']), true));

        if ($matched->isNotEmpty()) {
            $matchedAirlineCodes = $matched->pluck('airline_code')->unique();

            $unrestricted = $unrestricted->reject(
                fn (TenantProvider $provider): bool => $matchedAirlineCodes->contains($provider->airline_code)
            );
        }

        return $unrestricted->concat($matched)->values();
    }

    /**
     * @param  array<string, mixed>|FlightOption  $flight
     * @return array<string, mixed>
     */
    protected function normalizeFlight(mixed $flight, TenantProvider $provider): array
    {
        $data = $flight instanceof FlightOption ? $flight->toArray() : (array) $flight;
        $data['provider_id'] = $provider->id;
        $data['airline_code'] = $data['airline_code'] ?? $provider->airline_code;
        $data['airline_name'] = $data['airline_name'] ?? $provider->airline_name;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $flight
     * @return array<string, mixed>
     */
    protected function mapFlightToRoundTripSegment(array $flight): array
    {
        $segment = $flight['segments'][0] ?? $flight;

        return [
            'flight_number' => (string) ($segment['flight_number'] ?? ''),
            'class' => (string) ($segment['class'] ?? data_get($flight, 'pricing.class_code', 'Y')),
            'date' => (string) ($segment['departure_time'] ?? $flight['departure_time'] ?? ''),
            'origin' => strtoupper((string) ($segment['departure_airport'] ?? $flight['departure_airport'] ?? '')),
            'destination' => strtoupper((string) ($segment['arrival_airport'] ?? $flight['arrival_airport'] ?? '')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     * @param  list<array<string, mixed>>  $errors
     */
    protected function markChunkDone(string $uuid, array $offers, array $errors): void
    {
        $lock = Cache::lock($this->sessionLockKey($uuid), 30);

        $lock->block(20, function () use ($uuid, $offers, $errors): void {
            $session = $this->getSession($uuid);

            if ($session === null) {
                return;
            }

            $existingOffers = $session['offers'] ?? [];
            $existingErrors = $session['errors'] ?? [];

            $session['offers'] = array_values(array_merge($existingOffers, $offers));
            $session['errors'] = array_values(array_merge($existingErrors, $errors));
            $session['chunks_done'] = (int) ($session['chunks_done'] ?? 0) + 1;

            $total = (int) ($session['chunks_total'] ?? 0);
            $done = (int) $session['chunks_done'];

            $session['status'] = $done >= $total && $total > 0
                ? 'complete'
                : ($done > 0 ? 'partial' : 'pending');

            $this->putSession($uuid, $session);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getSession(string $uuid): ?array
    {
        $session = Cache::get($this->sessionKey($uuid));

        return is_array($session) ? $session : null;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function putSession(string $uuid, array $session): void
    {
        Cache::put($this->sessionKey($uuid), $session, now()->addMinutes(self::SESSION_TTL_MINUTES));
    }

    protected function sessionKey(string $uuid): string
    {
        return "flight_rt_search_{$uuid}";
    }

    protected function sessionLockKey(string $uuid): string
    {
        return "flight_rt_search_lock_{$uuid}";
    }
}
