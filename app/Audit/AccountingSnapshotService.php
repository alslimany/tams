<?php

namespace App\Audit;

use App\Models\Tenant\Order;
use App\Models\TenantProvider;
use App\Services\Accounting\LedgerQueryService;

class AccountingSnapshotService
{
    public function __construct(
        private readonly LedgerQueryService $ledger,
    ) {}

    public function capture(string $label): array
    {
        return [
            'label' => $label,
            'captured_at' => now()->toIso8601String(),
            'wallets' => $this->captureWallets(),
            'ledger_accounts' => $this->captureLedgerAccounts(),
            'open_orders' => $this->captureOpenOrders(),
        ];
    }

    private function captureWallets(): array
    {
        $wallets = [];

        // Agency operating wallets (the current tenant)
        try {
            $agency = tenant();
            if ($agency && method_exists($agency, 'wallets')) {
                foreach ($agency->wallets as $wallet) {
                    $key = 'agency_'.$wallet->slug;
                    $wallets[$key] = [
                        'model' => 'Agency',
                        'name' => $wallet->name,
                        'slug' => $wallet->slug,
                        'balance' => (float) $wallet->balanceFloat,
                        'currency' => $wallet->meta['currency'] ?? 'LYD',
                        'ledger' => $wallet->meta['ledger_account'] ?? null,
                    ];
                }
            }
        } catch (\Throwable) {
            // Tenant may not have wallets configured yet
        }

        // Provider wallets (TenantProvider — airline, hotel, insurance, etc.)
        try {
            foreach (TenantProvider::all() as $provider) {
                foreach ($provider->wallets as $wallet) {
                    $key = 'provider_'.$provider->id.'_'.$wallet->slug;
                    $wallets[$key] = [
                        'model' => 'TenantProvider',
                        'provider_id' => $provider->id,
                        'provider_name' => $provider->airline_name ?? $provider->account_name,
                        'provider_type' => $provider->provider_type,
                        'slug' => $wallet->slug,
                        'balance' => (float) $wallet->balanceFloat,
                        'currency' => $wallet->meta['currency'] ?? 'LYD',
                        'ledger' => $wallet->meta['ledger_account'] ?? null,
                    ];
                }
            }
        } catch (\Throwable) {
            // Provider wallets may not exist yet
        }

        return $wallets;
    }

    private function captureLedgerAccounts(): array
    {
        $trackCodes = [
            '1110', // Agency operating wallet
            '1120', // Merchant wallet
            '1210', // Airline provider wallet
            '1220', // Hotel provider wallet
            '1230', // Insurance provider wallet
            '1240', // eSIM provider wallet
            '1310', // Customer receivable
            '1320', // Merchant/network receivable
            '2200', // Network agency payable
            '2400', // VAT payable
            '4100', // Airline revenue
            '4200', // Hotel revenue
            '4300', // Insurance revenue
            '4400', // eSIM revenue
            '4600', // Network commission
            '4700', // Cancellation fee income
            '5100', // Airline cost
            '5200', // Hotel cost
            '5300', // Insurance cost
            '5400', // eSIM cost
            '5500', // Merchant wholesale cost
        ];

        $balances = [];

        foreach ($trackCodes as $code) {
            try {
                $balances[$code] = $this->ledger->accountBalance($code);
            } catch (\Throwable) {
                $balances[$code] = null;
            }
        }

        return $balances;
    }

    private function captureOpenOrders(): array
    {
        try {
            return Order::whereIn('status', ['pending', 'confirmed'])
                ->select('id', 'status', 'grand_total', 'created_at')
                ->latest()
                ->limit(10)
                ->get()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
