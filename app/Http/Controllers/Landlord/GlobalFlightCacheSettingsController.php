<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\FlightScheduleCache;
use App\Models\RouteAvailabilityCache;
use App\Services\GlobalCache\GlobalFlightCacheSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class GlobalFlightCacheSettingsController extends Controller
{
    public function index(GlobalFlightCacheSettingsService $settingsService): Response
    {
        $routeEntries = RouteAvailabilityCache::query()
            ->orderBy('airline_code')
            ->orderBy('origin')
            ->orderBy('destination')
            ->get([
                'airline_code',
                'origin',
                'destination',
                'has_flights',
                'last_seen_at',
                'last_checked_at',
                'consecutive_empty',
            ]);

        $scheduleRouteEntries = FlightScheduleCache::query()
            ->selectRaw('airline_code, origin, destination, COUNT(*) as cached_entries, MIN(flight_date) as first_cached_date, MAX(flight_date) as last_cached_date')
            ->groupBy('airline_code', 'origin', 'destination')
            ->orderBy('airline_code')
            ->orderBy('origin')
            ->orderBy('destination')
            ->get();

        return Inertia::render('Landlord/FlightCache', [
            'flightCacheSettings' => [
                'route_availability_enabled' => $settingsService->isRouteAvailabilityEnabled(),
                'schedule_cache_enabled' => $settingsService->isScheduleCacheEnabled(),
            ],
            'flightCacheSummary' => $this->buildSummary($routeEntries, $scheduleRouteEntries),
        ]);
    }

    public function update(Request $request, GlobalFlightCacheSettingsService $settingsService): RedirectResponse
    {
        $validated = $request->validate([
            'route_availability_enabled' => 'required|boolean',
            'schedule_cache_enabled' => 'required|boolean',
        ]);

        $settingsService->setRouteAvailabilityEnabled((bool) $validated['route_availability_enabled']);
        $settingsService->setScheduleCacheEnabled((bool) $validated['schedule_cache_enabled']);

        return back()->with('success', 'Global flight cache settings updated.');
    }

    private function buildSummary(Collection $routeEntries, Collection $scheduleRouteEntries): array
    {
        $routeEntriesByProvider = $routeEntries->groupBy('airline_code');
        $scheduleEntriesByProvider = $scheduleRouteEntries->groupBy('airline_code');

        $providerCodes = $routeEntries->pluck('airline_code')
            ->merge($scheduleRouteEntries->pluck('airline_code'))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $providers = $providerCodes->map(function (string $airlineCode) use ($routeEntriesByProvider, $scheduleEntriesByProvider): array {
            $providerRouteEntries = $routeEntriesByProvider->get($airlineCode, collect());
            $providerScheduleEntries = $scheduleEntriesByProvider->get($airlineCode, collect());

            return [
                'airline_code' => $airlineCode,
                'learned_route_count' => $providerRouteEntries->count(),
                'available_route_count' => $providerRouteEntries->where('has_flights', true)->count(),
                'inactive_route_count' => $providerRouteEntries->where('has_flights', false)->count(),
                'cached_provider_route_count' => $providerScheduleEntries->count(),
                'cached_fare_entry_count' => (int) $providerScheduleEntries->sum(fn ($entry): int => (int) $entry->cached_entries),
                'first_cached_date' => $this->formatDate($providerScheduleEntries->min('first_cached_date')),
                'last_cached_date' => $this->formatDate($providerScheduleEntries->max('last_cached_date')),
                'available_routes' => $providerRouteEntries
                    ->where('has_flights', true)
                    ->map(fn (RouteAvailabilityCache $entry): array => [
                        'route' => sprintf('%s-%s', $entry->origin, $entry->destination),
                        'last_seen_at' => $this->formatDateTime($entry->last_seen_at),
                        'last_checked_at' => $this->formatDateTime($entry->last_checked_at),
                    ])
                    ->values()
                    ->all(),
                'inactive_routes' => $providerRouteEntries
                    ->where('has_flights', false)
                    ->map(fn (RouteAvailabilityCache $entry): array => [
                        'route' => sprintf('%s-%s', $entry->origin, $entry->destination),
                        'consecutive_empty' => (int) $entry->consecutive_empty,
                        'last_checked_at' => $this->formatDateTime($entry->last_checked_at),
                    ])
                    ->values()
                    ->all(),
                'cached_routes' => $providerScheduleEntries
                    ->map(fn ($entry): array => [
                        'route' => sprintf('%s-%s', $entry->origin, $entry->destination),
                        'cached_entries' => (int) $entry->cached_entries,
                        'first_cached_date' => $this->formatDate($entry->first_cached_date),
                        'last_cached_date' => $this->formatDate($entry->last_cached_date),
                    ])
                    ->values()
                    ->all(),
            ];
        })->sortByDesc('learned_route_count')->values()->all();

        return [
            'overview' => [
                'providers_with_route_data' => $routeEntries->pluck('airline_code')->unique()->count(),
                'providers_with_schedule_data' => $scheduleRouteEntries->pluck('airline_code')->unique()->count(),
                'learned_routes' => $routeEntries->count(),
                'available_routes' => $routeEntries->where('has_flights', true)->count(),
                'inactive_routes' => $routeEntries->where('has_flights', false)->count(),
                'cached_provider_routes' => $scheduleRouteEntries->count(),
                'cached_fare_entries' => (int) $scheduleRouteEntries->sum(fn ($entry): int => (int) $entry->cached_entries),
                'first_cached_date' => $this->formatDate($scheduleRouteEntries->min('first_cached_date')),
                'last_cached_date' => $this->formatDate($scheduleRouteEntries->max('last_cached_date')),
            ],
            'providers' => $providers,
        ];
    }

    private function formatDateTime(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return (string) $value->format('Y-m-d H:i');
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }
}
