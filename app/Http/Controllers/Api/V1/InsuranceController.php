<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Finance\CreateOrderFromInsuranceBooking;
use App\Actions\Finance\ProcessInsuranceProviderWalletTransactions;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Api\Controller;
use App\Models\User;
use App\Services\AgencyNetwork\MerchantAgencyWalletManager;
use App\Services\Insurance\InsuranceProviderManager;
use App\Services\Insurance\Providers\AlBarakaProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class InsuranceController extends Controller
{
    public function __construct(
        protected AlBarakaProvider $provider,
        protected InsuranceProviderManager $providerManager,
        protected CreateOrderFromInsuranceBooking $createOrderFromInsuranceBooking,
        protected ProcessInsuranceProviderWalletTransactions $insuranceProviderWalletTransactions,
        protected MerchantAgencyWalletManager $merchantAgencyWalletManager,
    ) {}

    /**
     * Compulsory insurance reference data.
     */
    public function compulsoryReferences(string $type): JsonResponse
    {
        $compulsory = app(\App\Http\Controllers\Tenant\CompulsoryInsuranceController::class);

        return match ($type) {
            'durations' => $compulsory->durationsReference(),
            'document-types' => $compulsory->documentTypesReference(),
            'vehicle-types' => $compulsory->vehicleTypesReference(),
            'colors' => $compulsory->colorsReference(),
            'licensing-authorities' => $compulsory->licensingAuthoritiesReference(),
            default => $this->error("Unknown reference type: {$type}", 404),
        };
    }

    /**
     * Calculate compulsory insurance price.
     */
    public function compulsoryPrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type_id' => ['required', 'integer'],
            'duration_id' => ['required', 'integer'],
            'seats' => ['required', 'integer', 'min:1'],
            'payload' => ['nullable', 'integer'],
        ]);

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
                'provider_source' => $this->providerManager->activeProviderSource(),
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

            Cache::put("insurance_quote_{$quoteToken}", $quote, now()->addMinutes(30));

            return $this->success($quote, 'Price calculated successfully.');
        } catch (Throwable $e) {
            report($e);

            return $this->error('Unable to calculate price: '.$e->getMessage(), 422);
        }
    }

    /**
     * Issue compulsory insurance policy.
     */
    public function compulsoryIssue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quote_token' => ['required', 'string'],
            'beneficiary_name' => ['required', 'string'],
            'beneficiary_phone' => ['required', 'string'],
            'beneficiary_address' => ['nullable', 'string'],
            'beneficiary_email' => ['nullable', 'email'],
            'vehicle_type_id' => ['required', 'integer'],
            'vehicle_color_id' => ['required', 'integer'],
            'vehicle_licensing_authority_id' => ['required', 'integer'],
            'vehicle_manufacture_year' => ['required', 'integer', 'min:1900', 'max:'.((int) date('Y') + 1)],
            'vehicle_chassis_number' => ['required', 'string'],
            'vehicle_plate_number' => ['required', 'string'],
            'vehicle_payload' => ['nullable', 'numeric'],
            'vehicle_type_engine_power' => ['nullable', 'integer'],
            'policy_date_from' => ['required', 'date'],
        ]);

        $quote = Cache::get("insurance_quote_{$validated['quote_token']}");

        if (! $quote) {
            return $this->error('Quote expired. Please calculate price again.', 410);
        }

        $issuer = $request->user();

        if (! $issuer instanceof User) {
            return $this->error('Authentication required.', 401);
        }

        $insuranceProvider = $this->providerManager->activeProvider();
        $providerSource = is_array($quote['provider_source'] ?? null) ? $quote['provider_source'] : [];
        $totalPremium = round((float) ($quote['total_premium'] ?? 0), 2);
        $currency = strtoupper((string) ($quote['currency'] ?? 'LYD'));

        // Validate wallet
        try {
            if ((string) data_get($providerSource, 'source_type') === 'agency_network') {
                $this->merchantAgencyWalletManager->assertCanWithdrawForSource($issuer, $providerSource, $currency, $totalPremium);
            }

            $this->insuranceProviderWalletTransactions->assertCanWithdrawForSource($providerSource, $insuranceProvider, $currency, $totalPremium);
        } catch (InsufficientWalletBalanceException $e) {
            return $this->error($e->getMessage(), 402);
        }

        try {
            $beneficiaryAddress = (string) ($validated['beneficiary_address'] ?? 'Not provided');

            $existingClientProfileId = $this->provider->findClientProfileByPhone((string) $validated['beneficiary_phone']);

            $clientProfileId = $existingClientProfileId
                ?? Cache::remember(
                    "client_profile_{$issuer->id}_".md5(json_encode($validated)),
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
                'PriceDetailID' => $this->resolvePriceDetailId($quote),
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

            $finalTotalPremium = (float) ($policyData['TotalPremium'] ?? $policyDetailsResponse['raw']['TotalPremium'] ?? $quote['total_premium']);

            $policyDetails = [
                'policy_id' => (int) $policy['policy_id'],
                'policy_number' => (string) ($policyData['PolicyNumber'] ?? $policy['policy_number'] ?? ''),
                'report_reference' => (string) ($policyData['EncryptedId'] ?? $policyData['CardNumber'] ?? ''),
                'total_amount' => round((float) $finalTotalPremium, 2),
                'net_amount' => round((float) ($policyData['NetPremium'] ?? $quote['net_premium']), 2),
                'tax_amount' => round((float) ($policyData['Tax'] ?? $quote['tax_amount']), 2),
                'currency' => strtoupper((string) ($policyData['Curr'] ?? $quote['currency'] ?? 'LYD')),
                'raw' => $policyDetailsResponse['raw'],
            ];

            $order = $this->createOrderFromInsuranceBooking->createFromPolicyDetails(
                userId: $issuer->id,
                productSubtype: 'compulsory',
                policyDetails: $policyDetails,
                beneficiaryData: [
                    'name' => $validated['beneficiary_name'],
                    'phone' => $validated['beneficiary_phone'],
                    'address' => $beneficiaryAddress,
                    'email' => $validated['beneficiary_email'] ?? null,
                ],
                requestPayload: [
                    'quote' => $quote,
                    'provider_source' => $providerSource,
                    'issue_request' => $validated,
                    'client_profile_id' => $clientProfileId,
                    'client_profile_vehicle_id' => $vehicleId,
                ],
                insuranceProvider: $insuranceProvider,
                processAgencyWallet: (string) data_get($providerSource, 'source_type') === 'agency_network',
            );

            return $this->success([
                'order_id' => $order->id,
                'order_number' => $order->number,
                'policy_number' => $policyDetails['policy_number'],
                'policy_id' => $policyDetails['policy_id'],
                'report_reference' => $policyDetails['report_reference'],
                'total_amount' => $policyDetails['total_amount'],
                'currency' => $policyDetails['currency'],
            ], 'Policy issued successfully.', 201);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Failed to issue policy: '.$e->getMessage(), 422);
        }
    }

    protected function resolvePriceDetailId(array $quote): int
    {
        $raw = $quote['raw'] ?? [];

        return (int) ($raw['PriceDetails'][0]['PriceDetailId'] ?? $raw['PriceDetailId'] ?? 0);
    }
}
