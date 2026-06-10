<?php

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\Tenant;
use App\Services\Hotels\Providers\ThreeTProvider;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sync reference data (cities, hotels, board types) from a hotel provider API
 * into the central provider_locations and provider_reference_items tables.
 *
 * Usage:
 *   php artisan providers:sync-locations --tenant=my-tenant           # full 3T sync
 *   php artisan providers:sync-locations --tenant=my-tenant --country=TN
 *   php artisan providers:sync-locations --tenant=my-tenant --type=boards
 */
class SyncProviderLocationsCommand extends Command
{
    protected $signature = 'providers:sync-locations
                            {provider=3t : Provider key (only "3t" supported for now)}
                            {--tenant= : Tenant ID whose 3T credentials to use for the API calls}
                            {--country= : Sync only this ISO alpha2 country code (e.g. TN, LY)}
                            {--type=all : What to sync: all | cities | hotels | boards}';

    protected $description = 'Sync cities, hotels, and board types from a provider content API into the central database';

    /**
     * Pause between country/city API calls to avoid hammering the provider (microseconds).
     */
    private const RATE_LIMIT_US = 300_000; // 300 ms

    /** @var array<string, int> ISO alpha2 (uppercase) → countries.id */
    private array $countryIdMap = [];

    public function handle(): int
    {
        $provider = (string) $this->argument('provider');

        if ($provider !== '3t') {
            $this->error("Provider [{$provider}] is not supported yet. Only '3t' is available.");

            return self::FAILURE;
        }

        $api = $this->resolveApi();
        if ($api === null) {
            return self::FAILURE;
        }

        $this->info("Starting provider location sync for [{$provider}]...");

        // Build country ID map from the central countries table
        $this->countryIdMap = Country::query()
            ->pluck('id', 'alpha2')
            ->mapWithKeys(fn ($id, $alpha2): array => [strtoupper((string) $alpha2) => (int) $id])
            ->all();

        $type = strtolower((string) ($this->option('type') ?? 'all'));
        $onlyCountry = strtoupper((string) ($this->option('country') ?? ''));

        $syncCities = in_array($type, ['all', 'cities', 'hotels'], true);
        $syncHotels = in_array($type, ['all', 'hotels'], true);
        $syncBoards = in_array($type, ['all', 'boards'], true);

        if ($syncBoards) {
            $this->syncBoards($api, $provider);
        }

        if ($syncCities || $syncHotels) {
            $this->syncLocations($api, $provider, $onlyCountry, $syncHotels);
        }

        $this->newLine();
        $this->info('Sync complete.');

        return self::SUCCESS;
    }

    /**
     * Resolve a ThreeTProvider instance, initializing tenancy when --tenant is given.
     */
    private function resolveApi(): ?ThreeTProvider
    {
        $tenantId = $this->option('tenant');

        if (is_string($tenantId) && trim($tenantId) !== '') {
            $tenant = Tenant::query()->find($tenantId);

            if (! $tenant) {
                $this->error("Tenant [{$tenantId}] was not found.");

                return null;
            }

            tenancy()->initialize($tenant);
        } elseif (! tenancy()->initialized) {
            $this->error('No tenant context. Provide --tenant=<id> so credentials can be resolved.');

            return null;
        }

        return new ThreeTProvider;
    }

    /**
     * Return a DB connection that always points to the central (landlord) database.
     */
    private function centralDb(): Connection
    {
        return DB::connection(
            config('tenancy.database.central_connection', config('database.default', 'mysql'))
        );
    }

