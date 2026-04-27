<?php

namespace App\DTOs\Finance;

use App\Models\TenantProvider;

readonly class FinancialSourceData
{
    public function __construct(
        public string $type,
        public ?TenantProvider $provider,
        public ?string $defaultAgencyTenantId = null,
        public float $masterCommissionRate = 0,
    ) {}

    public function usesOwnCredentials(): bool
    {
        return $this->type === 'own_credentials';
    }

    public function usesMasterAgencySupply(): bool
    {
        return $this->type === 'master_agency_supply';
    }
}
