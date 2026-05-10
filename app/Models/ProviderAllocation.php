<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class ProviderAllocation extends Model
{
    use CentralConnection;

    public const StatusActive = 'active';

    public const StatusSuspended = 'suspended';

    public const StatusRemovalRequested = 'removal_requested';

    public const StatusRemovalApproved = 'removal_approved';

    public const StatusRevoked = 'revoked';

    protected $fillable = [
        'network_membership_id',
        'agency_tenant_id',
        'merchant_tenant_id',
        'provider_type',
        'provider_driver',
        'provider_identity',
        'source_provider_model',
        'source_provider_id',
        'status',
        'commission_rate',
        'markup_rate',
        'limits',
        'metadata',
        'approved_at',
        'suspended_at',
        'removal_requested_at',
        'removal_approved_at',
        'revoked_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProviderAllocation $allocation): void {
            $allocation->normalizeIdentityFields();

            if ($allocation->status === self::StatusActive && $allocation->hasActiveLogicalDuplicate()) {
                throw new InvalidArgumentException('Merchant already has an active allocation for this provider identity.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'source_provider_id' => 'integer',
            'commission_rate' => 'decimal:2',
            'markup_rate' => 'decimal:2',
            'limits' => 'array',
            'metadata' => 'array',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'removal_requested_at' => 'datetime',
            'removal_approved_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function networkMembership(): BelongsTo
    {
        return $this->belongsTo(NetworkMembership::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'agency_tenant_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'merchant_tenant_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::StatusActive);
    }

    public function requestRemoval(): bool
    {
        return $this->forceFill([
            'status' => self::StatusRemovalRequested,
            'removal_requested_at' => now(),
        ])->save();
    }

    public function hasActiveLogicalDuplicate(): bool
    {
        return self::query()
            ->whereKeyNot($this->getKey())
            ->where('merchant_tenant_id', $this->merchant_tenant_id)
            ->where('provider_type', $this->provider_type)
            ->where('provider_driver', $this->provider_driver)
            ->where('provider_identity', $this->provider_identity)
            ->where('status', self::StatusActive)
            ->exists();
    }

    protected function normalizeIdentityFields(): void
    {
        $this->provider_type = strtolower((string) $this->provider_type);
        $this->provider_driver = strtolower((string) $this->provider_driver);
        $this->provider_identity = strtoupper((string) $this->provider_identity);
    }
}
