<?php

use App\Models\AgencySettlement;
use App\Models\AgencyWalletTransaction;
use App\Models\Tenant;
use Illuminate\Support\Str;

function createSettlementParties(): array
{
    $buyerTenant = Tenant::create([
        'id' => 'buyer-'.Str::random(4),
        'company_name' => 'Buyer Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $buyerTenant->domains()->create(['domain' => $buyerTenant->id.'.localhost']);

    $defaultAgencyTenant = Tenant::create([
        'id' => 'default-'.Str::random(4),
        'company_name' => 'Default Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
        'is_default_agency' => true,
    ]);

    $defaultAgencyTenant->domains()->create(['domain' => $defaultAgencyTenant->id.'.localhost']);

    return [$buyerTenant, $defaultAgencyTenant];
}

test('finance:settle-master-commissions creates settlement rows and marks payables as settled', function () {
    [$buyerTenant, $defaultAgencyTenant] = createSettlementParties();

    AgencyWalletTransaction::query()->create([
        'tenant_id' => $buyerTenant->id,
        'default_agency_tenant_id' => $defaultAgencyTenant->id,
        'type' => 'commission_payable',
        'currency' => 'LYD',
        'amount' => 10,
        'balance_after' => 0,
        'reference_type' => 'order_item_id',
        'reference_id' => 'item-1',
        'description' => 'Commission payable 1',
    ]);

    AgencyWalletTransaction::query()->create([
        'tenant_id' => $buyerTenant->id,
        'default_agency_tenant_id' => $defaultAgencyTenant->id,
        'type' => 'commission_payable',
        'currency' => 'LYD',
        'amount' => 15,
        'balance_after' => 0,
        'reference_type' => 'order_item_id',
        'reference_id' => 'item-2',
        'description' => 'Commission payable 2',
    ]);

    AgencyWalletTransaction::query()->create([
        'tenant_id' => $buyerTenant->id,
        'default_agency_tenant_id' => $defaultAgencyTenant->id,
        'type' => 'ticket_cost_deduction',
        'currency' => 'LYD',
        'amount' => 100,
        'balance_after' => 0,
        'reference_type' => 'order_id',
        'reference_id' => 'order-1',
        'description' => 'Ignored non-payable transaction',
    ]);

    $this->artisan('finance:settle-master-commissions', ['tenantId' => $buyerTenant->id])
        ->assertSuccessful();

    expect(AgencySettlement::query()->count())->toBe(1);

    $settlement = AgencySettlement::query()->first();

    expect((string) $settlement->buyer_tenant_id)->toBe($buyerTenant->id)
        ->and((string) $settlement->default_agency_tenant_id)->toBe($defaultAgencyTenant->id)
        ->and((string) $settlement->currency)->toBe('LYD')
        ->and((float) $settlement->total_commission)->toBe(25.0)
        ->and((int) $settlement->transaction_count)->toBe(2);

    expect(
        AgencyWalletTransaction::query()
            ->where('type', 'commission_payable')
            ->whereNull('settlement_id')
            ->count()
    )->toBe(0);
});

test('finance:settle-master-commissions dry-run reports totals without persisting settlement records', function () {
    [$buyerTenant, $defaultAgencyTenant] = createSettlementParties();

    AgencyWalletTransaction::query()->create([
        'tenant_id' => $buyerTenant->id,
        'default_agency_tenant_id' => $defaultAgencyTenant->id,
        'type' => 'commission_payable',
        'currency' => 'USD',
        'amount' => 12.5,
        'balance_after' => 0,
        'reference_type' => 'order_item_id',
        'reference_id' => 'item-3',
        'description' => 'Commission payable dry-run',
    ]);

    $this->artisan('finance:settle-master-commissions', ['tenantId' => $buyerTenant->id, '--dry-run' => true])
        ->expectsOutputToContain('Dry run completed. No settlement records were written.')
        ->assertSuccessful();

    expect(AgencySettlement::query()->count())->toBe(0)
        ->and(
            AgencyWalletTransaction::query()
                ->where('type', 'commission_payable')
                ->whereNull('settlement_id')
                ->count()
        )->toBe(1);
});
