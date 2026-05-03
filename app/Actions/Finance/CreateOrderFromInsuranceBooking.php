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
        );
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
    ): Order {
        return DB::transaction(function () use ($issuer, $productSubtype, $bookingResult, $requestPayload, $insuranceProvider, $beneficiaryData, $policyDetails): Order {
            $commissionPercent = $insuranceProvider?->commissionForProductType($productSubtype) ?? 0.0;
            $commissionAmount = round(($bookingResult->netPremium * $commissionPercent) / 100, 2);
            $defaultAgencyTenantId = $this->resolveDefaultAgencyTenantId();
            $currency = strtoupper($bookingResult->currency ?: 'LYD');

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
                'payment_method' => 'wallet',
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
                'item_details' => [
                    'financial_source' => 'master_agency_supply',
                    'default_agency_tenant_id' => $defaultAgencyTenantId,
                    'beneficiary' => $beneficiaryData,
                    'insurance' => [
                        'policy_number' => $bookingResult->policyNumber,
                        'report_reference' => $bookingResult->reportReference,
                        'request_payload' => $requestPayload,
                        'provider_response' => $bookingResult->raw,
                    ],
                ],
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

            $this->processWalletTransactions->execute($order, $issuer, allowNegativeBalance: true);
            $this->initializeTenantLedger->execute();
            $this->postToLedger->execute($order, includeOwnCredentials: false);

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
}
