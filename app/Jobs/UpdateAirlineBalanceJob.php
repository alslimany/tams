<?php

namespace App\Jobs;

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

            $wallet = $providerConfig->getOrCreateCurrencyWallet($balanceCurrency);
            $currentBalance = (float) $wallet->balanceFloat;
            $delta = round($balanceAmount - $currentBalance, 2);

            if ($delta > 0) {
                $wallet->depositFloat($delta, [
                    'type' => 'provider_sync_adjustment',
                    'airline_code' => $providerConfig->airline_code,
                    'description' => 'Synced provider balance from API.',
                ]);
            }

            if ($delta < 0) {
                $wallet->forceWithdrawFloat(abs($delta), [
                    'type' => 'provider_sync_adjustment',
                    'airline_code' => $providerConfig->airline_code,
                    'description' => 'Synced provider balance from API.',
                ]);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
