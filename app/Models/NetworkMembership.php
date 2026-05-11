<?php

namespace App\Models;

use Bavix\Wallet\Interfaces\Wallet as WalletInterface;
use Bavix\Wallet\Models\Wallet;
use Bavix\Wallet\Traits\HasWallet;
use Bavix\Wallet\Traits\HasWallets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class NetworkMembership extends Model implements WalletInterface
{
    use CentralConnection;
    use HasWallet;
    use HasWallets;

    public const StatusPending = 'pending';

    public const StatusActive = 'active';

    public const StatusSuspended = 'suspended';

    public const StatusRemovalRequested = 'removal_requested';

    public const StatusRemovalApproved = 'removal_approved';

    public const StatusRevoked = 'revoked';

    protected $fillable = [
        'agency_tenant_id',
        'merchant_tenant_id',
        'merchant_email',
        'merchant_contact_name',
        'invitation_token',
        'invitation_code',
        'status',
        'expires_at',
        'invited_at',
        'accepted_at',
        'suspended_at',
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
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'suspended_at' => 'datetime',
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

    public function getOrCreateCurrencyWallet(string $currency = 'LYD'): Wallet
    {
        $normalizedCurrency = strtoupper($currency);
        $slug = 'NETWORK_'.$this->id.'_'.$normalizedCurrency;

        return $this->getWallet($slug) ?? $this->createWallet([
            'name' => 'Network '.$this->id.' '.$normalizedCurrency.' Wallet',
            'slug' => $slug,
            'meta' => [
                'type' => 'merchant_agency_network_wallet',
                'currency' => $normalizedCurrency,
                'agency_tenant_id' => $this->agency_tenant_id,
                'source_agency_tenant_id' => $this->agency_tenant_id,
                'merchant_tenant_id' => $this->merchant_tenant_id,
                'network_membership_id' => $this->id,
            ],
        ]);
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

    public function suspend(): bool
    {
        return $this->forceFill([
            'status' => self::StatusSuspended,
            'suspended_at' => now(),
        ])->save();
    }

    public function revoke(): bool
    {
        return $this->forceFill([
            'status' => self::StatusRevoked,
            'revoked_at' => now(),
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
