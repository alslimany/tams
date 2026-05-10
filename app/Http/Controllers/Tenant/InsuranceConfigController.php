<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInsuranceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InsuranceConfigController extends Controller
{
    public function index(): Response
    {
        $configuredProviders = TenantInsuranceProvider::query()
            ->get()
            ->keyBy('provider_type');

        $providers = collect($this->supportedProviders())
            ->map(function (array $provider) use ($configuredProviders): array {
                /** @var TenantInsuranceProvider|null $configured */
                $configured = $configuredProviders->get($provider['provider_type']);

                return [
                    'name' => $provider['name'],
                    'provider_type' => $provider['provider_type'],
                    'description' => $provider['description'],
                    'supports_balance_api' => (bool) ($provider['supports_balance_api'] ?? false),
                    'is_active' => (bool) ($configured?->is_active ?? false),
                    'base_url' => (string) data_get($configured?->credentials ?? [], 'base_url', $provider['base_url']),
                    'token' => (string) data_get($configured?->credentials ?? [], 'token', ''),
                    'commission_compulsory' => (float) ($configured?->commission_compulsory ?? 0),
                    'commission_travel' => (float) ($configured?->commission_travel ?? 0),
                    'commission_orange' => (float) ($configured?->commission_orange ?? 0),
                    'currency' => 'LYD',
                    'remaining_balance' => round((float) ($configured?->getBalance('LYD') ?? 0), 2),
                    'requires_initial_balance' => $configured !== null
                        && ! ((bool) ($provider['supports_balance_api'] ?? false))
                        && (float) $configured->getBalance('LYD') <= 0,
                    'status' => $configured ? 'configured' : 'not_configured',
                ];
            })
            ->values();

        return Inertia::render('Tenant/Settings/Insurance', [
            'providers' => $providers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'provider_type' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['required', 'url', 'max:255'],
            'token' => ['required', 'string', 'max:4000'],
            'is_active' => ['required', 'boolean'],
            'commission_compulsory' => ['required', 'numeric', 'min:0', 'max:100'],
            'commission_travel' => ['required', 'numeric', 'min:0', 'max:100'],
            'commission_orange' => ['required', 'numeric', 'min:0', 'max:100'],
            'initial_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! collect($this->supportedProviders())->contains(fn (array $provider): bool => $provider['provider_type'] === $validated['provider_type'])) {
            return back()->withErrors([
                'provider_type' => 'Unsupported insurance provider type.',
            ]);
        }

        $provider = TenantInsuranceProvider::query()->updateOrCreate(
            ['provider_type' => $validated['provider_type']],
            [
                'name' => $validated['name'],
                'provider_type' => $validated['provider_type'],
                'is_active' => $validated['is_active'],
                'credentials' => [
                    'base_url' => rtrim($validated['base_url'], '/'),
                    'token' => $validated['token'],
                ],
                'commission_compulsory' => $validated['commission_compulsory'],
                'commission_travel' => $validated['commission_travel'],
                'commission_orange' => $validated['commission_orange'],
            ]
        );

        $initialBalance = (float) ($validated['initial_balance'] ?? 0);
        $wallet = $provider->getOrCreateCurrencyWallet('LYD');

        if ($initialBalance > 0 && (float) $wallet->balanceFloat <= 0) {
            $wallet->depositFloat($initialBalance, [
                'type' => 'opening_balance',
                'description' => 'Opening balance set from provider configuration.',
                'provider_type' => $provider->provider_type,
            ]);
        }

        return back()->with('success', 'Insurance provider configuration saved successfully.');
    }

    public function deposit(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'provider_type' => ['required', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $provider = TenantInsuranceProvider::query()
            ->where('provider_type', $validated['provider_type'])
            ->first();

        if (! $provider instanceof TenantInsuranceProvider) {
            return back()->withErrors([
                'provider_type' => 'Insurance provider is not configured yet.',
            ]);
        }

        $currency = strtoupper((string) ($validated['currency'] ?? 'LYD'));
        $depositAmount = round((float) $validated['amount'], 2);

        DB::transaction(function () use ($provider, $currency, $depositAmount, $validated): void {
            $wallet = $provider->getOrCreateCurrencyWallet($currency);

            $wallet->depositFloat($depositAmount, [
                'type' => 'deposit',
                'description' => (string) ($validated['description'] ?? 'Manual balance deposit from insurance settings.'),
                'provider_type' => $provider->provider_type,
            ]);
        });

        return back()->with('success', 'Insurance provider wallet balance updated.');
    }

    /**
     * @return array<int, array{provider_type:string,name:string,description:string,base_url:string}>
     */
    protected function supportedProviders(): array
    {
        return [
            [
                'provider_type' => 'albaraka',
                'name' => 'Al Baraka Insurance',
                'description' => 'Compulsory, travel, and orange insurance via Al Baraka API.',
                'base_url' => 'https://tameen.webapi.ly',
                'supports_balance_api' => false,
            ],
        ];
    }
}
