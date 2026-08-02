<?php

namespace App\Actions\Finance;

use App\DTOs\ESim\ESimOrderResult;
use App\Models\Tenant\AgencySetting;
use App\Models\Tenant\Order;
use App\Models\Tenant\TenantEsimProvider;
use App\Models\User;
use App\Services\Orders\OrderNumberGenerator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateOrderFromESimPurchase
{
    public function __construct(
        protected OrderNumberGenerator $orderNumberGenerator,
        protected InitializeTenantLedger $initializeTenantLedger,
        protected PostToLedger $postToLedger,
    ) {}

    /**
     * @param  array<string, mixed>  $packageData
     * @param  array<string, mixed>  $customerData
     * @param  array<string, mixed>  $providerSource
     * @param  array{transaction_type?: string, parent_order_item_id?: int|null, parent_order_id?: int|null}  $options
     */
    public function execute(
        int $userId,
        ESimOrderResult $orderResult,
        array $packageData,
        array $customerData,
        array $providerSource = [],
        ?TenantEsimProvider $esimProvider = null,
        array $options = [],
    ): Order {
        $issuer = User::query()->find($userId);

        if (! $issuer instanceof User) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        return DB::transaction(function () use ($issuer, $orderResult, $packageData, $customerData, $providerSource, $esimProvider, $options): Order {
            $currency = strtoupper((string) ($packageData['currency'] ?? 'USD'));
            $price = round((float) ($packageData['price'] ?? 0), 2);
            $providerCurrency = strtoupper((string) ($packageData['provider_currency'] ?? 'USD'));
            $providerPrice = round((float) ($packageData['provider_price'] ?? $packageData['price'] ?? 0), 2);
            $exchangeRate = isset($packageData['exchange_rate']) && (float) $packageData['exchange_rate'] > 0
                ? round((float) $packageData['exchange_rate'], 4)
                : 1.0;
            $commissionPercent = $esimProvider?->commissionForProductType('esim') ?? 0.0;
            $commissionAmount = round(($price * $commissionPercent) / 100, 2);
            $defaultAgencyTenantId = $this->resolveDefaultAgencyTenantId();
            $providerSourceDetails = $this->providerSourceItemDetails($providerSource);
            $financialSource = $this->financialSourceForProviderSource($providerSource);
            $transactionType = (string) ($options['transaction_type'] ?? 'purchase');
            $parentOrderItemId = isset($options['parent_order_item_id']) ? (int) $options['parent_order_item_id'] : null;
            $parentOrderId = isset($options['parent_order_id']) ? (int) $options['parent_order_id'] : null;
            $iccid = $orderResult->iccid !== ''
                ? $orderResult->iccid
                : (string) ($packageData['iccid'] ?? '');

            $order = Order::query()->create([
                'owner_type' => $issuer::class,
                'owner_id' => $issuer->id,
                'number' => $this->generateUniqueOrderNumber(),
                'status' => 'issued',
                'issued_at' => now(),
                'subtotal' => $price,
                'tax_total' => 0,
                'grand_total' => $price,
                'amount_paid' => $price,
                'currency' => $currency,
                'payment_method' => 'wallet',
                'payment_reference' => $orderResult->orderId,
                'contact' => [
                    'full_name' => (string) ($customerData['name'] ?? ''),
                    'email' => (string) ($customerData['email'] ?? ''),
                    'phone' => '',
                ],
            ]);

            $order->items()->create([
                'type' => 'esim',
                'product_type' => 'esim',
                'product_subtype' => $transactionType === 'topup' ? 'esim_topup' : 'esim',
                'provider' => $esimProvider?->provider_type ?? 'l2',
                'provider_reference' => $orderResult->orderId,
                'ticket_number' => $iccid,
                'item_details' => array_merge($providerSourceDetails, [
                    'financial_source' => $financialSource,
                    'default_agency_tenant_id' => $defaultAgencyTenantId,
                    'package_id' => (string) ($packageData['id'] ?? ''),
                    'package_name' => (string) ($packageData['name'] ?? ''),
                    'country' => (string) ($packageData['country'] ?? ''),
                    'data_mb' => (int) ($packageData['data_mb'] ?? 0),
                    'validity_days' => (int) ($packageData['validity_days'] ?? 0),
                    'provider' => $esimProvider?->provider_type ?? 'l2',
                    'provider_order_id' => $orderResult->orderId,
                    'provider_cost' => $providerPrice,
                    'provider_currency' => $providerCurrency,
                    'iccid' => $iccid,
                    'activation_code' => $orderResult->activationCode,
                    'smdp_address' => $orderResult->smdpAddress,
                    'lpa_string' => $orderResult->smdpAddress && $orderResult->activationCode
                        ? "LPA:1\${$orderResult->smdpAddress}\${$orderResult->activationCode}"
                        : null,
                    'qr_code_url' => $orderResult->qrCodeUrl,
                    'status' => $orderResult->status,
                    'assigned' => $orderResult->assigned,
                    'customer' => $customerData,
                    'transaction_type' => $transactionType,
                    'parent_order_item_id' => $parentOrderItemId,
                    'parent_order_id' => $parentOrderId,
                    'package' => [
                        'id' => (string) ($packageData['id'] ?? ''),
                        'name' => (string) ($packageData['name'] ?? ''),
                        'country' => (string) ($packageData['country'] ?? ''),
                        'data_mb' => (int) ($packageData['data_mb'] ?? 0),
                        'validity_days' => (int) ($packageData['validity_days'] ?? 0),
                        'price' => $price,
                        'currency' => $currency,
                    ],
                ]),
                'product_details' => [
                    'provider' => $esimProvider?->name ?? 'L2 Travel eSIM',
                    'package_id' => (string) ($packageData['id'] ?? ''),
                    'package_name' => (string) ($packageData['name'] ?? ''),
                    'country' => (string) ($packageData['country'] ?? ''),
                    'data_mb' => (int) ($packageData['data_mb'] ?? 0),
                    'validity_days' => (int) ($packageData['validity_days'] ?? 0),
                    'iccid' => $iccid,
                    'activation_code' => $orderResult->activationCode,
                    'smdp_address' => $orderResult->smdpAddress,
                    'lpa_string' => $orderResult->smdpAddress && $orderResult->activationCode
                        ? "LPA:1\${$orderResult->smdpAddress}\${$orderResult->activationCode}"
                        : null,
                    'qr_code_url' => $orderResult->qrCodeUrl,
                    'customer' => $customerData,
                    'provider_cost' => $providerPrice,
                    'provider_currency' => $providerCurrency,
                    'transaction_type' => $transactionType,
                    'parent_order_item_id' => $parentOrderItemId,
                    'parent_order_id' => $parentOrderId,
                ],
                'price' => $price,
                'net_fare' => $price,
                'taxes' => [],
                'total_tax' => 0,
                'total' => $price,
                'total_amount' => $price,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'status' => 'issued',
                'transaction_type' => $transactionType,
                'commission_percent' => $commissionPercent,
                'commission_amount' => $commissionAmount,
                'net_after_commission' => round($price - $commissionAmount, 2),
                'agent_commission' => $commissionAmount,
                'net_commission' => $commissionAmount,
                'paid' => $price,
                'remaining' => 0,
            ]);

            $this->initializeTenantLedger->execute();
            $this->postToLedger->execute($order, includeOwnCredentials: false);

            return $order->fresh('items');
        });
    }

    protected function resolveDefaultAgencyTenantId(): ?string
    {
        $agencySettings = AgencySetting::current();

        return $agencySettings->default_agency_tenant_id
            ?: \App\Models\Tenant::getDefaultAgency()?->id;
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

        foreach (['provider_selector', 'source_agency_tenant_id', 'merchant_tenant_id', 'network_membership_id', 'provider_allocation_id', 'source_provider_model', 'source_provider_id'] as $key) {
            $value = data_get($providerSource, $key);

            if ($value !== null) {
                $details[$key] = $value;
            }
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $providerSource
     */
    protected function financialSourceForProviderSource(array $providerSource): string
    {
        if ((string) data_get($providerSource, 'source_type') === 'agency_network') {
            return 'agency_network_supply';
        }

        return 'master_agency_supply';
    }
}
