<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
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
        $activeProvider = $this->providerManager->activeProviderWithSource();
        $provider = $activeProvider['provider'];

        return Inertia::render('Tenant/Insurance/Search', [
            'productTypes' => [
                ['value' => 'compulsory', 'label' => __('common.compulsory_insurance')],
                ['value' => 'travel', 'label' => __('common.travel_insurance')],
                ['value' => 'orange', 'label' => __('common.orange_insurance')],
            ],
            'lookupsByType' => [
                'compulsory' => ['durations'],
                'travel' => ['zones', 'durations'],
                'orange' => ['cars', 'countries', 'document_types', 'vehicle_nationalities'],
            ],
            'providers' => $this->providerManager->configuredProviders(),
            'activeProvider' => [
                'name' => $provider?->name ?? 'Not configured',
                'type' => $provider?->provider_type,
                'commission_compulsory' => (float) ($provider?->commission_compulsory ?? 0),
                'commission_travel' => (float) ($provider?->commission_travel ?? 0),
                'commission_orange' => (float) ($provider?->commission_orange ?? 0),
                'source' => $activeProvider['source'],
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
