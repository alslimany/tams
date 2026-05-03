<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInsuranceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                    'is_active' => (bool) ($configured?->is_active ?? false),
                    'base_url' => (string) data_get($configured?->credentials ?? [], 'base_url', $provider['base_url']),
                    'token' => (string) data_get($configured?->credentials ?? [], 'token', ''),
                    'commission_compulsory' => (float) ($configured?->commission_compulsory ?? 0),
                    'commission_travel' => (float) ($configured?->commission_travel ?? 0),
                    'commission_orange' => (float) ($configured?->commission_orange ?? 0),
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
        ]);

        if (! collect($this->supportedProviders())->contains(fn (array $provider): bool => $provider['provider_type'] === $validated['provider_type'])) {
            return back()->withErrors([
                'provider_type' => 'Unsupported insurance provider type.',
            ]);
        }

        TenantInsuranceProvider::query()->updateOrCreate(
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

        return back()->with('success', 'Insurance provider configuration saved successfully.');
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
            ],
        ];
    }
}
