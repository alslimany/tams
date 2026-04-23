<?php

namespace App\Console\Commands;

use App\Jobs\PrefetchRouteScheduleJob;
use App\Models\RouteAvailabilityCache;
use App\Services\Airline\AirlineProviderLocatorService;
use App\Services\GlobalCache\GlobalFlightCacheSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class CacheFlightSchedulesCommand extends Command
{
    protected $signature = 'flight:cache-schedules
        {--route= : Route filter in ORIGIN-DEST format, e.g. MJI-BEN}
        {--airline= : Airline IATA code filter, e.g. UZ}
        {--days=30 : Number of days to prefetch starting from today}';

    protected $description = 'Pre-fetch daily lowest route prices for known active airline routes';

    public function handle(
        AirlineProviderLocatorService $providerLocator,
        GlobalFlightCacheSettingsService $settingsService,
    ): int {
        if (! $settingsService->isScheduleCacheEnabled()) {
            $this->warn('Schedule cache is disabled by landlord settings.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));

        $query = RouteAvailabilityCache::query()
            ->where('has_flights', true)
            ->where('last_seen_at', '>=', now()->subDays(30));

        $airlineOption = $this->option('airline');
        if (is_string($airlineOption) && trim($airlineOption) !== '') {
            $query->where('airline_code', strtoupper(trim($airlineOption)));
        }

        $routeOption = $this->option('route');
        if (is_string($routeOption) && trim($routeOption) !== '') {
            [$origin, $destination] = array_pad(explode('-', strtoupper(trim($routeOption)), 2), 2, null);

            if (! $origin || ! $destination || strlen($origin) !== 3 || strlen($destination) !== 3) {
                $this->error('Invalid --route format. Expected ORIGIN-DEST, e.g. MJI-BEN.');

                return self::FAILURE;
            }

            $query
                ->where('origin', $origin)
                ->where('destination', $destination);
        }

        $routes = $query
            ->get(['airline_code', 'origin', 'destination'])
            ->unique(fn (RouteAvailabilityCache $entry): string => implode(':', [
                $entry->airline_code,
                $entry->origin,
                $entry->destination,
            ]))
            ->values();

        if ($routes->isEmpty()) {
            $this->info('No eligible route records found for schedule prefetch.');

            return self::SUCCESS;
        }

        $jobs = [];

        foreach ($routes as $route) {
            $providerContext = $providerLocator->locateActiveVidecomProvider((string) $route->airline_code);

            if (! $providerContext) {
                continue;
            }

            $jobs[] = new PrefetchRouteScheduleJob(
                tenantId: $providerContext['tenant_id'],
                providerId: $providerContext['provider_id'],
                airlineCode: (string) $route->airline_code,
                origin: (string) $route->origin,
                destination: (string) $route->destination,
                days: $days,
            );
        }

        if ($jobs === []) {
            $this->warn('No active tenant provider credentials were found for the selected routes.');

            return self::SUCCESS;
        }

        Bus::batch($jobs)
            ->name('flight-schedule-cache-prefetch')
            ->dispatch();

        $this->info(sprintf('Dispatched %d schedule prefetch jobs.', count($jobs)));

        return self::SUCCESS;
    }
}
