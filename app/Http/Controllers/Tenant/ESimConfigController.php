<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantEsimProvider;
use App\Services\ESim\ESimProviderFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ESimConfigController extends Controller
{
    public function index(): Response
    {
        $configuredProviders = TenantEsimProvider::query()
            ->get()
            ->keyBy('provider_type');

        $providers = collect($this->supportedProviders())
            ->map(function (array $provider) use ($configuredProviders): array {
                /** @var TenantEsimProvider|null $configured */
                $configured = $configuredProviders->get($provider['provider_type']);
                $currency = strtoupper((string) ($configured?->currency ?? $provider['default_currency']));

                $providerOrg = null;

                if ($configured instanceof TenantEsimProvider) {
                    try {
                        $providerOrg = ESimProviderFactory::make($configured)->organization();
                    } catch (Throwable) {
                        // Silently ignore — settings page must not break if API is unreachable
                    }
                }

                return [
                    'name' => $provider['name'],
                    'provider_type' => $provider['provider_type'],
                    'description' => $provider['description'],
                    'default_currency' => $provider['default_currency'],
                    'is_active' => (bool) ($configured?->is_active ?? false),
                    'base_url' => (string) data_get($configured?->credentials ?? [], 'base_url', $provider['base_url']),
                    'api_key' => (string) data_get($configured?->credentials ?? [], 'api_key', ''),
                    'client_secret' => (string) data_get($configured?->credentials ?? [], 'client_secret', ''),
                    'commission_esim' => (float) ($configured?->commission_esim ?? 0),
                    'currency' => $currency,
                    'remaining_balance' => round((float) ($configured?->getBalance($currency) ?? 0), 2),
                    'requires_initial_balance' => $configured !== null && (float) $configured->getBalance($currency) <= 0,
                    'status' => $configured ? 'configured' : 'not_configured',
                    'provider_org' => $providerOrg,
                ];
            })
            ->values();

        return Inertia::render('Tenant/Settings/ESim', [
            'providers' => $providers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'provider_type' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['required', 'url', 'max:255'],
            'api_key' => ['required', 'string', 'max:4000'],
            'client_secret' => ['required', 'string', 'max:4000'],
            'is_active' => ['required', 'boolean'],
            'commission_esim' => ['required', 'numeric', 'min:0', 'max:100'],
            'initial_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! collect($this->supportedProviders())->contains(fn (array $provider): bool => $provider['provider_type'] === $validated['provider_type'])) {
            return back()->withErrors([
                'provider_type' => 'Unsupported eSIM provider type.',
            ]);
        }

        $currency = TenantEsimProvider::DEFAULT_CURRENCY;

        $provider = TenantEsimProvider::query()->updateOrCreate(
            ['provider_type' => $validated['provider_type']],
            [
                'name' => $validated['name'],
                'provider_type' => $validated['provider_type'],
                'is_active' => $validated['is_active'],
                'currency' => $currency,
                'credentials' => [
                    'base_url' => rtrim($validated['base_url'], '/'),
                    'api_key' => $validated['api_key'],
                    'client_secret' => $validated['client_secret'],
                ],
                'commission_esim' => $validated['commission_esim'],
            ]
        );

        $initialBalance = (float) ($validated['initial_balance'] ?? 0);
        $wallet = $provider->getOrCreateCurrencyWallet($currency);

        if ($initialBalance > 0 && (float) $wallet->balanceFloat <= 0) {
            $wallet->depositFloat($initialBalance, [
                'type' => 'opening_balance',
                'description' => 'Opening balance set from eSIM provider configuration.',
                'provider_type' => $provider->provider_type,
                'product_type' => 'esim',
            ]);
        }

        return back()->with('success', 'eSIM provider configuration saved successfully.');
    }

    public function deposit(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'provider_type' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $provider = TenantEsimProvider::query()
            ->where('provider_type', $validated['provider_type'])
            ->first();

        if (! $provider instanceof TenantEsimProvider) {
            return back()->withErrors([
                'provider_type' => 'eSIM provider is not configured yet.',
            ]);
        }

        $currency = $provider->providerCurrency();
        $depositAmount = round((float) $validated['amount'], 2);

        DB::transaction(function () use ($provider, $currency, $depositAmount, $validated): void {
            $wallet = $provider->getOrCreateCurrencyWallet($currency);

            $wallet->depositFloat($depositAmount, [
                'type' => 'deposit',
                'description' => (string) ($validated['description'] ?? 'Manual balance deposit from eSIM settings.'),
                'provider_type' => $provider->provider_type,
                'product_type' => 'esim',
            ]);
        });

        return back()->with('success', 'eSIM provider wallet balance updated.');
    }

    /**
     * @return array<int, array{provider_type:string,name:string,description:string,base_url:string,default_currency:string}>
     */
    protected function supportedProviders(): array
    {
        return [
            [
                'provider_type' => 'l2',
                'name' => 'L2 Travel eSIM',
                'description' => 'eSIM package search and issuance via L2 Travel API.',
                'base_url' => 'https://l2travelesim.com/api/v2',
                'default_currency' => TenantEsimProvider::DEFAULT_CURRENCY,
            ],
        ];
    }
}
