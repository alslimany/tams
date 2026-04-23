<?php

namespace App\Jobs;

use App\Models\Tenant\AirlineAccount;
use App\Models\TenantProvider;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\Videcom\BaseVidecomAirline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateAirlineBalanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $tenantProviderId) {}

    public function handle(): void
    {
        $providerConfig = TenantProvider::query()->find($this->tenantProviderId);

        if (! $providerConfig instanceof TenantProvider) {
            return;
        }

        try {
            $provider = ProviderFactory::make($providerConfig);

            if (! $provider instanceof BaseVidecomAirline) {
                return;
            }

            $currency = strtoupper((string) data_get($providerConfig->credentials, 'currency', ''));
            $balanceResult = $provider->fetchWalletBalance($currency);

            $balanceCurrency = strtoupper((string) ($balanceResult['currency'] ?? $currency));
            $balanceAmount = (float) ($balanceResult['balance'] ?? 0);

            AirlineAccount::query()->updateOrCreate(
                [
                    'tenant_provider_id' => $providerConfig->id,
                    'currency' => $balanceCurrency,
                ],
                [
                    'balance' => $balanceAmount,
                ]
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
