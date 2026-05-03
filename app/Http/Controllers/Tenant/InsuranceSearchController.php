<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Services\Insurance\InsuranceProviderManager;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class InsuranceSearchController extends Controller
{
    public function __construct(
        protected InsuranceProviderManager $providerManager,
    ) {}

    public function index(): Response
    {
        $provider = $this->providerManager->activeProvider();
        $configuredProviders = TenantInsuranceProvider::query()
            ->orderBy('name')
            ->get()
            ->map(fn (TenantInsuranceProvider $item): array => [
                'provider_type' => $item->provider_type,
                'name' => $item->name,
                'is_active' => (bool) $item->is_active,
            ])
            ->values();

        return Inertia::render('Tenant/Insurance/Search', [
            'productTypes' => [
                ['value' => 'compulsory', 'label' => 'Compulsory'],
                ['value' => 'travel', 'label' => 'Travel'],
                ['value' => 'orange', 'label' => 'Orange'],
            ],
            'lookupsByType' => [
                'compulsory' => ['durations'],
                'travel' => ['zones', 'durations'],
                'orange' => ['cars', 'countries', 'document_types', 'vehicle_nationalities'],
            ],
            'providers' => $configuredProviders,
            'activeProvider' => [
                'name' => $provider?->name ?? 'Not configured',
                'type' => $provider?->provider_type,
                'commission_compulsory' => (float) ($provider?->commission_compulsory ?? 0),
                'commission_travel' => (float) ($provider?->commission_travel ?? 0),
                'commission_orange' => (float) ($provider?->commission_orange ?? 0),
            ],
        ]);
    }

    public function lookup(string $productType, string $lookupKey): JsonResponse
    {
        $data = $this->providerManager->provider()->lookup($productType, $lookupKey);

        return response()->json([
            'data' => $data,
        ]);
    }
}
