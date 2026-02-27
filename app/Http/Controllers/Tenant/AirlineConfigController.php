<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantProvider;
use App\Services\Airline\ProviderFactory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Exception;

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
                'name' => 'Oya Airline',
                'provider_type' => 'videcom',
                'videcom_code' => 'OYa',
                'base_url' => 'https://customer3.videcom.com/OYa',
                'accounts' => [
                    ['name' => 'Default Account', 'currency' => 'LYD']
                ]
            ],
            [
                'id' => 'BM',
                'name' => 'Medsky Airline',
                'provider_type' => 'videcom',
                'videcom_code' => 'Medsky',
                'base_url' => 'https://customer3.videcom.com/Medsky',
                'accounts' => [
                    ['name' => 'LYD Account', 'currency' => 'LYD', 'airports' => ['IST', 'MJI', 'BEN']],
                    ['name' => 'EUR Account', 'currency' => 'EUR', 'airports' => ['MLA', 'FCO']],
                ]
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
                return array_merge($account, [
                    'is_enabled' => $existing ? $existing->is_active : false,
                    'config_id' => $existing ? $existing->id : null,
                    'airline_code' => $airline['id'],
                    'provider_type' => $airline['provider_type'],
                ]);
            });

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
            'mode' => 'required|in:soap,session',
            'username' => 'required_if:mode,session|nullable|string',
            'password' => 'required_if:mode,session|nullable|string',
            'token' => 'required_if:mode,soap|nullable|string',
            'base_url' => 'required|url',
            'currency' => 'required|string|size:3',
            'airports' => 'nullable|array',
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
            ]
        );

        return back()->with('success', "{$validated['airline_name']} ({$validated['account_name']}) configured successfully.");
    }

    public function testConnection(Request $request)
    {
        $validated = $request->validate([
            'provider_type' => 'required|string',
            'airline_code' => 'required|string',
            'mode' => 'required|in:soap,session',
            'username' => 'required_if:mode,session|nullable|string',
            'password' => 'required_if:mode,session|nullable|string',
            'token' => 'required_if:mode,soap|nullable|string',
            'base_url' => 'required|url',
            'currency' => 'required|string|size:3',
            'airports' => 'nullable|array',
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

            return response()->json(['success' => true, 'message' => 'Connection successful!']);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function toggle(TenantProvider $provider)
    {
        $provider->update(['is_active' => !$provider->is_active]);
        return back()->with('success', $provider->airline_name . ' ' . ($provider->is_active ? 'enabled' : 'disabled'));
    }
}
