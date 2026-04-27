<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class AgencyWalletTransaction extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id',
        'default_agency_tenant_id',
        'type',
        'currency',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'admin_id',
        'settlement_id',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function defaultAgency(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'default_agency_tenant_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(LandlordUser::class, 'admin_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(AgencySettlement::class, 'settlement_id');
    }

    /**
     * Record a top-up from the central admin to an agency wallet.
     */
    public static function recordTopUp(
        string $tenantId,
        string $currency,
        float $amount,
        float $balanceAfter,
        ?string $description = null,
        ?int $adminId = null,
    ): self {
        return self::create([
            'tenant_id' => $tenantId,
            'type' => 'topup_from_admin',
            'currency' => $currency,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_type' => 'manual_topup',
            'description' => $description ?? 'Wallet top-up from central admin',
            'admin_id' => $adminId,
        ]);
    }

    /**
     * Record a ticket cost deduction from an agency wallet.
     */
    public static function recordTicketDeduction(
        string $tenantId,
        string $defaultAgencyTenantId,
        string $currency,
        float $amount,
        float $balanceAfter,
        string $orderId,
    ): self {
        return self::create([
            'tenant_id' => $tenantId,
            'default_agency_tenant_id' => $defaultAgencyTenantId,
            'type' => 'ticket_cost_deduction',
            'currency' => $currency,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_type' => 'order_id',
            'reference_id' => $orderId,
            'description' => "Ticket cost deduction for order {$orderId}",
        ]);
    }

    /**
     * Record a master commission payable from buyer agency to default agency.
     */
    public static function recordCommissionPayable(
        string $tenantId,
        string $defaultAgencyTenantId,
        string $currency,
        float $amount,
        float $balanceAfter,
        string $orderId,
        ?string $orderItemId = null,
    ): self {
        return self::create([
            'tenant_id' => $tenantId,
            'default_agency_tenant_id' => $defaultAgencyTenantId,
            'type' => 'commission_payable',
            'currency' => $currency,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_type' => $orderItemId ? 'order_item_id' : 'order_id',
            'reference_id' => $orderItemId ?? $orderId,
            'description' => "Master commission payable for order {$orderId}",
        ]);
    }
}
