<?php

namespace App\Services\Insurance;

use App\Contracts\Insurance\InsuranceProviderInterface;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Services\Insurance\Providers\AlBarakaProvider;
use RuntimeException;

class InsuranceProviderManager
{
    public function __construct(
        protected AlBarakaProvider $alBarakaProvider,
    ) {}

    public function provider(): InsuranceProviderInterface
    {
        $active = $this->activeProvider();

        return match ($active?->provider_type ?? 'albaraka') {
            'albaraka' => $this->alBarakaProvider,
            default => throw new RuntimeException('Unsupported insurance provider type.'),
        };
    }

    public function activeProvider(): ?TenantInsuranceProvider
    {
        return TenantInsuranceProvider::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }
}
