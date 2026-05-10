<?php

namespace App\Actions\Finance;

use App\Models\Tenant\Order;
use App\Services\Airline\AgencyProviderResolver;
use App\Services\Finance\CommissionCalculator;

class ApplyFinancialSourceAndCommission
{
    public function __construct(
        protected DetermineFinancialSource $financialSourceAction,
        protected CommissionCalculator $commissionCalculator,
        protected AgencyProviderResolver $providerResolver,
    ) {}

    public function execute(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $segment = (array) data_get($item->item_details, 'segments.0', data_get($item->item_details, 'segment', data_get($item->product_details, 'segment', [])));
            $origin = (string) ($segment['departure_airport'] ?? $segment['origin'] ?? '');
            $destination = (string) ($segment['arrival_airport'] ?? $segment['destination'] ?? '');
            $airlineCode = (string) data_get($item->item_details, 'airline_code', data_get($item->product_details, 'airline_code', ''));

            $resolvedProvider = $airlineCode !== ''
                ? $this->providerResolver->resolve($airlineCode)
                : ['is_using_master_agency' => false];

            $source = $this->financialSourceAction->execute($airlineCode, (string) $item->currency);
            $commission = $this->commissionCalculator->calculate(
                $source->provider ?? [],
                $origin,
                $destination,
                (float) ($item->net_fare ?? 0),
            );

            $details = (array) $item->item_details;
            $details['financial_source'] = $source->type;
            $details['financial_provider_id'] = $source->provider?->id;
            $details['financial_source_tenant_id'] = data_get($resolvedProvider, 'resolved_tenant_id');
            $details['provider_source_type'] = data_get($resolvedProvider, 'source_type');
            $details['is_default_agency_deprecated'] = (bool) data_get($resolvedProvider, 'is_default_agency_deprecated', false);

            foreach (['provider_selector', 'source_agency_tenant_id', 'merchant_tenant_id', 'network_membership_id', 'provider_allocation_id', 'source_provider_model', 'source_provider_id'] as $metadataKey) {
                $metadataValue = data_get($resolvedProvider, $metadataKey);
                if ($metadataValue !== null) {
                    $details[$metadataKey] = $metadataValue;
                }
            }

            $commissionPercent = (float) ($commission['percent'] ?? 0);
            $commissionAmount = (float) ($commission['amount'] ?? 0);
            $netAfterCommission = (float) ($commission['net_after_commission'] ?? 0);
            $agentCommission = (float) ($commission['amount'] ?? 0);

            if ($source->usesMasterAgencySupply()) {
                $masterCommissionAmount = round((float) ($item->net_fare ?? 0) * ($source->masterCommissionRate / 100), 2);

                $details['default_agency_tenant_id'] = $source->defaultAgencyTenantId;
                $details['master_commission_rate'] = $source->masterCommissionRate;
                $details['settlement_source'] = 'default_agency_supply';

                // Buyer agency commission is not recognized in master-supply mode.
                $commissionPercent = 0;
                $commissionAmount = 0;
                $netAfterCommission = (float) ($item->net_fare ?? 0);
                $agentCommission = $masterCommissionAmount;
            } else {
                $details['settlement_source'] = 'own_credentials';
            }

            if ($source->usesOwnCredentials() && ($source->provider?->provider_type ?? null) === 'videcom') {
                $details['provider_financial_mode'] = 'discount';
                $details['provider_discount_amount'] = $commissionAmount;
                $details['provider_net_fare'] = $netAfterCommission;
                $details['provider_payable_amount'] = round($netAfterCommission + (float) ($item->total_tax ?? 0), 2);
            }

            $item->fill([
                'commission_percent' => $commissionPercent,
                'commission_amount' => $commissionAmount,
                'net_after_commission' => $netAfterCommission,
                'agent_commission' => $agentCommission,
                'net_commission' => $agentCommission,
                'used_master_agency_provider' => (bool) ($resolvedProvider['is_using_master_agency'] ?? false),
                'master_commission_percent' => $source->usesMasterAgencySupply() ? $source->masterCommissionRate : null,
                'item_details' => $details,
            ])->save();
        }
    }
}
