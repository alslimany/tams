<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Central (landlord) model — stores cities, hotels, regions from any provider API.
 *
 * @property int $id
 * @property string $provider_type e.g. '3t', 'amadeus'
 * @property string $location_type e.g. 'city', 'hotel', 'region'
 * @property string $code Provider's own code for this record
 * @property string|null $parent_code For hotels: the parent city code
 * @property string $name_en
 * @property string|null $name_ar
 * @property string|null $name_fr
 * @property string|null $country_code ISO alpha2
 * @property int|null $country_id
 * @property array<string, mixed>|null $metadata
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ProviderLocation extends Model
{
    use CentralConnection;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Return the translated name for the given locale, falling back to English.
     */
    public function translatedName(string $locale = 'en'): string
    {
        $col = match ($locale) {
            'ar' => 'name_ar',
            'fr' => 'name_fr',
            default => 'name_en',
        };

        return (string) ($this->{$col} ?: $this->name_en);
    }

    /** @param Builder<ProviderLocation> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<ProviderLocation> $query */
    public function scopeForProvider(Builder $query, string $providerType): void
    {
        $query->where('provider_type', $providerType);
    }

    /** @param Builder<ProviderLocation> $query */
    public function scopeOfType(Builder $query, string $locationType): void
    {
        $query->where('location_type', $locationType);
    }

    /**
     * Search across all three language columns.
     *
     * @param  Builder<ProviderLocation>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = '%'.trim($term).'%';

        $query->where(function (Builder $q) use ($like): void {
            $q->where('name_en', 'like', $like)
                ->orWhere('name_ar', 'like', $like)
                ->orWhere('name_fr', 'like', $like);
        });
    }
}
