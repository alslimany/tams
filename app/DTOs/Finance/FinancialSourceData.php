<?php

namespace App\DTOs\Finance;

use App\Models\TenantProvider;

readonly class FinancialSourceData
{
    public function __construct(
        public string $type,
        public ?TenantProvider $provider,
    ) {}

    public function usesOwnCredentials(): bool
    {
        return $this->type === 'own_credentials';
    }
}
