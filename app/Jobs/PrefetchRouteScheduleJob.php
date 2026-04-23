<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Services\Airline\ProviderFactory;
use App\Services\GlobalCache\FlightScheduleCacheService;
use App\Services\GlobalCache\RouteAvailabilityService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PrefetchRouteScheduleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $tenantId,
        public int $providerId,
        public string $airlineCode,
        public string $origin,
        public string $destination,
        public int $days = 30,
    ) {}

    public function handle(RouteAvailabilityService $routeAvailabilityService, FlightScheduleCacheService $flightScheduleCacheService): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant) {
            return;
        }

        $tenant->run(function () use ($routeAvailabilityService, $flightScheduleCacheService): void {
            $providerConfig = TenantProvider::query()->find($this->providerId);

            if (! $providerConfig || ! $providerConfig->is_active || $providerConfig->provider_type !== 'videcom') {
                return;
            }

            $provider = ProviderFactory::make($providerConfig);

            for ($offset = 0; $offset < $this->days; $offset++) {
                $date = Carbon::today()->addDays($offset)->toDateString();

                $params = [
                    'origin' => strtoupper($this->origin),
                    'destination' => strtoupper($this->destination),
                    'date' => $date,
                    'adults' => 1,
                    'children' => 0,
                    'infants' => 0,
                    'qty' => 1,
                    'is_return' => false,
                ];

                $flights = collect($provider->searchAvailability($params));

                $routeAvailabilityService->recordResult(
                    $this->airlineCode,
                    $this->origin,
                    $this->destination,
                    $flights->isNotEmpty(),
                );

                foreach ($flights as $flight) {
                    $flightData = is_array($flight) ? $flight : (array) $flight;
                    $segment = data_get($flightData, 'segments.0', []);

                    $bookingClass = (string) ($segment['class'] ?? data_get($flightData, 'pricing.class_code', ''));
                    $price = (float) data_get($flightData, 'pricing.total', 0);
                    $currency = (string) data_get($flightData, 'pricing.currency', 'LYD');

                    if ($price <= 0) {
                        continue;
                    }

                    $flightScheduleCacheService->storePrice(
                        airlineCode: $this->airlineCode,
                        origin: $this->origin,
                        destination: $this->destination,
                        date: $date,
                        bookingClass: $bookingClass !== '' ? $bookingClass : null,
                        price: $price,
                        currency: $currency,
                        ttlHours: 24,
                    );
                }
            }
        });
    }
}
