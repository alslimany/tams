<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class NetworkMembership extends Model
{
    use CentralConnection;

    public const StatusPending = 'pending';

    public const StatusActive = 'active';

    public const StatusSuspended = 'suspended';

    public const StatusRemovalRequested = 'removal_requested';

    public const StatusRemovalApproved = 'removal_approved';

    public const StatusRevoked = 'revoked';

    protected $fillable = [
        'agency_tenant_id',
        'merchant_tenant_id',
        'invitation_token',
        'invitation_code',
        'status',
        'expires_at',
        'accepted_at',
        'removal_requested_at',
        'removal_approved_at',
        'revoked_at',
        'created_by',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (NetworkMembership $membership): void {
            $membership->invitation_token ??= (string) Str::uuid();
            $membership->invitation_code ??= Str::upper(Str::random(10));
        });
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'removal_requested_at' => 'datetime',
            'removal_approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'agency_tenant_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'merchant_tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(LandlordUser::class, 'created_by');
    }

    public function providerAllocations(): HasMany
    {
        return $this->hasMany(ProviderAllocation::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::StatusActive);
    }

    public function activate(): bool
    {
        return $this->forceFill([
            'status' => self::StatusActive,
            'accepted_at' => now(),
        ])->save();
    }

    public function requestRemoval(): bool
    {
        return $this->forceFill([
            'status' => self::StatusRemovalRequested,
            'removal_requested_at' => now(),
        ])->save();
    }
}
