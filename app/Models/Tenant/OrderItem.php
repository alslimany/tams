<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class OrderItem extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'item_details' => 'array',
            'product_details' => 'array',
            'net_fare' => 'decimal:2',
            'price' => 'decimal:2',
            'taxes' => 'array',
            'total_tax' => 'decimal:2',
            'total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'net_commission' => 'decimal:2',
            'agent_commission' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'net_after_commission' => 'decimal:2',
            'paid' => 'decimal:2',
            'remaining' => 'decimal:2',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('passports')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);

        $this->addMediaCollection('visas')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(200)
            ->height(200)
            ->performOnCollections('passports', 'visas')
            ->nonQueued();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refundParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refund_parent_id');
    }
}