    private function syncBoards(ThreeTProvider $api, string $provider): void
    {
        $this->info('Syncing board types...');

        try {
            $items = $api->boards();
        } catch (\Throwable $exception) {
            $this->error('Failed to fetch board list: '.$exception->getMessage());

            return;
        }

        if (empty($items)) {
            $this->warn('Board list returned no items — skipping.');

            return;
        }

        $now = now();
        $rows = collect($items)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item) use ($now): array {
                $code = (string) ($item['code'] ?? $item['boardCode'] ?? $item['id'] ?? '');
                $name = (string) ($item['description'] ?? $item['boardName'] ?? $item['name'] ?? $code);

                return [
                    'provider_type' => '3t',
                    'item_type' => 'board_type',
                    'code' => $code,
                    'name_en' => $name,
                    'name_ar' => $name,   // placeholder — translate manually if needed
                    'name_fr' => $name,
                    'metadata' => null,
                    'sort_order' => 0,
                    'last_synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->filter(fn (array $row): bool => $row['code'] !== '')
            ->values();

        $this->centralDb()->table('provider_reference_items')->upsert(
            $rows->all(),
            ['provider_type', 'item_type', 'code'],
            ['name_en', 'name_ar', 'name_fr', 'last_synced_at', 'updated_at'],
        );

        $this->line("  <fg=green>✓</> Upserted {$rows->count()} board type(s).");
    }

    private function syncLocations(
        ThreeTProvider $api,
        string $provider,
        string $onlyCountry,
        bool $syncHotels,
    ): void {
        $this->info('Fetching country list from provider...');

        try {
            $countries = collect($api->countries())
                ->filter(fn (mixed $c): bool => is_array($c));
        } catch (\Throwable $exception) {
            $this->error('Failed to fetch country list: '.$exception->getMessage());

            return;
        }

        if ($onlyCountry !== '') {
            $countries = $countries->filter(
                fn (array $c): bool => strtoupper((string) ($c['countryId'] ?? $c['id'] ?? '')) === $onlyCountry,
            );
        }

        $this->line("  Processing {$countries->count()} country(ies)...");

        $bar = $this->output->createProgressBar($countries->count());
        $bar->start();

        foreach ($countries as $countryData) {
            $countryCode = strtoupper((string) ($countryData['countryId'] ?? $countryData['id'] ?? ''));

            if ($countryCode === '') {
                $bar->advance();

                continue;
            }

            usleep(self::RATE_LIMIT_US);

            try {
                $cities = collect($api->cities($countryCode))
                    ->filter(fn (mixed $c): bool => is_array($c));
            } catch (\Throwable $exception) {
                $this->newLine();
                $this->warn("  Could not fetch cities for [{$countryCode}]: ".$exception->getMessage());
                $bar->advance();

                continue;
            }

            $this->upsertCities($cities, $countryCode);

            if ($syncHotels) {
                $this->syncHotelsForCities($api, $cities, $countryCode);
            }

            $bar->advance();
        }

        $bar->finish();
    }

    /** @param Collection<int, array<string, mixed>> $cities */
    private function upsertCities(Collection $cities, string $countryCode): void
    {
        $now = now();
        $countryId = $this->countryIdMap[$countryCode] ?? null;

        $rows = $cities->map(function (array $city) use ($countryCode, $countryId, $now): array {
            $code = (string) ($city['cityId'] ?? $city['id'] ?? '');
            $name = (string) ($city['cityName'] ?? $city['name'] ?? $code);

            return [
                'provider_type' => '3t',
                'location_type' => 'city',
                'code' => $code,
                'parent_code' => null,
                'name_en' => $name,
                'name_ar' => $name,   // placeholder
                'name_fr' => $name,
                'country_code' => $countryCode,
                'country_id' => $countryId,
                'metadata' => null,
                'is_active' => true,
                'last_synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->filter(fn (array $row): bool => $row['code'] !== '')->values();

        if ($rows->isEmpty()) {
            return;
        }

        $this->centralDb()->table('provider_locations')->upsert(
            $rows->all(),
            ['provider_type', 'location_type', 'code'],
            ['name_en', 'country_code', 'country_id', 'is_active', 'last_synced_at', 'updated_at'],
        );
    }

    /** @param Collection<int, array<string, mixed>> $cities */
    private function syncHotelsForCities(
        ThreeTProvider $api,
        Collection $cities,
        string $countryCode,
    ): void {
        $countryId = $this->countryIdMap[$countryCode] ?? null;
        $now = now();

        foreach ($cities as $city) {
            $cityCode = (string) ($city['cityId'] ?? $city['id'] ?? '');

            if ($cityCode === '') {
                continue;
            }

            usleep(self::RATE_LIMIT_US);

            try {
                $hotels = collect($api->hotels($cityCode))
                    ->filter(fn (mixed $h): bool => is_array($h));
            } catch (\Throwable $exception) {
                $this->newLine();
                $this->warn("  Could not fetch hotels for city [{$cityCode}]: ".$exception->getMessage());

                continue;
            }

            if ($hotels->isEmpty()) {
                continue;
            }

            $rows = $hotels->map(function (array $hotel) use ($cityCode, $countryCode, $countryId, $now): array {
                $code = (string) ($hotel['hotelCode'] ?? $hotel['code'] ?? $hotel['id'] ?? '');
                $name = (string) ($hotel['hotelName'] ?? $hotel['name'] ?? $code);
                $stars = (string) ($hotel['stars'] ?? $hotel['category'] ?? '');
                $address = (string) ($hotel['address'] ?? '');

                $meta = array_filter([
                    'stars' => $stars !== '' ? $stars : null,
                    'address' => $address !== '' ? $address : null,
                ]);

                return [
                    'provider_type' => '3t',
                    'location_type' => 'hotel',
                    'code' => $code,
                    'parent_code' => $cityCode,
                    'name_en' => $name,
                    'name_ar' => $name,   // placeholder
                    'name_fr' => $name,
                    'country_code' => $countryCode,
                    'country_id' => $countryId,
                    'metadata' => $meta !== [] ? json_encode($meta) : null,
                    'is_active' => true,
                    'last_synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->filter(fn (array $row): bool => $row['code'] !== '')->values();

            if ($rows->isEmpty()) {
                continue;
            }

            $this->centralDb()->table('provider_locations')->upsert(
                $rows->all(),
                ['provider_type', 'location_type', 'code'],
                ['name_en', 'parent_code', 'country_code', 'country_id', 'metadata', 'is_active', 'last_synced_at', 'updated_at'],
            );
        }
    }
}
