<?php

namespace App\Console\Commands;

use App\Jobs\ProcessRoundTripSearchJob;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Services\Airline\FlightOfferPresenter;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\RoundTripOfferSearchService;
use App\Services\Airline\Videcom\BaseVidecomAirline;
use App\Services\Airline\Videcom\Monitor\ReadOnlyCommandGuard;
use App\Services\Airline\Videcom\OpenReservationCacheService;
use App\Services\Airline\Videcom\PricingCacheService;
use App\Services\Airline\Videcom\VidecomClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use SimpleXMLElement;
use Throwable;

/**
 * Read-only Videcom monitor.
 *
 * Compares raw Videcom availability + price quotes with our caches and with what
 * our own search / round-trip services return, for one-way, same-provider round
 * trip and mixed-provider round trip. It never books, holds, issues or refunds:
 * every outgoing VRS command goes through ReadOnlyCommandGuard and the run
 * aborts on the first command that is not on the allow-list.
 */
class VidecomMonitorCommand extends Command
{
    protected $signature = 'videcom:monitor
        {--routes=MJI-TUN : Comma separated ORIGIN-DEST pairs}
        {--days=3,7 : Outbound date offsets in days from today}
        {--stay=3 : Return date offset from the outbound date}
        {--tenant= : Only this tenant id}
        {--providers= : Only these tenant_provider ids (comma separated)}
        {--max-quotes=80 : Max raw quote commands per provider and direction}
        {--price-tolerance=1 : Allowed absolute price difference}';

    protected $description = 'Read-only check of Videcom availability/pricing vs our cache and APIs (one-way, round trip, combined round trip).';

    protected ReadOnlyCommandGuard $guard;

    /** @var list<array<string, mixed>> */
    protected array $issues = [];

    /** @var list<string> */
    protected array $commandLog = [];

    /** @var array<string, mixed> */
    protected array $observations = [];

    /** @var array<string, mixed> */
    protected array $coverage = [];

    /** @var array<string, string> raw availability xml by key */
    protected array $rawSamples = [];

    protected float $tolerance = 1.0;

