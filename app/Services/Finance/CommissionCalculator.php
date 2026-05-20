<?php

namespace App\Services\Finance;

class CommissionCalculator
{
    public function __construct(private RouteInternationalService $routeInternationalService) {}

    /**
     * @param  array<string, mixed>|object  $tenantProvider
     * @return array{percent: float, amount: float, net_after_commission: float}
     */
    public function calculate(array|object $tenantProvider, string $origin, string $destination, float $netFare): array
    {
        $isInternational = $this->routeInternationalService->isInternational($origin, $destination);

        $commissionPercent = $isInternational
            ? (float) (data_get($tenantProvider, 'international_commission_rate') ?? data_get($tenantProvider, 'commission_international') ?? 0)
            : (float) (data_get($tenantProvider, 'domestic_commission_rate') ?? data_get($tenantProvider, 'commission_domestic') ?? 0);

        $commissionAmount = round($netFare * $commissionPercent / 100, 2);
        $netAfterCommission = round($netFare - $commissionAmount, 2);

        return [
            'percent' => $commissionPercent,
            'amount' => $commissionAmount,
            'net_after_commission' => $netAfterCommission,
        ];
    }
}
