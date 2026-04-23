<?php

use App\Models\FlightScheduleCache;
use App\Models\LandlordSetting;
use App\Models\LandlordUser;
use App\Models\RouteAvailabilityCache;
use App\Services\GlobalCache\GlobalFlightCacheSettingsService;
use Illuminate\Support\Facades\Hash;

test('landlord can view flight cache summary', function () {
    $landlord = LandlordUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    LandlordSetting::query()->create([
        'key' => GlobalFlightCacheSettingsService::KEY_SCHEDULE_CACHE_ENABLED,
        'value' => ['enabled' => false],
    ]);

    RouteAvailabilityCache::query()->create([
        'airline_code' => 'YI',
        'origin' => 'MJI',
        'destination' => 'BEN',
        'has_flights' => true,
        'last_seen_at' => now(),
        'last_checked_at' => now(),
    ]);

    RouteAvailabilityCache::query()->create([
        'airline_code' => 'YI',
        'origin' => 'MJI',
        'destination' => 'TIP',
        'has_flights' => false,
        'consecutive_empty' => 3,
        'last_checked_at' => now(),
    ]);

    RouteAvailabilityCache::query()->create([
        'airline_code' => 'UZ',
        'origin' => 'BEN',
        'destination' => 'MJI',
        'has_flights' => true,
        'last_seen_at' => now(),
        'last_checked_at' => now(),
    ]);

    FlightScheduleCache::query()->create([
        'airline_code' => 'YI',
        'origin' => 'MJI',
        'destination' => 'BEN',
        'flight_date' => '2026-05-01',
        'booking_class' => 'Y',
        'lowest_price' => 120,
        'currency' => 'LYD',
        'expires_at' => now()->addDay(),
    ]);

    FlightScheduleCache::query()->create([
        'airline_code' => 'YI',
        'origin' => 'MJI',
        'destination' => 'BEN',
        'flight_date' => '2026-05-02',
        'booking_class' => 'Y',
        'lowest_price' => 130,
        'currency' => 'LYD',
        'expires_at' => now()->addDay(),
    ]);

    FlightScheduleCache::query()->create([
        'airline_code' => 'UZ',
        'origin' => 'BEN',
        'destination' => 'MJI',
        'flight_date' => '2026-05-03',
        'booking_class' => 'Y',
        'lowest_price' => 140,
        'currency' => 'LYD',
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($landlord, 'landlord');

    $this->get(route('landlord.settings.flight-cache.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Landlord/FlightCache')
            ->where('flightCacheSettings.route_availability_enabled', true)
            ->where('flightCacheSettings.schedule_cache_enabled', false)
            ->where('flightCacheSummary.overview.learned_routes', 3)
            ->where('flightCacheSummary.overview.available_routes', 2)
            ->where('flightCacheSummary.overview.inactive_routes', 1)
            ->where('flightCacheSummary.overview.cached_fare_entries', 3)
            ->where('flightCacheSummary.overview.cached_provider_routes', 2)
            ->where('flightCacheSummary.providers.0.airline_code', 'YI')
            ->where('flightCacheSummary.providers.0.learned_route_count', 2)
            ->where('flightCacheSummary.providers.0.available_route_count', 1)
            ->where('flightCacheSummary.providers.0.cached_fare_entry_count', 2)
            ->where('flightCacheSummary.providers.0.available_routes.0.route', 'MJI-BEN')
            ->where('flightCacheSummary.providers.0.inactive_routes.0.route', 'MJI-TIP')
        );
});

test('landlord can update flight cache settings', function () {
    $landlord = LandlordUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    $this->actingAs($landlord, 'landlord');

    $this->patch(route('landlord.settings.flight-cache.update'), [
        'route_availability_enabled' => false,
        'schedule_cache_enabled' => true,
    ])->assertRedirect();

    expect(LandlordSetting::query()
        ->where('key', GlobalFlightCacheSettingsService::KEY_ROUTE_AVAILABILITY_ENABLED)
        ->first()?->value)
        ->toBe(['enabled' => false]);

    expect(LandlordSetting::query()
        ->where('key', GlobalFlightCacheSettingsService::KEY_SCHEDULE_CACHE_ENABLED)
        ->first()?->value)
        ->toBe(['enabled' => true]);
});
