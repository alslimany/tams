<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AirlineAccount;
use App\Models\TenantProvider;
use App\Services\Airline\ProviderFactory;
use App\Services\Airline\Videcom\BaseVidecomAirline;
use Exception;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AirlineConfigController extends Controller
{
    /**
     * List of all airlines supported by the system.
     */
    protected function getSupportedAirlines(): array
    {
        return [
            [
                'id' => 'YI',
                'iata' => 'YI',
                'icao' => 'OYA',
                'name' => 'Oya Airline',
                'provider_type' => 'videcom',
                'videcom_code' => 'OYA',
                'base_url' => 'https://customer3.videcom.com/OYA',
                'accounts' => [
                    ['name' => 'Default Account', 'currency' => 'LYD'],
                ],
            ],
            [
                'id' => 'BM',
                'iata' => 'BM',
                'icao' => 'MNS',
                'name' => 'Medsky Airline',
                'provider_type' => 'videcom',
                'videcom_code' => 'Medsky',
                'base_url' => 'https://customer3.videcom.com/Medsky',
                'accounts' => [
                    ['name' => 'Default Account', 'currency' => 'LYD', 'airports' => ['IST', 'MJI', 'BEN']],
                    ['name' => 'EUR Account', 'currency' => 'EUR', 'airports' => ['MLA', 'FCO']],
                ],
            ],
            [
                'id' => 'UZ',
                'iata' => 'UZ',
                'icao' => 'BRQ',
                'name' => 'Buraq Air',
                'provider_type' => 'videcom',
                'videcom_code' => 'Buraq',
                'base_url' => 'https://booking.buraq.aero',
                'accounts' => [
                    ['name' => 'Default Account', 'currency' => 'LYD'],
                ],
            ],
            [
                'id' => 'YL',
                'iata' => 'YL',
                'icao' => 'LWA',
                'name' => 'Libyan Wings',
                'provider_type' => 'videcom',
                'videcom_code' => 'LibyanWings',
                'base_url' => 'https://booking.libyanwings.ly',
                'accounts' => [
                    ['name' => 'Default Account', 'currency' => 'LYD'],
                ],
            ],
            [
                'id' => 'NB',
                'iata' => 'NB',
                'icao' => 'BNL',
                'name' => 'Berniq Air',
                'provider_type' => 'videcom',
                'videcom_code' => 'Berniq',
                'base_url' => 'https://customer3.videcom.com/BerniqAirways',
                'accounts' => [
                    ['name' => 'Default Account', 'currency' => 'LYD'],
                ],
            ],
            [
                'id' => '5S',
                'iata' => '5S',
                'icao' => 'GAK',
                'name' => 'Global Air',
                'provider_type' => 'videcom',
                'videcom_code' => 'GlobalAir',
                'base_url' => 'https://customer2.videcom.com/GlobalAirTransport',
                'accounts' => [
                    ['name' => 'Default Account', 'currency' => 'LYD'],
                ],
            ],
            [
                'id' => 'FQ',
                'iata' => 'FQ',
                'icao' => 'CWN',
                'name' => 'Crown Air',
                'provider_type' => 'videcom',
                'videcom_code' => 'FlyCrown',
                'base_url' => 'https://customer2.videcom.com/FlyCrown',
                'accounts' => [
                    ['name' => 'Default Account', 'currency' => 'LYD'],
                ],
            ],
            [
                'id' => 'LB',
                'iata' => 'LB',
                'icao' => 'LBA',
                'name' => 'Libyan Express',
                'provider_type' => 'videcom',
                'videcom_code' => 'LibyanExpress',
                'base_url' => 'https://customer2.videcom.com/LibyanExpress',
                'accounts' => [
                    ['name' => 'Default Account', 'currency' => 'LYD'],
                ],
            ],
        ];
    }

    public function index()
    {
        $supportedAirlines = $this->getSupportedAirlines();
        $configuredAirlines = TenantProvider::all()->groupBy('airline_code');

        $airlines = collect($supportedAirlines)->map(function ($airline) use ($configuredAirlines) {
            $configs = $configuredAirlines->get($airline['id'], collect());

            $airline['accounts'] = collect($airline['accounts'])->map(function ($account) use ($configs, $airline) {
                $existing = $configs->firstWhere('account_name', $account['name']);
                $remainingBalance = null;

                if ($existing) {
                    $accountBalance = AirlineAccount::query()
                        ->where('tenant_provider_id', $existing->id)
                        ->where('currency', $account['currency'])
                        ->value('balance');

                    if ($accountBalance !== null) {
                        $remainingBalance = number_format((float) $accountBalance, 2, '.', '');
                    }
                }

                return array_merge($account, [
                    'is_enabled' => $existing ? $existing->is_active : false,
                    'config_id' => $existing ? $existing->id : null,
                    'credentials' => $existing ? $existing->credentials : null,
                    'last_tested_at' => $existing ? $existing->last_tested_at : null,
                    'last_test_status' => $existing ? $existing->last_test_status : null,
                    'last_test_message' => $existing ? $existing->last_test_message : null,
                    'airline_code' => $airline['id'],
                    'provider_type' => $airline['provider_type'],
                    'remaining_balance' => $remainingBalance,
                    'domestic_commission_rate' => $existing ? $existing->domestic_commission_rate : null,
                    'international_commission_rate' => $existing ? $existing->international_commission_rate : null,
                    'commission_domestic' => $existing ? $existing->commission_domestic : null,
                    'commission_international' => $existing ? $existing->commission_international : null,
                ]);
            }
            );

            return $airline;
        });

        return Inertia::render('Tenant/Settings/AirConfig/Index', [
            'airlines' => $airlines,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'provider_type' => 'required|string',
            'airline_code' => 'required|string',
            'airline_name' => 'required|string',
            'account_name' => 'required|string',
            'mode' => 'required|in:api,session',
            'username' => 'required_if:mode,session|nullable|string',
            'password' => 'required_if:mode,session|nullable|string',
            'token' => 'required_if:mode,api|nullable|string',
            'base_url' => 'required|url',
            'currency' => 'required|string|size:3',
            'airports' => 'nullable|array',
            'domestic_commission_rate' => 'nullable|numeric|min:0|max:100',
            'international_commission_rate' => 'nullable|numeric|min:0|max:100',
            'commission_domestic' => 'nullable|numeric|min:0',
            'commission_international' => 'nullable|numeric|min:0',
        ]);

        $credentials = [
            'mode' => $validated['mode'],
            'base_url' => $validated['base_url'],
            'currency' => $validated['currency'],
            'airports' => $validated['airports'] ?? [],
            'airline_code' => $validated['airline_code'],
        ];

        if ($validated['mode'] === 'session') {
            $credentials['username'] = $validated['username'];
            $credentials['password'] = $validated['password'];
        } else {
            $credentials['token'] = $validated['token'];
        }

        $provider = TenantProvider::updateOrCreate(
            [
                'provider_type' => $validated['provider_type'],
                'airline_code' => $validated['airline_code'],
                'account_name' => $validated['account_name'],
            ],
            [
                'airline_name' => $validated['airline_name'],
                'credentials' => $credentials,
                'is_active' => true,
                'last_tested_at' => now(),
                'last_test_status' => 'configured',
                'last_test_message' => null,
                'domestic_commission_rate' => $this->normalizeCommissionRate($validated['domestic_commission_rate'] ?? null),
                'international_commission_rate' => $this->normalizeCommissionRate($validated['international_commission_rate'] ?? null),
                'commission_domestic' => $this->normalizeCommissionRate($validated['commission_domestic'] ?? null) ?? 0,
                'commission_international' => $this->normalizeCommissionRate($validated['commission_international'] ?? null) ?? 0,
            ]
        );

        $this->syncOpeningBalance($provider);

        return back()->with('success', "{$validated['airline_name']} ({$validated['account_name']}) configured successfully.");
    }

    public function testConnection(Request $request)
    {
        $validated = $request->validate([
            'provider_type' => 'required|string',
            'airline_code' => 'required|string',
            'account_name' => 'nullable|string',
            'mode' => 'required|in:api,session',
            'username' => 'required_if:mode,session|nullable|string',
            'password' => 'required_if:mode,session|nullable|string',
            'token' => 'required_if:mode,api|nullable|string',
            'base_url' => 'required|url',
            'currency' => 'required|string|size:3',
            'airports' => 'nullable|array',
            'domestic_commission_rate' => 'nullable|numeric|min:0|max:100',
            'international_commission_rate' => 'nullable|numeric|min:0|max:100',
            'commission_domestic' => 'nullable|numeric|min:0',
            'commission_international' => 'nullable|numeric|min:0',
        ]);

        $credentials = [
            'mode' => $validated['mode'],
            'base_url' => $validated['base_url'],
            'currency' => $validated['currency'],
            'airports' => $validated['airports'] ?? [],
            'airline_code' => $validated['airline_code'],
        ];

        if ($validated['mode'] === 'session') {
            $credentials['username'] = $validated['username'];
            $credentials['password'] = $validated['password'];
        } else {
            $credentials['token'] = $validated['token'];
        }

        try {
            // Use a temporary TenantProvider model (not saved) to use the factory
            $tempConfig = new TenantProvider([
                'provider_type' => $validated['provider_type'],
                'airline_code' => $validated['airline_code'],
                'credentials' => $credentials,
            ]);

            $provider = ProviderFactory::make($tempConfig);
            $provider->testConnection();

            $existing = TenantProvider::query()
                ->where('provider_type', $validated['provider_type'])
                ->where('airline_code', $validated['airline_code'])
                ->where('account_name', $request->string('account_name'))
                ->first();

            if ($existing) {
                $existing->update([
                    'last_tested_at' => now(),
                    'last_test_status' => 'passed',
                    'last_test_message' => 'Connection successful.',
                ]);

                $this->syncOpeningBalance($existing);
            }

            return response()->json(['success' => true, 'message' => 'Connection successful!']);
        } catch (Exception $e) {
            $existing = TenantProvider::query()
                ->where('provider_type', $validated['provider_type'])
                ->where('airline_code', $validated['airline_code'])
                ->where('account_name', $request->string('account_name'))
                ->first();

            if ($existing) {
                $existing->update([
                    'last_tested_at' => now(),
                    'last_test_status' => 'failed',
                    'last_test_message' => $e->getMessage(),
                ]);
            }

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function toggle(TenantProvider $provider)
    {
        $provider->update(['is_active' => ! $provider->is_active]);

        return back()->with('success', $provider->airline_name.' '.($provider->is_active ? 'enabled' : 'disabled'));
    }

    protected function syncOpeningBalance(TenantProvider $provider): void
    {
        try {
            $airlineProvider = ProviderFactory::make($provider);

            if (! $airlineProvider instanceof BaseVidecomAirline) {
                return;
            }

            $currency = strtoupper((string) data_get($provider->credentials, 'currency', ''));
            $balanceResult = $airlineProvider->fetchWalletBalance($currency);

            $balanceCurrency = strtoupper((string) ($balanceResult['currency'] ?? $currency));
            $balanceAmount = (float) ($balanceResult['balance'] ?? 0);

            AirlineAccount::query()->updateOrCreate(
                [
                    'tenant_provider_id' => $provider->id,
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

    protected function normalizeCommissionRate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}
