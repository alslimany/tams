<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Central (landlord) model — stores reference items (board types, room types, etc.)
 * from any provider API.
 *
 * @property int $id
 * @property string $provider_type e.g. '3t', 'amadeus'
 * @property string $item_type e.g. 'board_type', 'room_type', 'facility'
 * @property string $code Provider's own code (e.g. 'BB', 'HB', 'AI')
 * @property string $name_en
 * @property string|null $name_ar
 * @property string|null $name_fr
 * @property array<string, mixed>|null $metadata
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ProviderReferenceItem extends Model
{
    use CentralConnection;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'sort_order' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Return the translated label for the given locale, falling back to English.
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

    /** @param Builder<ProviderReferenceItem> $query */
    public function scopeForProvider(Builder $query, string $providerType): void
    {
        $query->where('provider_type', $providerType);
    }

    /** @param Builder<ProviderReferenceItem> $query */
    public function scopeOfType(Builder $query, string $itemType): void
    {
        $query->where('item_type', $itemType);
    }
}
