<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantHotelProvider;
use App\Services\Hotels\HotelApiException;
use App\Services\Hotels\HotelProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HotelConfigController extends Controller
{
    public function index(): Response
    {
        $configuredProviders = TenantHotelProvider::query()
            ->get()
            ->keyBy('provider_type');

        $providers = collect($this->supportedProviders())
            ->map(function (array $provider) use ($configuredProviders): array {
                /** @var TenantHotelProvider|null $configured */
                $configured = $configuredProviders->get($provider['provider_type']);
                $currency = strtoupper((string) ($configured?->currency ?? $provider['default_currency']));

                return [
                    'name' => $provider['name'],
                    'provider_type' => $provider['provider_type'],
                    'description' => $provider['description'],
                    'default_currency' => $provider['default_currency'],
                    'is_active' => (bool) ($configured?->is_active ?? false),
                    'base_url' => (string) data_get($configured?->credentials ?? [], 'base_url', $provider['base_url']),
                    'api_key' => (string) data_get($configured?->credentials ?? [], 'api_key', ''),
                    'login' => (string) data_get($configured?->credentials ?? [], 'login', ''),
                    'password' => (string) data_get($configured?->credentials ?? [], 'password', ''),
                    'commission_hotel' => (float) ($configured?->commission_hotel ?? 0),
                    'currency' => $currency,
                    'remaining_balance' => round((float) ($configured?->getBalance($currency) ?? 0), 2),
                    'provider_credit_balance' => (float) data_get($configured?->credentials ?? [], 'last_credit_check.balance', 0),
                    'provider_credit_currency' => (string) data_get($configured?->credentials ?? [], 'last_credit_check.currency', $currency),
                    'provider_credit_checked_at' => data_get($configured?->credentials ?? [], 'last_credit_check.checked_at'),
                    'requires_initial_balance' => $configured !== null && (float) $configured->getBalance($currency) <= 0,
                    'status' => $configured ? 'configured' : 'not_configured',
                ];
            })
            ->values();

        return Inertia::render('Tenant/Settings/Hotels', [
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
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'commission_hotel' => ['required', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'initial_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! collect($this->supportedProviders())->contains(fn (array $provider): bool => $provider['provider_type'] === $validated['provider_type'])) {
            return back()->withErrors([
                'provider_type' => 'Unsupported hotel provider type.',
            ]);
        }

        $supportedProvider = collect($this->supportedProviders())
            ->firstWhere('provider_type', $validated['provider_type']);
        $currency = strtoupper((string) ($validated['currency'] ?? $supportedProvider['default_currency'] ?? TenantHotelProvider::DEFAULT_CURRENCY));

        $provider = TenantHotelProvider::query()->updateOrCreate(
            ['provider_type' => $validated['provider_type']],
            [
                'name' => $validated['name'],
                'provider_type' => $validated['provider_type'],
                'is_active' => $validated['is_active'],
                'currency' => $currency,
                'credentials' => [
                    'base_url' => rtrim($validated['base_url'], '/'),
                    'api_key' => $validated['api_key'],
                    'login' => $validated['login'],
                    'password' => $validated['password'],
                ],
                'commission_hotel' => $validated['commission_hotel'],
            ]
        );

        $initialBalance = (float) ($validated['initial_balance'] ?? 0);
        $wallet = $provider->getOrCreateCurrencyWallet($currency);

        if ($initialBalance > 0 && (float) $wallet->balanceFloat <= 0) {
            $wallet->depositFloat($initialBalance, [
                'type' => 'opening_balance',
                'description' => 'Opening balance set from hotel provider configuration.',
                'provider_type' => $provider->provider_type,
                'product_type' => 'hotel',
            ]);
        }

        return back()->with('success', 'Hotel provider configuration saved successfully.');
    }

    public function deposit(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'provider_type' => ['required', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $provider = TenantHotelProvider::query()
            ->where('provider_type', $validated['provider_type'])
            ->first();

        if (! $provider instanceof TenantHotelProvider) {
            return back()->withErrors([
                'provider_type' => 'Hotel provider is not configured yet.',
            ]);
        }

        $currency = strtoupper((string) ($validated['currency'] ?? $provider->providerCurrency()));
        $depositAmount = round((float) $validated['amount'], 2);

        DB::transaction(function () use ($provider, $currency, $depositAmount, $validated): void {
            $wallet = $provider->getOrCreateCurrencyWallet($currency);

            $wallet->depositFloat($depositAmount, [
                'type' => 'deposit',
                'description' => (string) ($validated['description'] ?? 'Manual balance deposit from hotel settings.'),
                'provider_type' => $provider->provider_type,
                'product_type' => 'hotel',
            ]);
        });

        return back()->with('success', 'Hotel provider wallet balance updated.');
    }

    public function syncCredit(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'provider_type' => ['required', 'string', 'max:50'],
        ]);

        $provider = TenantHotelProvider::query()
            ->where('provider_type', $validated['provider_type'])
            ->first();

        if (! $provider instanceof TenantHotelProvider) {
            return back()->withErrors([
                'provider_type' => 'Hotel provider is not configured yet.',
            ]);
        }

        try {
            $payload = HotelProviderFactory::make($provider)->creditCheck();
            $credit = $this->normalizeCreditCheck($payload, $provider->providerCurrency());

            $provider->update([
                'credentials' => [
                    ...(is_array($provider->credentials) ? $provider->credentials : []),
                    'last_credit_check' => [
                        ...$credit,
                        'checked_at' => now()->toISOString(),
                        'raw' => $payload['raw'] ?? $payload,
                    ],
                ],
            ]);
        } catch (HotelApiException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', '3T provider credit balance synced.');
    }

    /**
     * @return array<int, array{provider_type:string,name:string,description:string,base_url:string,default_currency:string}>
     */
    protected function supportedProviders(): array
    {
        return [
            [
                'provider_type' => '3t',
                'name' => '3T Hotels',
                'description' => 'Hotel search, booking, and cancellation via 3T API.',
                'base_url' => 'https://babaldiwan.com.ly',
                'default_currency' => TenantHotelProvider::DEFAULT_CURRENCY,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{balance: float, currency: string}
     */
    protected function normalizeCreditCheck(array $payload, string $fallbackCurrency): array
    {
        $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];
        $balance = data_get($response, 'balance', data_get($response, 'credit', data_get($response, 'amount', data_get($payload, 'raw.balance', 0))));
        $currency = data_get($response, 'currency', data_get($payload, 'raw.currency', $fallbackCurrency));

        return [
            'balance' => round((float) $balance, 2),
            'currency' => strtoupper((string) $currency),
        ];
    }
}
