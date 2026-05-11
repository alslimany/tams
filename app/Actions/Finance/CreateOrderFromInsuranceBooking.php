<?php

namespace App\Actions\Finance;

use App\DTOs\Insurance\InsuranceBookingResult;
use App\Models\Tenant;
use App\Models\Tenant\AgencySetting;
use App\Models\Tenant\Order;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\User;
use App\Services\Orders\OrderNumberGenerator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateOrderFromInsuranceBooking
{
    public function __construct(
        protected OrderNumberGenerator $orderNumberGenerator,
        protected ProcessWalletTransactions $processWalletTransactions,
        protected InitializeTenantLedger $initializeTenantLedger,
        protected PostToLedger $postToLedger,
    ) {}

    /**
     * @param  array<string, mixed>  $requestPayload
     */
    public function execute(
        string $productSubtype,
        InsuranceBookingResult $bookingResult,
        array $requestPayload,
        ?TenantInsuranceProvider $insuranceProvider = null,
    ): Order {
        $issuer = Auth::user();

        if (! $issuer instanceof User) {
            throw new RuntimeException('An authenticated user is required to create insurance orders.');
        }

        return $this->createForIssuer(
            issuer: $issuer,
            productSubtype: $productSubtype,
            bookingResult: $bookingResult,
            requestPayload: $requestPayload,
            insuranceProvider: $insuranceProvider,
            beneficiaryData: null,
            policyDetails: null,
        );
    }

    /**
     * @param  array<string, mixed>  $policyDetails
     * @param  array<string, mixed>  $beneficiaryData
     * @param  array<string, mixed>  $requestPayload
     */
    public function createFromPolicyDetails(
        int $userId,
        string $productSubtype,
        array $policyDetails,
        array $beneficiaryData,
        array $requestPayload = [],
        ?TenantInsuranceProvider $insuranceProvider = null,
        bool $processAgencyWallet = true,
    ): Order {
        $issuer = User::query()->find($userId);

        if (! $issuer instanceof User) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        $totalPremium = round((float) ($policyDetails['total_amount'] ?? $policyDetails['total_premium'] ?? 0), 2);
        $netPremium = round((float) ($policyDetails['net_amount'] ?? $policyDetails['net_premium'] ?? 0), 2);
        $taxAmount = round((float) ($policyDetails['tax_amount'] ?? max(0, $totalPremium - $netPremium)), 2);

        $bookingResult = new InsuranceBookingResult(
            success: true,
            message: (string) ($policyDetails['message'] ?? 'Insurance policy issued successfully.'),
            policyNumber: (string) ($policyDetails['policy_number'] ?? ''),
            reportReference: (string) ($policyDetails['report_reference'] ?? ''),
            totalPremium: $totalPremium,
            netPremium: $netPremium,
            taxAmount: $taxAmount,
            currency: (string) ($policyDetails['currency'] ?? 'LYD'),
            raw: is_array($policyDetails['raw'] ?? null) ? $policyDetails['raw'] : $policyDetails,
        );

        return $this->createForIssuer(
            issuer: $issuer,
            productSubtype: $productSubtype,
            bookingResult: $bookingResult,
            requestPayload: $requestPayload,
            insuranceProvider: $insuranceProvider,
            beneficiaryData: $beneficiaryData,
            policyDetails: $policyDetails,
            processAgencyWallet: $processAgencyWallet,
        );
    }

    /**
     * @param  array<string, mixed>  $clientProfileData
     * @param  array<int, array<string, mixed>>  $policyItems
     * @param  array<string, mixed>  $requestPayload
     */
    public function createFromTravelPolicies(
        int $userId,
        array $clientProfileData,
        array $policyItems,
        array $requestPayload = [],
        ?TenantInsuranceProvider $insuranceProvider = null,
        bool $processAgencyWallet = true,
    ): Order {
        $issuer = User::query()->find($userId);

        if (! $issuer instanceof User) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        return DB::transaction(function () use ($issuer, $clientProfileData, $policyItems, $requestPayload, $insuranceProvider, $processAgencyWallet): Order {
            $commissionPercent = $insuranceProvider?->commissionForProductType('travel') ?? 0.0;
            $defaultAgencyTenantId = $this->resolveDefaultAgencyTenantId();
            $currency = strtoupper((string) ($policyItems[0]['currency'] ?? 'LYD'));
            $providerSource = $this->providerSourceFromRequestPayload($requestPayload);
            $providerSourceDetails = $this->providerSourceItemDetails($providerSource);
            $financialSource = $this->financialSourceForProviderSource($providerSource, $processAgencyWallet);

            $subtotal = 0.0;
            $taxTotal = 0.0;
            $grandTotal = 0.0;

            foreach ($policyItems as $item) {
                $subtotal += (float) ($item['net_amount'] ?? $item['net_premium'] ?? 0);
                $taxTotal += (float) ($item['tax_amount'] ?? 0);
                $grandTotal += (float) ($item['total_amount'] ?? $item['total_premium'] ?? 0);
            }

            $order = Order::query()->create([
                'owner_type' => $issuer::class,
                'owner_id' => $issuer->id,
                'number' => $this->generateUniqueOrderNumber(),
                'status' => 'issued',
                'issued_at' => now(),
                'subtotal' => round($subtotal, 2),
                'tax_total' => round($taxTotal, 2),
                'grand_total' => round($grandTotal, 2),
                'amount_paid' => round($grandTotal, 2),
                'currency' => $currency,
                'payment_method' => $processAgencyWallet ? 'wallet' : 'provider_wallet',
                'payment_reference' => (string) ($policyItems[0]['policy_number'] ?? $policyItems[0]['report_reference'] ?? ''),
                'contact' => [
                    'full_name' => (string) ($clientProfileData['name'] ?? ''),
                    'phone' => (string) ($clientProfileData['phone'] ?? ''),
                    'email' => (string) ($clientProfileData['email'] ?? ''),
                    'address' => (string) ($clientProfileData['address'] ?? ''),
                ],
            ]);

            foreach ($policyItems as $item) {
                $netPremium = round((float) ($item['net_amount'] ?? $item['net_premium'] ?? 0), 2);
                $totalPremium = round((float) ($item['total_amount'] ?? $item['total_premium'] ?? 0), 2);
                $taxAmount = round((float) ($item['tax_amount'] ?? max(0, $totalPremium - $netPremium)), 2);
                $commissionAmount = round(($netPremium * $commissionPercent) / 100, 2);
                $passenger = is_array($item['passenger'] ?? null) ? $item['passenger'] : [];
                $policyDetails = is_array($item['policy_details'] ?? null) ? $item['policy_details'] : [];
                $passengerName = trim(((string) ($passenger['first_name'] ?? '')).' '.((string) ($passenger['last_name'] ?? '')));

                $order->items()->create([
                    'type' => 'insurance',
                    'product_type' => 'insurance',
                    'product_subtype' => 'travel',
                    'provider' => $insuranceProvider?->provider_type ?? 'albaraka',
                    'provider_reference' => (string) ($policyDetails['policy_id'] ?? ''),
                    'ticket_number' => (string) ($policyDetails['policy_number'] ?? ''),
                    'item_details' => array_merge($providerSourceDetails, [
                        'passenger_name' => $passengerName,
                        'financial_source' => $financialSource,
                        'default_agency_tenant_id' => $defaultAgencyTenantId,
                        'beneficiary' => $clientProfileData,
                        'insurance' => [
                            'passenger' => $passenger,
                            'zone_id' => $policyDetails['zone_id'] ?? null,
                            'duration_id' => $policyDetails['duration_id'] ?? null,
                            'policy_date_from' => $policyDetails['policy_date_from'] ?? null,
                            'policy_date_to' => $policyDetails['policy_date_to'] ?? null,
                            'policy_number' => (string) ($policyDetails['policy_number'] ?? ''),
                            'report_reference' => (string) ($policyDetails['report_reference'] ?? ''),
                            'provider_response' => $policyDetails['raw'] ?? $policyDetails,
                            'request_payload' => $requestPayload,
                        ],
                    ]),
                    'product_details' => [
                        'provider' => $insuranceProvider?->name ?? 'Al Baraka Insurance',
                        'product_subtype' => 'travel',
                        'beneficiary' => $clientProfileData,
                        'passenger_name' => $passengerName,
                        'zone_id' => $policyDetails['zone_id'] ?? null,
                        'duration_id' => $policyDetails['duration_id'] ?? null,
                        'policy_date_from' => $policyDetails['policy_date_from'] ?? null,
                        'policy_date_to' => $policyDetails['policy_date_to'] ?? null,
                        'passenger' => $passenger,
                        'policy_details' => $policyDetails,
                    ],
                    'price' => $netPremium,
                    'net_fare' => $netPremium,
                    'taxes' => [
                        [
                            'code' => 'INS_TAX',
                            'amount' => $taxAmount,
                            'currency' => strtoupper((string) ($item['currency'] ?? $currency)),
                        ],
                    ],
                    'total_tax' => $taxAmount,
                    'total' => $totalPremium,
                    'total_amount' => $totalPremium,
                    'currency' => strtoupper((string) ($item['currency'] ?? $currency)),
                    'exchange_rate' => 1,
                    'status' => 'issued',
                    'transaction_type' => 'purchase',
                    'commission_percent' => $commissionPercent,
                    'commission_amount' => $commissionAmount,
                    'net_after_commission' => round($netPremium - $commissionAmount, 2),
                    'agent_commission' => $commissionAmount,
                    'net_commission' => $commissionAmount,
                    'paid' => $totalPremium,
                    'remaining' => 0,
                ]);
            }

            if ($processAgencyWallet) {
                $this->processWalletTransactions->execute($order, $issuer, allowNegativeBalance: false);
            }

            if ($processAgencyWallet) {
                $this->initializeTenantLedger->execute();
                $this->postToLedger->execute($order, includeOwnCredentials: false);
            }

            return $order->fresh('items');
        });
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>|null  $beneficiaryData
     * @param  array<string, mixed>|null  $policyDetails
     */
    protected function createForIssuer(
        User $issuer,
        string $productSubtype,
        InsuranceBookingResult $bookingResult,
        array $requestPayload,
        ?TenantInsuranceProvider $insuranceProvider,
        ?array $beneficiaryData,
        ?array $policyDetails,
        bool $processAgencyWallet = true,
    ): Order {
        return DB::transaction(function () use ($issuer, $productSubtype, $bookingResult, $requestPayload, $insuranceProvider, $beneficiaryData, $policyDetails, $processAgencyWallet): Order {
            $commissionPercent = $insuranceProvider?->commissionForProductType($productSubtype) ?? 0.0;
            $commissionAmount = round(($bookingResult->netPremium * $commissionPercent) / 100, 2);
            $defaultAgencyTenantId = $this->resolveDefaultAgencyTenantId();
            $currency = strtoupper($bookingResult->currency ?: 'LYD');
            $providerSource = $this->providerSourceFromRequestPayload($requestPayload);
            $providerSourceDetails = $this->providerSourceItemDetails($providerSource);
            $financialSource = $this->financialSourceForProviderSource($providerSource, $processAgencyWallet);

            $order = Order::query()->create([
                'owner_type' => $issuer::class,
                'owner_id' => $issuer->id,
                'number' => $this->generateUniqueOrderNumber(),
                'status' => 'issued',
                'issued_at' => now(),
                'subtotal' => $bookingResult->netPremium,
                'tax_total' => $bookingResult->taxAmount,
                'grand_total' => $bookingResult->totalPremium,
                'amount_paid' => $bookingResult->totalPremium,
                'currency' => $currency,
                'payment_method' => $processAgencyWallet ? 'wallet' : 'provider_wallet',
                'payment_reference' => $bookingResult->policyNumber ?: $bookingResult->reportReference,
                'contact' => null,
            ]);

            $order->items()->create([
                'type' => 'insurance',
                'product_type' => 'insurance',
                'product_subtype' => strtolower($productSubtype),
                'provider' => $insuranceProvider?->provider_type ?? 'albaraka',
                'provider_reference' => $bookingResult->policyNumber ?: $bookingResult->reportReference,
                'ticket_number' => $bookingResult->policyNumber,
                'item_details' => array_merge($providerSourceDetails, [
                    'financial_source' => $financialSource,
                    'default_agency_tenant_id' => $defaultAgencyTenantId,
                    'beneficiary' => $beneficiaryData,
                    'insurance' => [
                        'policy_number' => $bookingResult->policyNumber,
                        'report_reference' => $bookingResult->reportReference,
                        'request_payload' => $requestPayload,
                        'provider_response' => $bookingResult->raw,
                    ],
                ]),
                'product_details' => [
                    'provider' => $insuranceProvider?->name ?? 'Al Baraka Insurance',
                    'product_subtype' => strtolower($productSubtype),
                    'policy_details' => $policyDetails,
                    'beneficiary' => $beneficiaryData,
                ],
                'price' => $bookingResult->netPremium,
                'net_fare' => $bookingResult->netPremium,
                'taxes' => [
                    [
                        'code' => 'INS_TAX',
                        'amount' => $bookingResult->taxAmount,
                        'currency' => $currency,
                    ],
                ],
                'total_tax' => $bookingResult->taxAmount,
                'total' => $bookingResult->totalPremium,
                'total_amount' => $bookingResult->totalPremium,
                'currency' => $currency,
                'exchange_rate' => 1,
                'status' => 'issued',
                'transaction_type' => 'issue',
                'commission_percent' => $commissionPercent,
                'commission_amount' => $commissionAmount,
                'net_after_commission' => round($bookingResult->netPremium - $commissionAmount, 2),
                'agent_commission' => $commissionAmount,
                'net_commission' => $commissionAmount,
                'paid' => $bookingResult->totalPremium,
                'remaining' => 0,
            ]);

            if ($processAgencyWallet) {
                $this->processWalletTransactions->execute($order, $issuer, allowNegativeBalance: false);
            }

            if ($processAgencyWallet) {
                $this->initializeTenantLedger->execute();
                $this->postToLedger->execute($order, includeOwnCredentials: false);
            }

            return $order->fresh('items');
        });
    }

    protected function resolveDefaultAgencyTenantId(): ?string
    {
        $agencySettings = AgencySetting::current();

        return $agencySettings->default_agency_tenant_id
            ?: Tenant::getDefaultAgency()?->id;
    }

    protected function generateUniqueOrderNumber(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $number = $this->orderNumberGenerator->generate();

            if (! Order::query()->where('number', $number)->exists()) {
                return $number;
            }
        }

        throw new RuntimeException('Unable to generate a unique order number.');
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @return array<string, mixed>
     */
    protected function providerSourceFromRequestPayload(array $requestPayload): array
    {
        $providerSource = data_get($requestPayload, 'provider_source');

        if (is_array($providerSource)) {
            return $providerSource;
        }

        $quoteProviderSource = data_get($requestPayload, 'quote.provider_source');

        return is_array($quoteProviderSource) ? $quoteProviderSource : [];
    }

    /**
     * @param  array<string, mixed>  $providerSource
     * @return array<string, mixed>
     */
    protected function providerSourceItemDetails(array $providerSource): array
    {
        $details = [];

        if ($providerSource === []) {
            return $details;
        }

        $details['provider_source_type'] = data_get($providerSource, 'source_type');

        foreach (['provider_selector', 'source_agency_tenant_id', 'merchant_tenant_id', 'network_membership_id', 'provider_allocation_id', 'source_provider_model', 'source_provider_id'] as $metadataKey) {
            $metadataValue = data_get($providerSource, $metadataKey);

            if ($metadataValue !== null) {
                $details[$metadataKey] = $metadataValue;
            }
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $providerSource
     */
    protected function financialSourceForProviderSource(array $providerSource, bool $processAgencyWallet): string
    {
        if ((string) data_get($providerSource, 'source_type') === 'agency_network') {
            return 'agency_network_supply';
        }

        return $processAgencyWallet ? 'master_agency_supply' : 'own_provider_wallet';
    }
}