    public function handle(RoundTripOfferSearchService $roundTrips, FlightOfferPresenter $presenter): int
    {
        $this->guard = new ReadOnlyCommandGuard;
        $this->tolerance = (float) $this->option('price-tolerance');
        $startedAt = now();

        VidecomClient::$commandGuard = function (string $command): void {
            $this->guard->check($command);
            $this->commandLog[] = $command;
        };

        // The round-trip search would normally fan out through the queue; run it
        // in-process instead so nothing else picks the session up.
        Bus::fake([ProcessRoundTripSearchJob::class]);

        $routes = collect(explode(',', (string) $this->option('routes')))
            ->map(fn (string $r): array => array_map('strtoupper', array_map('trim', explode('-', $r))))
            ->filter(fn (array $r): bool => count($r) === 2 && strlen($r[0]) === 3 && strlen($r[1]) === 3)
            ->values()->all();
        $offsets = array_map('intval', array_filter(explode(',', (string) $this->option('days')), 'strlen'));
        $stay = (int) $this->option('stay');
        $datePairs = array_map(fn (int $o): array => [
            now()->addDays($o)->toDateString(),
            now()->addDays($o + $stay)->toDateString(),
        ], $offsets);

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        try {
            foreach ($tenants as $tenant) {
                $tenant->run(function () use ($tenant, $routes, $datePairs, $roundTrips, $presenter): void {
                    $this->runTenant((string) $tenant->id, $routes, $datePairs, $roundTrips, $presenter);
                });

                if ($this->guard->violated) {
                    break;
                }
            }
        } catch (Throwable $e) {
            $this->error('Monitor aborted: '.$e->getMessage());
            $this->observations['aborted'] = $e->getMessage();
        } finally {
            VidecomClient::$commandGuard = null;
        }

        return $this->writeReport($startedAt, $routes, $datePairs);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $routes
     * @param  list<array{0: string, 1: string}>  $datePairs
     */
    protected function runTenant(string $tenantId, array $routes, array $datePairs, RoundTripOfferSearchService $roundTrips, FlightOfferPresenter $presenter): void
    {
        $providers = TenantProvider::query()
            ->where('is_active', true)
            ->where('provider_type', 'videcom')
            ->when($this->option('providers'), fn ($q, $ids) => $q->whereIn('id', array_map('intval', explode(',', $ids))))
            ->get();

        foreach ($providers as $provider) {
            // Fresh Videcom session per run.
            $this->clientFor(ProviderFactory::make($provider))->resetSession();
        }

        foreach ($routes as [$origin, $dest]) {
            $outDates = array_column($datePairs, 0);
            $retDates = array_column($datePairs, 1);
            $raw = [];   // [pid][dir] => ['flights' => [...], 'quotes' => [...]]
            $serving = [];

            foreach ($providers as $provider) {
                if (! $this->accountAllowsRoute($provider, $origin, $dest)) {
                    continue;
                }

                $airline = ProviderFactory::make($provider);
                $raw[$provider->id]['out'] = $this->rawLeg($tenantId, $provider, $airline, $origin, $dest, $outDates);
                $raw[$provider->id]['ret'] = $this->rawLeg($tenantId, $provider, $airline, $dest, $origin, $retDates);

                if ($this->guard->violated) {
                    return;
                }

                $this->coverage[] = [
                    'tenant' => $tenantId,
                    'provider_id' => $provider->id,
                    'airline' => $provider->airline_code,
                    'currency' => data_get($provider->credentials, 'currency'),
                    'route' => "{$origin}-{$dest}",
                    'out_flights' => count($raw[$provider->id]['out']['flights']),
                    'ret_flights' => count($raw[$provider->id]['ret']['flights']),
                    'out_error' => $raw[$provider->id]['out']['error'],
                    'ret_error' => $raw[$provider->id]['ret']['error'],
                ];

                if ($raw[$provider->id]['out']['flights'] !== [] || $raw[$provider->id]['ret']['flights'] !== []) {
                    $serving[] = $provider;
                }
            }

            foreach ($datePairs as [$date, $returnDate]) {
                foreach ($serving as $provider) {
                    $this->scenarioOneWay($tenantId, $provider, $origin, $dest, $date, $raw[$provider->id]['out'], $roundTrips, $presenter);
                }

                if ($serving !== []) {
                    $this->scenarioRoundTrip($tenantId, collect($serving), $origin, $dest, $date, $returnDate, $raw, $roundTrips);
                }
            }
        }
    }

    // ---------------------------------------------------------------- raw Videcom

    /**
     * @param  list<string>  $dates
     * @return array{flights: list<array<string, mixed>>, quotes: array<string, array<string, mixed>>, error: ?string}
     */
    protected function rawLeg(string $tenantId, TenantProvider $provider, BaseVidecomAirline $airline, string $origin, string $dest, array $dates): array
    {
        $client = $this->clientFor($airline);
        $result = ['flights' => [], 'quotes' => [], 'error' => null];
        $errors = [];
        $seen = [];

        // One ~X call per requested date: the ~X window does not reliably cover
        // every later date for every airline.
        foreach (array_values(array_unique($dates)) as $wanted) {
            $token = strtoupper(Carbon::parse($wanted)->format('dM'));
            $command = "A{$token}{$origin}{$dest}~X";

            try {
                $response = $client->runCommand($command);
            } catch (Throwable $e) {
                if ($this->guard->violated) {
                    throw $e;
                }
                $errors[] = "{$wanted}: ".substr($e->getMessage(), 0, 160);

                continue;
            }

            $this->rawSamples["{$tenantId}-{$provider->id}-{$provider->airline_code}-{$origin}{$dest}-{$wanted}"] = $response;
            $xml = $this->xml($response);

            if (! $xml instanceof SimpleXMLElement || ! isset($xml->Journeys)) {
                $errors[] = "{$wanted}: non-xml availability: ".$this->snippet($response);

                continue;
            }

            foreach ($xml->Journeys->Journey ?? [] as $journey) {
                foreach ($journey->Legs->BookFlightSegmentType ?? [] as $segment) {
                    $flt = (string) ($segment->FlightNumber ?? '');
                    if (preg_match('/^([A-Z]\d|\d[A-Z]|[A-Z]{2})(\d+)$/i', $flt, $m)) {
                        $flt = $m[2];
                    }
                    $dep = str_replace('T', ' ', (string) ($segment->XSDDepartureDateTime ?? $segment['DepartureDateTime'] ?? ''));
                    $day = substr($dep, 0, 10);

                    if (! in_array($day, $dates, true) || isset($seen["{$flt}|{$day}"])) {
                        continue;
                    }
                    $seen["{$flt}|{$day}"] = true;

                    $classes = [];
                    foreach ($segment->Availability->Class ?? [] as $class) {
                        $attrs = [];
                        foreach ($class->attributes() as $k => $v) {
                            $attrs[(string) $k] = (string) $v;
                        }
                        $classes[] = [
                            'class' => strtoupper((string) ($class['id'] ?? '')),
                            'av' => array_key_exists('av', $attrs) ? (int) $attrs['av'] : null,
                            'attrs' => $attrs,
                        ];
                    }

                    $result['flights'][] = [
                        'flight' => $flt,
                        'date' => $day,
                        'departure_time' => $dep,
                        'origin' => (string) ($segment->DepartureAirport['LocationCode'] ?? $origin),
                        'dest' => (string) ($segment->ArrivalAirport['LocationCode'] ?? $dest),
                        'classes' => $classes,
                    ];
                }
            }
        }

        $result['error'] = $errors === [] ? null : implode(' ; ', $errors);

        // Quote every class (NN1 and QQ1) up to the configured budget.
        $budget = (int) $this->option('max-quotes');
        foreach ($result['flights'] as $flight) {
            foreach ($flight['classes'] as $class) {
                if ($budget <= 1) {
                    break 2;
                }
                $key = $this->classKey($flight['flight'], $flight['date'], $class['class']);
                $result['quotes'][$key] = [
                    'NN' => $this->quote($client, $airline, [$flight + ['class' => $class['class']]], 'NN'),
                    'QQ' => $this->quote($client, $airline, [$flight + ['class' => $class['class']]], 'QQ'),
                    'cache_before' => [
                        'AD' => PricingCacheService::get($airline->getIataCode(), $flight['origin'], $flight['dest'], $class['class'], 'AD'),
                        'baggage' => PricingCacheService::getBaggage($airline->getIataCode(), $flight['origin'], $flight['dest'], $class['class']),
                    ],
                ];
                $budget -= 2;
            }
        }

        return $result;
    }

    /**
     * Read-only fare quote: fresh "i" entry, never ends a transaction.
     *
     * @param  list<array<string, mixed>>  $legs
     * @return array<string, mixed>
     */
    protected function quote(VidecomClient $client, BaseVidecomAirline $airline, array $legs, string $status): array
    {
        $entries = ['i', '-1TEST/PAXMR'];
        foreach ($legs as $leg) {
            $flt = str_pad(preg_replace('/\D+/', '', (string) $leg['flight']) ?: '0', 4, '0', STR_PAD_LEFT);
            $date = strtoupper(Carbon::parse($leg['date'])->format('dM'));
            $entries[] = "0{$airline->getIataCode()}{$flt}{$leg['class']}{$date}{$leg['origin']}{$leg['dest']}{$status}1";
        }
        $command = implode('^', array_merge($entries, ['FG', 'FS1', '*r~x']));

        try {
            $response = $client->runCommand($command);
        } catch (Throwable $e) {
            if ($this->guard->violated) {
                throw $e;
            }

            return ['ok' => false, 'total' => 0.0, 'error' => substr($e->getMessage(), 0, 160)];
        }

        $xml = $this->xml($response);
        if (! $xml instanceof SimpleXMLElement || ! isset($xml->FareQuote)) {
            return ['ok' => false, 'total' => 0.0, 'error' => $this->snippet($response)];
        }

        $segments = [];
        $baggage = null;
        foreach ($xml->xpath('//FareQuote/FareStore') ?: [] as $store) {
            if ((int) ($store['Pax'] ?? 0) !== 1) {
                continue;
            }
            foreach ($store->SegmentFS ?? [] as $seg) {
                $n = (int) ($seg['Seg'] ?? count($segments) + 1);
                $segments[$n] = round((float) ($seg['Fare'] ?? 0) + (float) ($seg['Tax1'] ?? 0) + (float) ($seg['Tax2'] ?? 0) + (float) ($seg['Tax3'] ?? 0), 2);
                $baggage ??= ['hold_weight' => (string) ($seg['HoldWt'] ?? ''), 'hand_weight' => (string) ($seg['HandWt'] ?? '')];
            }
        }
        if ($segments === []) {
            foreach ($xml->xpath('//FareQuote/FQItin') ?: [] as $i => $itin) {
                $segments[(int) ($itin['Seg'] ?? $i + 1)] = round((float) ($itin['Total'] ?? 0), 2);
            }
        }
        ksort($segments);
        $total = round(array_sum($segments), 2);

        return ['ok' => $total > 0, 'total' => $total, 'segments' => array_values($segments), 'baggage' => $baggage, 'error' => $total > 0 ? null : 'quote returned 0'];
    }

    // ---------------------------------------------------------------- scenarios

    /**
     * @param  array<string, mixed>  $rawOut
     */
    protected function scenarioOneWay(string $tenantId, TenantProvider $provider, string $origin, string $dest, string $date, array $rawOut, RoundTripOfferSearchService $roundTrips, FlightOfferPresenter $presenter): void
    {
        $scenario = 'one_way';
        $params = ['origin' => $origin, 'destination' => $dest, 'date' => $date, 'adults' => 1, 'children' => 0, 'infants' => 0, 'cabin_class' => 'all'];
        $cacheKey = "flight_avail:{$provider->id}:{$origin}:{$dest}:{$date}:1:0:0:all";
        Cache::forget($cacheKey);

        try {
            $flights = $presenter->presentMany($roundTrips->searchLegCached($provider, $origin, $dest, $date, $params), forApi: true);
        } catch (Throwable $e) {
            if ($this->guard->violated) {
                throw $e;
            }
            $this->issue($scenario, 'service_error', 'high', $tenantId, $provider, "{$origin}-{$dest}", $date, null, null, $e->getMessage());

            return;
        }

        $this->compareLeg($scenario, $tenantId, $provider, "{$origin}-{$dest}", $date, $flights, $rawOut, checkOpenRes: true);
    }

    /**
     * @param  list<array<string, mixed>>  $flights  presented service flights for one leg
     * @param  array<string, mixed>  $raw
     * @return array<string, array<string, mixed>> service flights keyed by class key
     */
    protected function compareLeg(string $scenario, string $tenantId, TenantProvider $provider, string $route, string $date, array $flights, array $raw, bool $checkOpenRes = false): array
    {
        $byKey = [];
        foreach ($flights as $f) {
            $byKey[$this->classKey((string) ($f['flight_number'] ?? ''), substr((string) ($f['departure_time'] ?? ''), 0, 10), (string) data_get($f, 'segments.0.class', data_get($f, 'pricing.class_code', '')))] = $f;
        }

        $rawClasses = 0;
        $pricedRaw = 0;
        foreach ($raw['flights'] as $rf) {
            if ($rf['date'] !== $date) {
                continue;
            }
            foreach ($rf['classes'] as $rc) {
                $rawClasses++;
                $key = $this->classKey($rf['flight'], $rf['date'], $rc['class']);
                $q = $raw['quotes'][$key] ?? null;
                $rawPrice = $q ? ($q['NN']['ok'] ? $q['NN']['total'] : ($q['QQ']['ok'] ? $q['QQ']['total'] : 0.0)) : null;
                $pricedRaw += $rawPrice > 0 ? 1 : 0;
                $svc = $byKey[$key] ?? null;
                $detailBase = ['av' => $rc['av'], 'raw_nn' => $q['NN'] ?? null, 'raw_qq' => $q['QQ'] ?? null];

                if ($q !== null) {
                    $this->observations['nn_qq_matrix'][($q['NN']['ok'] ? 'NN_ok' : 'NN_fail').'/'.($q['QQ']['ok'] ? 'QQ_ok' : 'QQ_fail').'/av'.(($rc['av'] ?? 0) >= 2 ? '>=2' : (string) ($rc['av'] ?? 'null'))][] = "{$provider->airline_code} {$rf['flight']} {$rc['class']} {$rf['date']}";
                }

                if ($svc === null) {
                    $sev = $rawPrice > 0 ? 'high' : 'info';
                    $type = $q === null ? 'missing_class_not_quoted' : ($rawPrice > 0 ? 'missing_class' : 'missing_class_unpriced_in_videcom');
                    $this->issue($scenario, $type, $sev, $tenantId, $provider, $route, $date, $rf['flight'], $rc['class'], $detailBase);

                    continue;
                }

                $svcPrice = (float) data_get($svc, 'pricing.total', 0);
                $svcSeats = $svc['available_seats'] ?? null;

                if ($svcPrice <= 0) {
                    $this->issue($scenario, 'price_zero_leg', 'high', $tenantId, $provider, $route, $date, $rf['flight'], $rc['class'], $detailBase);
                } elseif ($rawPrice > 0 && abs($svcPrice - $rawPrice) > $this->tolerance) {
                    $this->issue($scenario, 'price_mismatch_vs_videcom', 'high', $tenantId, $provider, $route, $date, $rf['flight'], $rc['class'], $detailBase + ['service' => $svcPrice, 'videcom' => $rawPrice]);
                } elseif ($rawPrice !== null && $rawPrice <= 0) {
                    $cached = data_get($q, 'cache_before.AD');
                    $this->issue($scenario, 'priced_from_cache_fallback', 'info', $tenantId, $provider, $route, $date, $rf['flight'], $rc['class'], $detailBase + ['service' => $svcPrice, 'cache_before' => $cached]);
                }

                if ($svcSeats !== null && $rc['av'] !== null && (int) $svcSeats !== (int) $rc['av']) {
                    $this->issue($scenario, 'seat_mismatch', 'medium', $tenantId, $provider, $route, $date, $rf['flight'], $rc['class'], ['service' => $svcSeats, 'videcom_av' => $rc['av']]);
                }

                if (data_get($svc, 'baggage.hold_weight') === null && data_get($svc, 'baggage.hand_weight') === null) {
                    $this->issue($scenario, 'null_baggage', 'medium', $tenantId, $provider, $route, $date, $rf['flight'], $rc['class'], ['raw_baggage' => $q['NN']['baggage'] ?? $q['QQ']['baggage'] ?? null]);
                }

                if ($checkOpenRes && $q !== null) {
                    try {
                        $allowed = ProviderFactory::make($provider)->canBookOpenReservation([
                            'origin' => $rf['origin'], 'dest' => $rf['dest'], 'class' => $rc['class'],
                            'date' => $rf['date'], 'flt_no' => $rf['flight'],
                        ]);
                        if ($allowed !== (bool) $q['QQ']['ok']) {
                            $this->issue($scenario, 'open_reservation_mismatch', 'medium', $tenantId, $provider, $route, $date, $rf['flight'], $rc['class'], ['app_allowed' => $allowed, 'videcom_qq_ok' => $q['QQ']['ok'], 'qq_error' => $q['QQ']['error'] ?? null]);
                        }
                    } catch (Throwable $e) {
                        if ($this->guard->violated) {
                            throw $e;
                        }
                    }
                }
            }
        }

        $this->observations['legs'][] = compact('scenario', 'route', 'date') + ['airline' => $provider->airline_code, 'provider_id' => $provider->id, 'raw_classes' => $rawClasses, 'raw_priced' => $pricedRaw, 'service_classes' => count($flights)];

        return $byKey;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TenantProvider>  $serving
     * @param  array<int, array<string, array<string, mixed>>>  $raw
     */
    protected function scenarioRoundTrip(string $tenantId, $serving, string $origin, string $dest, string $date, string $returnDate, array $raw, RoundTripOfferSearchService $roundTrips): void
    {
        foreach ($serving as $p) {
            Cache::forget("flight_avail:{$p->id}:{$origin}:{$dest}:{$date}:1:0:0:all");
            Cache::forget("flight_avail:{$p->id}:{$dest}:{$origin}:{$returnDate}:1:0:0:all");
        }

        $route = "{$origin}-{$dest}";
        try {
            $session = $roundTrips->start([
                'origin' => $origin, 'destination' => $dest, 'date' => $date, 'return_date' => $returnDate,
                'adults' => 1, 'children' => 0, 'infants' => 0, 'cabin_class' => 'all', 'cheap_only' => false,
                'provider_ids' => $serving->pluck('id')->all(),
            ]);
            foreach ($session['providers'] as $p) {
                $roundTrips->results($session['uuid'], (int) $p['id']);
            }
            $offers = $roundTrips->results($session['uuid'])['offers'] ?? [];
        } catch (Throwable $e) {
            if ($this->guard->violated) {
                throw $e;
            }
            $this->issue('round_trip', 'service_error', 'high', $tenantId, null, $route, $date, null, null, $e->getMessage());

            return;
        }

        $byId = $serving->keyBy('id');
        $counts = [];
        $rtQuoted = [];

        foreach ($offers as $offer) {
            $outPid = (int) data_get($offer, 'legs.0.provider_id');
            $retPid = (int) data_get($offer, 'legs.1.provider_id');
            $scenario = $outPid === $retPid ? 'round_trip_same_provider' : 'round_trip_combined';
            $pair = data_get($offer, 'legs.0.airline_code').'+'.data_get($offer, 'legs.1.airline_code');
            $counts[$scenario][$pair] = ($counts[$scenario][$pair] ?? 0) + 1;
            $provider = $byId[$outPid] ?? null;
            $out = data_get($offer, 'itineraries.0', []);
            $ret = data_get($offer, 'itineraries.1', []);
            $flightLabel = ($out['flight_number'] ?? '?').'/'.($ret['flight_number'] ?? '?');
            $classLabel = data_get($out, 'segments.0.class', '?').'/'.data_get($ret, 'segments.0.class', '?');
            $pr = $offer['pricing'] ?? [];

            if (($pr['method'] ?? null) !== 'roundtrip') {
                $this->issue($scenario, 'method_not_roundtrip', 'high', $tenantId, $provider, $route, $date, $flightLabel, $classLabel, ['method' => $pr['method'] ?? null, 'pair' => $pair]);
            }
            if ((float) ($pr['return_total'] ?? 0) <= 0 || (float) data_get($ret, 'pricing.total', 0) <= 0) {
                $this->issue($scenario, 'return_total_zero', 'high', $tenantId, $provider, $route, $date, $flightLabel, $classLabel, ['pricing' => $pr, 'pair' => $pair]);
            }
            if ((float) ($pr['outbound_total'] ?? 0) <= 0) {
                $this->issue($scenario, 'outbound_total_zero', 'high', $tenantId, $provider, $route, $date, $flightLabel, $classLabel, ['pricing' => $pr, 'pair' => $pair]);
            }
            if (abs((float) ($pr['total'] ?? 0) - (float) ($pr['outbound_total'] ?? 0) - (float) ($pr['return_total'] ?? 0)) > 0.01) {
                $this->issue($scenario, 'total_not_sum_of_legs', 'high', $tenantId, $provider, $route, $date, $flightLabel, $classLabel, ['pricing' => $pr]);
            }
            foreach (['outbound' => $out, 'return' => $ret] as $legName => $leg) {
                if (data_get($leg, 'baggage.hold_weight') === null && data_get($leg, 'baggage.hand_weight') === null) {
                    $this->issue($scenario, "null_baggage_{$legName}", 'medium', $tenantId, $provider, $route, $date, $flightLabel, $classLabel, ['pair' => $pair]);
                }
            }

            // Expected price from raw Videcom.
            $outRaw = $raw[$outPid]['out']['quotes'][$this->classKey((string) ($out['flight_number'] ?? ''), substr((string) ($out['departure_time'] ?? ''), 0, 10), (string) data_get($out, 'segments.0.class'))] ?? null;
            $retRaw = $raw[$retPid]['ret']['quotes'][$this->classKey((string) ($ret['flight_number'] ?? ''), substr((string) ($ret['departure_time'] ?? ''), 0, 10), (string) data_get($ret, 'segments.0.class'))] ?? null;
            $best = fn (?array $q): float => $q ? ($q['NN']['ok'] ? $q['NN']['total'] : ($q['QQ']['ok'] ? $q['QQ']['total'] : 0.0)) : 0.0;

            if ($scenario === 'round_trip_combined') {
                $exp = [$best($outRaw), $best($retRaw)];
                foreach (['outbound_total' => 0, 'return_total' => 1] as $field => $i) {
                    if ($exp[$i] > 0 && abs((float) ($pr[$field] ?? 0) - $exp[$i]) > $this->tolerance) {
                        $this->issue($scenario, "price_mismatch_{$field}", 'high', $tenantId, $provider, $route, $date, $flightLabel, $classLabel, ['service' => $pr[$field] ?? null, 'videcom_oneway' => $exp[$i], 'pair' => $pair]);
                    }
                }
            } elseif ($provider !== null && count($rtQuoted[$outPid] ?? []) < 3) {
                // Raw same-PNR round-trip quote for up to 3 offers per provider.
                $airline = ProviderFactory::make($provider);
                $legs = [
                    ['flight' => $out['flight_number'] ?? '', 'date' => substr((string) $out['departure_time'], 0, 10), 'origin' => $origin, 'dest' => $dest, 'class' => data_get($out, 'segments.0.class')],
                    ['flight' => $ret['flight_number'] ?? '', 'date' => substr((string) $ret['departure_time'], 0, 10), 'origin' => $dest, 'dest' => $origin, 'class' => data_get($ret, 'segments.0.class')],
                ];
                $rt = $this->quote($this->clientFor($airline), $airline, $legs, 'NN');
                $rt['status'] = 'NN';
                if (! $rt['ok']) {
                    $nnError = $rt['error'] ?? null;
                    $rt = $this->quote($this->clientFor($airline), $airline, $legs, 'QQ') + ['nn_error' => $nnError];
                    $rt['status'] = 'QQ';
                }
                $rtQuoted[$outPid][] = $rt;
                if ($rt['ok'] && abs((float) ($pr['total'] ?? 0) - $rt['total']) > $this->tolerance) {
                    $this->issue($scenario, 'price_mismatch_vs_videcom_rt_quote', 'high', $tenantId, $provider, $route, $date, $flightLabel, $classLabel, ['service' => $pr, 'videcom_rt' => $rt, 'oneway_sum' => $best($outRaw) + $best($retRaw)]);
                } elseif (! $rt['ok']) {
                    $this->issue($scenario, 'videcom_rt_quote_failed', 'info', $tenantId, $provider, $route, $date, $flightLabel, $classLabel, ['service' => $pr, 'videcom_rt' => $rt]);
                }
            }

            foreach ([[$out, $outRaw, $raw[$outPid]['out']['flights'] ?? []], [$ret, $retRaw, $raw[$retPid]['ret']['flights'] ?? []]] as [$leg, $q, $rawFlights]) {
                foreach ($rawFlights as $rf) {
                    if ($rf['flight'] !== ($leg['flight_number'] ?? null) || $rf['date'] !== substr((string) ($leg['departure_time'] ?? ''), 0, 10)) {
                        continue;
                    }
                    foreach ($rf['classes'] as $rc) {
                        if ($rc['class'] === data_get($leg, 'segments.0.class') && $rc['av'] !== null && (int) ($leg['available_seats'] ?? -1) !== $rc['av']) {
                            $this->issue($scenario, 'seat_mismatch', 'medium', $tenantId, $provider, $route, $date, $flightLabel, $classLabel, ['service' => $leg['available_seats'] ?? null, 'videcom_av' => $rc['av']]);
                        }
                    }
                }
            }
        }

        // Missing classes / providers in round-trip output vs raw.
        foreach ($serving as $p) {
            foreach (['out' => [$date, 0], 'ret' => [$returnDate, 1]] as $dir => [$d, $idx]) {
                $present = [];
                foreach ($offers as $offer) {
                    if ((int) data_get($offer, "legs.{$idx}.provider_id") === (int) $p->id) {
                        $it = data_get($offer, "itineraries.{$idx}", []);
                        $present[$this->classKey((string) ($it['flight_number'] ?? ''), substr((string) ($it['departure_time'] ?? ''), 0, 10), (string) data_get($it, 'segments.0.class'))] = true;
                    }
                }
                foreach ($raw[$p->id][$dir]['flights'] ?? [] as $rf) {
                    if ($rf['date'] !== $d) {
                        continue;
                    }
                    foreach ($rf['classes'] as $rc) {
                        $key = $this->classKey($rf['flight'], $rf['date'], $rc['class']);
                        $q = $raw[$p->id][$dir]['quotes'][$key] ?? null;
                        $priced = $q && ($q['NN']['ok'] || $q['QQ']['ok']);
                        if (! isset($present[$key]) && $priced) {
                            $this->issue('round_trip', "missing_class_{$dir}", 'high', $tenantId, $p, $dir === 'out' ? $route : "{$dest}-{$origin}", $d, $rf['flight'], $rc['class'], ['av' => $rc['av'], 'nn_ok' => $q['NN']['ok'], 'qq_ok' => $q['QQ']['ok'], 'videcom_price' => $q['NN']['ok'] ? $q['NN']['total'] : $q['QQ']['total']]);
                        }
                    }
                }
            }
        }

        $this->observations['round_trip_offer_counts'][] = ['route' => $route, 'date' => $date, 'return_date' => $returnDate, 'counts' => $counts, 'total_offers' => count($offers)];
    }

    // ---------------------------------------------------------------- helpers

    protected function clientFor(BaseVidecomAirline $airline): VidecomClient
    {
        return (fn () => $this->client)->call($airline);
    }

    protected function accountAllowsRoute(TenantProvider $provider, string $origin, string $dest): bool
    {
        $airports = array_map('strtoupper', (array) data_get($provider->credentials, 'airports', []));

        return $airports === [] || in_array($origin, $airports, true) || in_array($dest, $airports, true);
    }

    protected function classKey(string $flight, string $date, string $class): string
    {
        return ltrim(preg_replace('/\D+/', '', $flight) ?? '', '0').'|'.$date.'|'.strtoupper(substr($class, 0, 1));
    }

    protected function xml(string $payload): ?SimpleXMLElement
    {
        $payload = trim($payload);
        if ($payload === '' || $payload[0] !== '<') {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($payload) ?: null;
        libxml_use_internal_errors($prev);

        return $xml;
    }

    protected function snippet(string $s): string
    {
        return substr(trim(preg_replace('/\s+/', ' ', strip_tags($s)) ?? ''), 0, 160);
    }

    protected function issue(string $scenario, string $type, string $severity, string $tenantId, ?TenantProvider $provider, string $route, string $date, ?string $flight, ?string $class, mixed $detail): void
    {
        $this->issues[] = [
            'id' => implode('|', [$scenario, $type, $tenantId, $provider?->id ?? '-', $route, $date, $flight ?? '-', $class ?? '-']),
            'scenario' => $scenario,
            'type' => $type,
            'severity' => $severity,
            'tenant' => $tenantId,
            'provider_id' => $provider?->id,
            'airline' => $provider?->airline_code,
            'route' => $route,
            'date' => $date,
            'flight' => $flight,
            'class' => $class,
            'detail' => $detail,
        ];
    }

    // ---------------------------------------------------------------- report

    /**
     * @param  list<array{0: string, 1: string}>  $routes
     * @param  list<array{0: string, 1: string}>  $datePairs
     */
    protected function writeReport(\Carbon\CarbonInterface $startedAt, array $routes, array $datePairs): int
    {
        $dir = base_path('storage/app/videcom-monitor');
        @mkdir($dir.'/raw', 0775, true);
        $stamp = $startedAt->format('Ymd-His');

        // Dedupe issues by id (one-way re-runs etc.).
        $issues = array_values(collect($this->issues)->keyBy('id')->all());

        $previous = collect(glob($dir.'/*.json') ?: [])->sort()->last();
        $prev = $previous ? json_decode((string) file_get_contents($previous), true) : null;
        $prevIds = collect($prev['issues'] ?? [])->pluck('id')->all();
        $ids = array_column($issues, 'id');
        $diff = [
            'previous_report' => $previous ? basename($previous) : null,
            'fixed' => array_values(array_diff($prevIds, $ids)),
            'new' => array_values(array_diff($ids, $prevIds)),
            'unchanged' => array_values(array_intersect($ids, $prevIds)),
        ];

        // av hypothesis observation: distribution of av values + NN/QQ outcomes.
        $matrix = collect($this->observations['nn_qq_matrix'] ?? [])->map(fn ($l) => ['count' => count($l), 'examples' => array_slice($l, 0, 6)])->all();

        foreach ($this->rawSamples as $k => $xml) {
            file_put_contents("{$dir}/raw/{$stamp}-{$k}.xml", $xml);
        }

        $report = [
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'routes' => array_map(fn ($r) => implode('-', $r), $routes),
            'date_pairs' => $datePairs,
            'aborted' => $this->observations['aborted'] ?? null,
            'safety' => [
                'violations' => $this->guard->violations,
                'command_counts' => $this->guard->counts,
                'commands_sent' => count($this->commandLog),
                'command_prefixes' => collect($this->commandLog)->map(fn ($c) => preg_replace('/\d.*$/', '', explode('^', $c)[0]))->countBy()->all(),
            ],
            'coverage' => $this->coverage,
            'summary' => collect($issues)->groupBy('scenario')->map(fn ($g) => $g->countBy('type')->all())->all(),
            'nn_qq_av_matrix' => $matrix,
            'legs' => $this->observations['legs'] ?? [],
            'round_trip_offer_counts' => $this->observations['round_trip_offer_counts'] ?? [],
            'diff' => $diff,
            'issues' => $issues,
            'commands' => $this->commandLog,
        ];

        file_put_contents("{$dir}/{$stamp}.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        file_put_contents("{$dir}/{$stamp}.md", $this->markdown($report));

        $this->info("Report: storage/app/videcom-monitor/{$stamp}.md (".count($issues).' issues, '.count($diff['new']).' new, '.count($diff['fixed']).' fixed)');

        return $this->guard->violated ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $r
     */
    protected function markdown(array $r): string
    {
        $md = "# Videcom monitor report\n\nStarted {$r['started_at']}, finished {$r['finished_at']}\n\n";
        $md .= 'Routes: '.implode(', ', $r['routes']).' | Dates: '.implode(', ', array_map(fn ($p) => "{$p[0]} → {$p[1]}", $r['date_pairs']))."\n\n";
        if ($r['aborted']) {
            $md .= "**ABORTED:** {$r['aborted']}\n\n";
        }
        $md .= "## Safety\n\nCommands sent: {$r['safety']['commands_sent']} — ".json_encode($r['safety']['command_counts']).' — violations: '.count($r['safety']['violations'])."\n\n";
        $md .= "## Coverage\n\n| Tenant | Provider | Airline | Cur | Route | Out flights | Ret flights | Error |\n|---|---|---|---|---|---|---|---|\n";
        foreach ($r['coverage'] as $c) {
            $md .= "| {$c['tenant']} | {$c['provider_id']} | {$c['airline']} | {$c['currency']} | {$c['route']} | {$c['out_flights']} | {$c['ret_flights']} | ".trim(($c['out_error'] ?? '').' '.($c['ret_error'] ?? ''))." |\n";
        }
        $md .= "\n## Findings per scenario\n\n| Scenario | Issue type | Count |\n|---|---|---|\n";
        foreach ($r['summary'] as $scenario => $types) {
            foreach ($types as $type => $n) {
                $md .= "| {$scenario} | {$type} | {$n} |\n";
            }
        }
        $md .= "\n## Raw NN1 / QQ1 / av outcomes\n\n| Outcome | Count | Examples |\n|---|---|---|\n";
        foreach ($r['nn_qq_av_matrix'] as $k => $v) {
            $md .= "| {$k} | {$v['count']} | ".implode('; ', $v['examples'])." |\n";
        }
        $md .= "\n## Legs (raw classes vs service classes)\n\n| Scenario | Airline | Route | Date | Raw classes | Raw priced | Service classes |\n|---|---|---|---|---|---|---|\n";
        foreach ($r['legs'] as $l) {
            $md .= "| {$l['scenario']} | {$l['airline']} ({$l['provider_id']}) | {$l['route']} | {$l['date']} | {$l['raw_classes']} | {$l['raw_priced']} | {$l['service_classes']} |\n";
        }
        $md .= "\n## Round-trip offers\n\n";
        foreach ($r['round_trip_offer_counts'] as $c) {
            $md .= "- {$c['route']} {$c['date']} → {$c['return_date']}: {$c['total_offers']} offers ".json_encode($c['counts'])."\n";
        }
        $d = $r['diff'];
        $md .= "\n## Diff vs previous (".($d['previous_report'] ?? 'none').")\n\nFixed: ".count($d['fixed']).' | New: '.count($d['new']).' | Unchanged: '.count($d['unchanged'])."\n\n";
        foreach (['fixed', 'new'] as $k) {
            foreach (array_slice($d[$k], 0, 40) as $id) {
                $md .= "- {$k}: `{$id}`\n";
            }
        }
        $md .= "\n## Issues (high first, max 80)\n\n| Sev | Scenario | Type | Airline | Route | Date | Flight | Class | Detail |\n|---|---|---|---|---|---|---|---|---|\n";
        $order = ['high' => 0, 'medium' => 1, 'info' => 2];
        $issues = collect($r['issues'])->sortBy(fn ($i) => $order[$i['severity']] ?? 3)->take(80);
        foreach ($issues as $i) {
            $detail = str_replace('|', '/', substr(json_encode($i['detail']), 0, 220));
            $md .= "| {$i['severity']} | {$i['scenario']} | {$i['type']} | {$i['airline']} | {$i['route']} | {$i['date']} | {$i['flight']} | {$i['class']} | {$detail} |\n";
        }

        return $md;
    }
}
