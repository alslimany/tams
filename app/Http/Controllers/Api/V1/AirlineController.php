<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Controller;
use App\Services\Airline\AgencyProviderResolver;
use App\Support\AirlineLogoUrl;
use Illuminate\Http\JsonResponse;

class AirlineController extends Controller
{
    public function __construct(
        protected AgencyProviderResolver $providerResolver,
    ) {}

    /**
     * List all active airlines available to the tenant.
     */
    public function index(): JsonResponse
    {
        $providers = $this->providerResolver->getAllActiveProviders();

        $airlines = $providers
            ->map(fn ($p) => [
                'airline_code' => $p->airline_code,
                'airline_name' => $p->airline_name,
                'logo_url' => AirlineLogoUrl::forCode((string) $p->airline_code),
            ])
            ->unique(fn ($a) => $a['airline_code'])
            ->sortBy('airline_name')
            ->values();

        return $this->success($airlines);
    }
}
