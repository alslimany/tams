<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\CreateOrderFromInsuranceBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Insurance\CompulsoryIssueRequest;
use App\Http\Requests\Tenant\Insurance\CompulsoryPriceRequest;
use App\Models\Tenant\Order;
use App\Models\User;
use App\Services\Insurance\InsuranceProviderManager;
use App\Services\Insurance\Providers\AlBarakaProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CompulsoryInsuranceController extends Controller
{
    public function __construct(
        protected AlBarakaProvider $provider,
        protected InsuranceProviderManager $providerManager,
        protected CreateOrderFromInsuranceBooking $createOrderFromInsuranceBooking,
    ) {}

    public function searchPage(): Response
    {
        return Inertia::render('Tenant/Insurance/CompulsorySearch', [
            'durations' => $this->cachedReference('durations', fn (): array => $this->provider->compulsoryDurations()),
            'documentTypes' => $this->cachedReference('document_types', fn (): array => $this->provider->compulsoryDocumentTypes()),
        ]);
    }

    public function beneficiaryPage(string $quoteToken): Response|RedirectResponse
    {
        $quote = $this->pullQuote($quoteToken, keepInSession: true);

        if ($quote === null) {
            return redirect()
                ->route('insurance.compulsory.search')
                ->with('error', 'The selected quote has expired. Please get a new price.');
        }

        return Inertia::render('Tenant/Insurance/CompulsoryBeneficiary', [
            'quoteToken' => $quoteToken,
            'quote' => $quote,
            'vehicleTypes' => $this->cachedReference('vehicle_types', fn (): array => $this->provider->compulsoryVehicleTypes()),
            'colors' => $this->cachedReference('colors', fn (): array => $this->provider->compulsoryColors()),
            'licensingAuthorities' => $this->cachedReference('licensing_authorities', fn (): array => $this->provider->compulsoryLicensingAuthorities()),
        ]);
    }

    public function issuedPage(Order $order): Response|RedirectResponse
    {
        $order->loadMissing('items');

        $insuranceItem = $order->items
            ->where('product_type', 'insurance')
            ->first();

        if ($insuranceItem === null) {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'Insurance order details were not found.');
        }

        return Inertia::render('Tenant/Insurance/CompulsoryIssued', [
            'order' => $order,
            'item' => $insuranceItem,
        ]);
    }

    public function durationsReference(): JsonResponse
    {
        return $this->referenceResponse('durations', fn (): array => $this->provider->compulsoryDurations());
    }

    public function documentTypesReference(): JsonResponse
    {
        return $this->referenceResponse('document_types', fn (): array => $this->provider->compulsoryDocumentTypes());
    }

    public function vehicleTypesReference(): JsonResponse
    {
        return $this->referenceResponse('vehicle_types', fn (): array => $this->provider->compulsoryVehicleTypes());
    }

    public function colorsReference(): JsonResponse
    {
        return $this->referenceResponse('colors', fn (): array => $this->provider->compulsoryColors());
    }

    public function licensingAuthoritiesReference(): JsonResponse
    {
        return $this->referenceResponse('licensing_authorities', fn (): array => $this->provider->compulsoryLicensingAuthorities());
    }

    public function price(CompulsoryPriceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $price = $this->provider->calculateCompulsoryPrice([
                'ClientProfileVehicleId' => (int) $validated['document_type_id'],
                'InsuranceDurationId' => (int) $validated['duration_id'],
                'NoPassengers' => (int) $validated['seats'],
                'Payload' => isset($validated['payload']) ? (int) $validated['payload'] : null,
            ]);

            $quoteToken = (string) Str::uuid();
            $quote = [
                'quote_token' => $quoteToken,
                'document_type_id' => (int) $validated['document_type_id'],
                'duration_id' => (int) $validated['duration_id'],
                'seats' => (int) $validated['seats'],
                'payload' => isset($validated['payload']) ? (int) $validated['payload'] : null,
                'total_premium' => (float) $price['total_premium'],
                'net_premium' => (float) $price['net_premium'],
                'tax_amount' => (float) $price['tax_amount'],
                'currency' => (string) $price['currency'],
                'raw' => $price['raw'],
                'created_at' => now()->toISOString(),
            ];

            $this->storeQuote($quoteToken, $quote);

            return response()->json($quote);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to calculate compulsory insurance price. Please verify your inputs and provider configuration.',
            ], 422);
        }
    }

    public function issue(CompulsoryIssueRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $quote = $this->pullQuote((string) $validated['quote_token'], keepInSession: true);
        if ($quote === null) {
            return back()->with('error', 'The selected quote has expired. Please calculate the price again.');
        }

        $issuer = $request->user();

        if (! $issuer instanceof User) {
            return back()->with('error', 'Authentication is required to issue compulsory insurance.');
        }

        try {
            $beneficiaryAddress = (string) ($validated['beneficiary_address'] ?? 'Not provided');
            $existingClientProfileId = $this->provider->findClientProfileByPhone((string) $validated['beneficiary_phone']);

            $clientProfileId = $existingClientProfileId
                ?? Cache::remember(
                    $this->clientProfileCacheKey($issuer->id, $validated),
                    now()->addDay(),
                    function () use ($validated, $beneficiaryAddress): int {
                        return $this->provider->createClientProfile([
                            'Name' => $validated['beneficiary_name'],
                            'Phone' => $validated['beneficiary_phone'],
                            'Address' => $beneficiaryAddress,
                            'Email' => $validated['beneficiary_email'] ?? null,
                        ]);
                    }
                );

            $vehicleId = $this->provider->createClientProfileVehicle([
                'Name' => $validated['beneficiary_name'],
                'Address' => $beneficiaryAddress,
                'ClientProfileId' => (int) $clientProfileId,
                'CarId' => (int) $validated['vehicle_type_id'],
                'ColorId' => (int) $validated['vehicle_color_id'],
                'ChassisNumber' => $validated['vehicle_chassis_number'],
                'MetalPlateNo' => $validated['vehicle_plate_number'],
                'ManufactureYear' => (int) $validated['vehicle_manufacture_year'],
                'LicensingAuthorityId' => (int) $validated['vehicle_licensing_authority_id'],
                'NoPassengers' => (int) $quote['seats'],
                'Payload' => isset($validated['vehicle_payload']) ? (float) $validated['vehicle_payload'] : (float) ($quote['payload'] ?? 0),
                'TypeEnginePower' => (int) ($validated['vehicle_type_engine_power'] ?? 0),
                'PriceDetailID' => (int) $this->resolvePriceDetailId($quote),
            ]);

            $policy = $this->provider->createCompulsoryPolicy([
                'Check' => null,
                'ClientProfileId' => (int) $clientProfileId,
                'ClientProfileVehicleId' => (int) $vehicleId,
                'PolicyDateFrom' => now()->parse((string) $validated['policy_date_from'])->format('Y-m-d\\TH:i:s'),
                'InsuranceDurationId' => (string) $quote['duration_id'],
                'IsPolicyPaid' => false,
                'VoucherCode' => null,
            ]);

            $policyDetailsResponse = $this->provider->getCompulsoryPolicy((int) $policy['policy_id']);
            $policyData = is_array($policyDetailsResponse['data'] ?? null) ? $policyDetailsResponse['data'] : [];

            $totalPremium = $this->extractAmount($policyData, $policyDetailsResponse['raw'], ['TotalPremium', 'TotalPrice', 'PolicyPrice', 'Price', 'Premium'], (float) ($quote['total_premium'] ?? 0));
            $netPremium = $this->extractAmount($policyData, $policyDetailsResponse['raw'], ['NetPremium', 'NetPrice', 'Net'], (float) ($quote['net_premium'] ?? 0));
            $taxAmount = $this->extractAmount($policyData, $policyDetailsResponse['raw'], ['Tax', 'Taxes', 'TaxAmount'], max(0, $totalPremium - $netPremium));

            $policyDetails = [
                'policy_id' => (int) $policy['policy_id'],
                'policy_number' => (string) ($policyData['PolicyNumber'] ?? $policy['policy_number'] ?? ''),
                'report_reference' => (string) ($policyData['EncryptedId'] ?? $policyData['CardNumber'] ?? ''),
                'total_amount' => round($totalPremium, 2),
                'net_amount' => round($netPremium, 2),
                'tax_amount' => round($taxAmount, 2),
                'currency' => strtoupper((string) ($policyData['Curr'] ?? $policyData['Currency'] ?? $quote['currency'] ?? 'LYD')),
                'raw' => $policyDetailsResponse['raw'],
            ];

            $beneficiaryData = [
                'name' => $validated['beneficiary_name'],
                'phone' => $validated['beneficiary_phone'],
                'address' => (string) ($validated['beneficiary_address'] ?? 'Not provided'),
                'email' => $validated['beneficiary_email'] ?? null,
                'vehicle_type_id' => (int) $validated['vehicle_type_id'],
                'vehicle_color_id' => (int) $validated['vehicle_color_id'],
                'vehicle_licensing_authority_id' => (int) $validated['vehicle_licensing_authority_id'],
                'vehicle_manufacture_year' => (int) $validated['vehicle_manufacture_year'],
                'vehicle_chassis_number' => $validated['vehicle_chassis_number'],
                'vehicle_plate_number' => $validated['vehicle_plate_number'],
                'vehicle_payload' => isset($validated['vehicle_payload']) ? (float) $validated['vehicle_payload'] : (float) ($quote['payload'] ?? 0),
            ];

            $order = $this->createOrderFromInsuranceBooking->createFromPolicyDetails(
                userId: $issuer->id,
                productSubtype: 'compulsory',
                policyDetails: $policyDetails,
                beneficiaryData: $beneficiaryData,
                requestPayload: [
                    'quote' => $quote,
                    'issue_request' => $validated,
                    'client_profile_id' => $clientProfileId,
                    'client_profile_vehicle_id' => $vehicleId,
                ],
                insuranceProvider: $this->providerManager->activeProvider(),
            );

            $this->forgetQuote((string) $validated['quote_token']);

            return redirect()
                ->route('insurance.compulsory.issued', $order)
                ->with('success', 'Compulsory insurance policy issued successfully.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Unable to issue compulsory insurance at the moment. Please try again.');
        }
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    protected function resolvePriceDetailId(array $quote): int
    {
        $candidates = [
            data_get($quote, 'raw.data.PriceDetailID'),
            data_get($quote, 'raw.data.PriceDetailId'),
            data_get($quote, 'raw.data.PriceDetailsId'),
            data_get($quote, 'raw.PriceDetailID'),
            data_get($quote, 'raw.PriceDetailId'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return 14;
    }

    /**
     * @param  callable():array<int, array<string, mixed>>  $resolver
     */
    protected function referenceResponse(string $key, callable $resolver): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->cachedReference($key, $resolver),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to fetch reference data from insurance provider.',
            ], 422);
        }
    }

    /**
     * @param  callable():array<int, array<string, mixed>>  $resolver
     * @return array<int, array<string, mixed>>
     */
    protected function cachedReference(string $key, callable $resolver): array
    {
        return Cache::remember($this->referenceCacheKey($key), now()->addDay(), $resolver);
    }

    protected function referenceCacheKey(string $key): string
    {
        return 'insurance:compulsory:reference:'.($this->tenantId() ?? 'central').':'.$key;
    }

    protected function clientProfileCacheKey(int $userId, array $validated): string
    {
        $signature = strtolower(implode('|', [
            (string) $validated['beneficiary_name'],
            (string) $validated['beneficiary_phone'],
            (string) $validated['beneficiary_address'],
            (string) ($validated['beneficiary_email'] ?? ''),
        ]));

        return 'insurance:compulsory:client_profile:'.($this->tenantId() ?? 'central').':'.$userId.':'.sha1($signature);
    }

    protected function tenantId(): ?string
    {
        $tenant = tenant();

        if (! $tenant) {
            return null;
        }

        return (string) $tenant->id;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pullQuote(string $quoteToken, bool $keepInSession): ?array
    {
        $quotes = session()->get('insurance.compulsory_quotes', []);

        if (! is_array($quotes) || ! isset($quotes[$quoteToken]) || ! is_array($quotes[$quoteToken])) {
            return null;
        }

        $quote = $quotes[$quoteToken];

        if (! $keepInSession) {
            unset($quotes[$quoteToken]);
            session()->put('insurance.compulsory_quotes', $quotes);
        }

        return $quote;
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    protected function storeQuote(string $quoteToken, array $quote): void
    {
        $quotes = session()->get('insurance.compulsory_quotes', []);

        if (! is_array($quotes)) {
            $quotes = [];
        }

        $quotes[$quoteToken] = $quote;
        session()->put('insurance.compulsory_quotes', $quotes);
    }

    protected function forgetQuote(string $quoteToken): void
    {
        $quotes = session()->get('insurance.compulsory_quotes', []);

        if (! is_array($quotes)) {
            return;
        }

        unset($quotes[$quoteToken]);
        session()->put('insurance.compulsory_quotes', $quotes);
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $fallback
     * @param  array<int, string>  $keys
     */
    protected function extractAmount(array $primary, array $fallback, array $keys, float $default): float
    {
        foreach ($keys as $key) {
            $primaryValue = data_get($primary, $key);
            if (is_numeric($primaryValue)) {
                return (float) $primaryValue;
            }

            $fallbackValue = data_get($fallback, $key);
            if (is_numeric($fallbackValue)) {
                return (float) $fallbackValue;
            }
        }

        return $default;
    }
}
