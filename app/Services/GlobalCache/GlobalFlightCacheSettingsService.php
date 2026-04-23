<?php

namespace App\Services\GlobalCache;

use App\Models\LandlordSetting;

class GlobalFlightCacheSettingsService
{
    public const KEY_ROUTE_AVAILABILITY_ENABLED = 'flight_cache.route_availability_enabled';

    public const KEY_SCHEDULE_CACHE_ENABLED = 'flight_cache.schedule_cache_enabled';

    public function isRouteAvailabilityEnabled(): bool
    {
        return $this->getBoolean(self::KEY_ROUTE_AVAILABILITY_ENABLED, true);
    }

    public function isScheduleCacheEnabled(): bool
    {
        return $this->getBoolean(self::KEY_SCHEDULE_CACHE_ENABLED, true);
    }

    public function setRouteAvailabilityEnabled(bool $enabled): void
    {
        $this->setBoolean(self::KEY_ROUTE_AVAILABILITY_ENABLED, $enabled);
    }

    public function setScheduleCacheEnabled(bool $enabled): void
    {
        $this->setBoolean(self::KEY_SCHEDULE_CACHE_ENABLED, $enabled);
    }

    protected function getBoolean(string $key, bool $default): bool
    {
        $setting = LandlordSetting::query()->where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        $value = data_get($setting->value, 'enabled');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    protected function setBoolean(string $key, bool $enabled): void
    {
        LandlordSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['enabled' => $enabled]],
        );
    }
}
