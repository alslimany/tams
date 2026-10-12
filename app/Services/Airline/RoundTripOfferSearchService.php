<?php

namespace App\Services\Airline;

use App\DTOs\Airline\FlightOption;
use App\DTOs\Airline\RoundTripPriceRequest;
use App\Exceptions\NoFareAvailableException;
use App\Jobs\ProcessRoundTripSearchJob;
use App\Models\TenantProvider;
use App\Services\Airline\Videcom\BaseVidecomAirline;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RoundTripOfferSearchService
{
    public const SESSION_TTL_MINUTES = 30;

    public const AVAILABILITY_CACHE_MINUTES = 15;

    /**
     * Same-token Videcom round-trip repricing is limited to the cheapest
     * one-way combinations. The rest stay priced as the sum of the legs.
     */
    public const SAME_TOKEN_REPRICE_LIMIT = 3;

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

        if ($outboundProviders->isNotEmpty()) {
            ProcessRoundTripSearchJob::dispatch(
                (string) (tenant('id') ?? ''),
                $uuid,
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
    public function results(string $uuid, ?int $outboundProviderId = null): ?array
    {
        $session = $this->getSession($uuid);

        if ($session === null) {
            return null;
        }

        if ($outboundProviderId !== null) {
            $knownProvider = collect($session['providers'] ?? [])->contains(
                fn (array $provider): bool => (int) ($provider['id'] ?? 0) === $outboundProviderId,
            );

            if (! $knownProvider) {
                return ['provider_missing' => true];
            }

            if (! $this->isProviderComplete($uuid, $outboundProviderId)) {
                $this->processOutboundChunk($uuid, $outboundProviderId);
            }

            $session = $this->getSession($uuid) ?? $session;
        }

        $offers = $session['offers'] ?? [];
        $errors = $session['errors'] ?? [];

        if ($outboundProviderId !== null) {
            $offers = array_values(array_filter(
                $offers,
                fn (array $offer): bool => (int) data_get($offer, 'legs.0.provider_id') === $outboundProviderId,
            ));
            $errors = array_values(array_filter(
                $errors,
                fn (array $error): bool => (int) ($error['outbound_provider_id'] ?? 0) === $outboundProviderId,
            ));
        }

        if ((bool) data_get($session, 'params.cheap_only', false)) {
            $offers = $this->cheapestOffersOnly($offers);
        }

        usort($offers, static function (array $left, array $right): int {
            return ((float) data_get($left, 'pricing.total', 0)) <=> ((float) data_get($right, 'pricing.total', 0));
        });

        $payload = [
            'uuid' => $uuid,
            'status' => (string) ($session['status'] ?? 'pending'),
            'chunks_total' => (int) ($session['chunks_total'] ?? 0),
            'chunks_done' => (int) ($session['chunks_done'] ?? 0),
            'offers' => array_values($offers),
            'errors' => array_values($errors),
            'search_params' => $session['params'] ?? null,
        ];

        if ($outboundProviderId !== null) {
            $payload['provider_id'] = $outboundProviderId;
            $payload['provider_status'] = 'complete';
        }

        return $payload;
    }

    /**
     * Warm every leg once, in parallel, then build each outbound chunk from that cache.
     */
    public function processSession(string $uuid): void
    {
        $this->useCentralCache();

        $session = $this->getSession($uuid);

        if ($session === null) {
            return;
        }

        $this->warmLegs($uuid);

        /** @var list<int> $providerIds */
        $providerIds = array_map(
            static fn (array $provider): int => (int) ($provider['id'] ?? 0),
            $session['providers'] ?? [],
        );

        $tasks = [];

        foreach ($providerIds as $providerId) {
            if ($providerId <= 0) {
                continue;
            }

            $tasks[] = function () use ($uuid, $providerId): void {
                $this->processOutboundChunk($uuid, $providerId);
            };
        }

        $this->runInParallel($tasks);
    }

    public function processOutboundChunk(string $uuid, int $outboundProviderId): void
    {
        $this->useCentralCache();

        $lock = Cache::lock($this->providerLockKey($uuid, $outboundProviderId), 180);

        try {
            $lock->block(170, function () use ($uuid, $outboundProviderId): void {
                if ($this->isProviderComplete($uuid, $outboundProviderId)) {
                    return;
                }

                $this->buildOutboundChunk($uuid, $outboundProviderId);
            });
        } catch (LockTimeoutException) {
            // Another request or the queue job is still building this airline.
        }
    }

    protected function buildOutboundChunk(string $uuid, int $outboundProviderId): void
    {
        $this->useCentralCache();

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
            $this->markChunkDone($uuid, $outboundProviderId, [], [[
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
            $this->markChunkDone($uuid, $outboundProviderId, [], []);

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
            $this->markChunkDone($uuid, $outboundProviderId, [], [[
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

        $errors = [];
        $sameTokenPairs = [];

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

                $returnOffers = [];

                foreach ($outboundFlights as $outboundFlight) {
                    foreach ($returnFlights as $returnFlight) {
                        $offer = $this->buildOffer(
                            $outboundProvider,
                            $returnProvider,
                            $outboundFlight,
                            $returnFlight,
                            $params,
                            priceRoundTrip: false,
                        );

                        if ($offer === null) {
                            continue;
                        }

                        $returnOffers[] = $offer;

                        if ((int) $outboundProvider->id === (int) $returnProvider->id) {
                            $sameTokenPairs[] = [
                                'return_provider' => $returnProvider,
                                'outbound_flight' => $outboundFlight,
                                'return_flight' => $returnFlight,
                                'total' => (float) data_get($offer, 'pricing.total', 0),
                            ];
                        }
                    }
                }

                if ($returnOffers !== []) {
                    $this->mergeOffers($uuid, $returnOffers, []);
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

        $repriced = $this->repriceCheapestSameTokenPairs($outboundProvider, $sameTokenPairs, $params);

        if ($repriced !== []) {
            $this->mergeOffers($uuid, $repriced, []);
        }

        $this->markChunkDone($uuid, $outboundProviderId, [], $errors);
    }

    /**
     * Fetch each airline's outbound and return availability once.
     *
     * Later chunks read this cache instead of calling Videcom again per combination.
     */
    public function warmLegs(string $uuid): void
    {
        $session = $this->getSession($uuid);

        if ($session === null) {
            return;
        }

        /** @var array<string, mixed> $params */
        $params = $session['params'] ?? [];
        $origin = strtoupper((string) ($params['origin'] ?? ''));
        $destination = strtoupper((string) ($params['destination'] ?? ''));
        $date = (string) ($params['date'] ?? '');
        $returnDate = (string) ($params['return_date'] ?? '');

        $allProviders = $this->resolveProviders($params['provider_ids'] ?? null);
        $outboundProviders = $this->filterProvidersByAccountAirports($allProviders, $origin, $destination);
        $returnProviders = $this->filterProvidersByAccountAirports($allProviders, $destination, $origin);

        /** @var array<int, array{provider: TenantProvider, outbound: bool, return: bool}> $legs */
        $legs = [];

        foreach ($outboundProviders as $provider) {
            $legs[(int) $provider->id] = [
                'provider' => $provider,
                'outbound' => true,
                'return' => false,
            ];
        }

        foreach ($returnProviders as $provider) {
            $existing = $legs[(int) $provider->id] ?? null;
            $legs[(int) $provider->id] = [
                'provider' => $provider,
                'outbound' => (bool) ($existing['outbound'] ?? false),
                'return' => true,
            ];
        }

        $tasks = [];

        foreach ($legs as $leg) {
            $tasks[] = function () use ($leg, $origin, $destination, $date, $returnDate, $params): void {
                if ($leg['outbound']) {
                    $this->searchLegCached($leg['provider'], $origin, $destination, $date, $params);
                }

                if ($leg['return']) {
                    $this->searchLegCached(
                        $leg['provider'],
                        $destination,
                        $origin,
                        $returnDate,
                        $params,
                        isReturnLeg: true,
                    );
                }
            };
        }

        $this->runInParallel($tasks);
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
        ]);

        $lock = Cache::lock($cacheKey.':lock', 120);

        try {
            /** @var list<array<string, mixed>> $flights */
            $flights = $lock->block(110, function () use ($cacheKey, $provider, $origin, $destination, $date, $adults, $children, $infants, $cabinClass, $isReturnLeg): array {
                $cached = Cache::get($cacheKey);

                if (is_array($cached)) {
                    return $cached;
                }

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

                $flights = array_values(array_map(
                    fn (mixed $flight): array => $this->normalizeFlight($flight, $provider),
                    $filtered,
                ));

                Cache::put($cacheKey, $flights, now()->addMinutes(self::AVAILABILITY_CACHE_MINUTES));

                return $flights;
            });
        } catch (LockTimeoutException) {
            $cached = Cache::get($cacheKey);

            return is_array($cached) ? $cached : [];
        }

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
        bool $priceRoundTrip = true,
    ): ?array {
        $sameToken = (int) $outboundProvider->id === (int) $returnProvider->id;
        $outboundTotal = (float) data_get($outboundFlight, 'pricing.total', 0);
        $returnTotal = (float) data_get($returnFlight, 'pricing.total', 0);
        $currency = (string) data_get($outboundFlight, 'pricing.currency', data_get($returnFlight, 'pricing.currency', 'LYD'));
        $pricingMethod = 'oneway';
        $total = round($outboundTotal + $returnTotal, 2);

        if ($sameToken && $priceRoundTrip) {
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
            } catch (NoFareAvailableException $exception) {
                Log::info('Round-trip repricing unavailable; falling back to one-way pricing.', [
                    'airline' => (string) $outboundProvider->airline_code,
                    'outbound_flight' => (string) data_get($outboundFlight, 'flight_number', ''),
                    'return_flight' => (string) data_get($returnFlight, 'flight_number', ''),
                    'reason' => $exception->getMessage(),
                ]);
                $pricingMethod = 'oneway';
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
     * @param  list<array{return_provider: TenantProvider, outbound_flight: array<string, mixed>, return_flight: array<string, mixed>, total: float}>  $pairs
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    protected function repriceCheapestSameTokenPairs(TenantProvider $outboundProvider, array $pairs, array $params): array
    {
        if ($pairs === []) {
            return [];
        }

        usort($pairs, static fn (array $left, array $right): int => $left['total'] <=> $right['total']);

        $repriced = [];

        foreach (array_slice($pairs, 0, self::SAME_TOKEN_REPRICE_LIMIT) as $pair) {
            $offer = $this->buildOffer(
                $outboundProvider,
                $pair['return_provider'],
                $pair['outbound_flight'],
                $pair['return_flight'],
                $params,
            );

            if ($offer !== null) {
                $repriced[] = $offer;
            }
        }

        return $repriced;
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     * @param  list<array<string, mixed>>  $errors
     */
    protected function mergeOffers(string $uuid, array $offers, array $errors): void
    {
        $this->updateSession($uuid, function (array &$session) use ($offers, $errors): void {
            $session['offers'] = $this->replaceOffers($session['offers'] ?? [], $offers);
            $session['errors'] = array_values(array_merge($session['errors'] ?? [], $errors));
            $session['status'] = $this->sessionStatus($session);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     * @param  list<array<string, mixed>>  $errors
     */
    protected function markChunkDone(string $uuid, int $outboundProviderId, array $offers, array $errors): void
    {
        $this->updateSession($uuid, function (array &$session) use ($outboundProviderId, $offers, $errors): void {
            $session['offers'] = $this->replaceOffers($session['offers'] ?? [], $offers);
            $session['errors'] = array_values(array_merge($session['errors'] ?? [], $errors));

            $completed = array_map('intval', $session['completed_provider_ids'] ?? []);

            if (! in_array($outboundProviderId, $completed, true)) {
                $completed[] = $outboundProviderId;
            }

            $session['completed_provider_ids'] = array_values($completed);
            $session['chunks_done'] = count($completed);
            $session['status'] = $this->sessionStatus($session);
        });
    }

    protected function isProviderComplete(string $uuid, int $providerId): bool
    {
        $session = $this->getSession($uuid);
        $completed = array_map('intval', $session['completed_provider_ids'] ?? []);

        return in_array($providerId, $completed, true);
    }

    protected function providerLockKey(string $uuid, int $providerId): string
    {
        return "flight_rt_provider_{$uuid}_{$providerId}";
    }

    /**
     * @param  callable(array<string, mixed>&): void  $mutator
     */
    protected function updateSession(string $uuid, callable $mutator): void
    {
        $lock = Cache::lock($this->sessionLockKey($uuid), 30);

        $lock->block(20, function () use ($uuid, $mutator): void {
            $session = $this->getSession($uuid);

            if ($session === null) {
                return;
            }

            $mutator($session);
            $this->putSession($uuid, $session);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    protected function replaceOffers(array $existing, array $incoming): array
    {
        $indexed = [];

        foreach ($existing as $offer) {
            $indexed[(string) ($offer['offer_id'] ?? count($indexed))] = $offer;
        }

        foreach ($incoming as $offer) {
            $indexed[(string) ($offer['offer_id'] ?? count($indexed))] = $offer;
        }

        return array_values($indexed);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function sessionStatus(array $session): string
    {
        $total = (int) ($session['chunks_total'] ?? 0);
        $done = (int) ($session['chunks_done'] ?? 0);

        if ($total > 0 && $done >= $total) {
            return 'complete';
        }

        if ($done > 0 || ($session['offers'] ?? []) !== []) {
            return 'partial';
        }

        return 'pending';
    }

    /**
     * @param  list<callable(): void>  $tasks
     */
    protected function runInParallel(array $tasks): void
    {
        if ($tasks === []) {
            return;
        }

        if (count($tasks) === 1 || app()->runningUnitTests() || ! function_exists('pcntl_fork')) {
            foreach ($tasks as $task) {
                $task();
            }

            return;
        }

        $this->disconnectDatabases();

        $pids = [];

        foreach ($tasks as $task) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->reconnectDatabases();
                $task();

                continue;
            }

            if ($pid === 0) {
                pcntl_alarm(0);

                try {
                    $this->reconnectDatabases();
                    $task();
                    exit(0);
                } catch (Throwable $exception) {
                    report($exception);
                    exit(1);
                }
            }

            $pids[] = $pid;
        }

        $this->reconnectDatabases();

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }

    protected function disconnectDatabases(): void
    {
        foreach (array_keys(config('database.connections', [])) as $name) {
            DB::disconnect($name);
        }
    }

    protected function reconnectDatabases(): void
    {
        foreach (array_keys(config('database.connections', [])) as $name) {
            try {
                DB::reconnect($name);
            } catch (Throwable) {
                // Some connections exist only after tenancy is initialized.
            }
        }

        $this->useCentralCache();
    }

    /**
     * Keep availability and the search session on the central cache connection.
     *
     * Tenancy switches the default database connection, and a forked worker must
     * not reopen the cache store against the tenant database.
     */
    protected function useCentralCache(): void
    {
        if (config('cache.default') !== 'database') {
            return;
        }

        $central = config('tenancy.database.central_connection');

        if (is_string($central) && $central !== '') {
            config(['cache.stores.database.connection' => $central]);
        }

        $manager = app('cache');

        if (method_exists($manager, 'forgetDriver')) {
            $manager->forgetDriver('database');
        }
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
