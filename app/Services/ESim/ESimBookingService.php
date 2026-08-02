<?php

namespace App\Services\ESim;

use App\Actions\Finance\CreateOrderFromESimPurchase;
use App\Actions\Finance\ProcessESimProviderWalletTransactions;
use App\DTOs\ESim\ESimOrderRequest;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantEsimProvider;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class ESimBookingService
{
    public function __construct(
        protected ESimProviderManager $providerManager,
        protected ESimCatalogueService $catalogue,
        protected CreateOrderFromESimPurchase $createOrderFromESimPurchase,
        protected ProcessESimProviderWalletTransactions $esimProviderWalletTransactions,
    ) {}

    public function startSearch(string $countryIso2): string
    {
        $uuid = (string) Str::uuid();

        Cache::put($this->searchCacheKey($uuid), [
            'country' => strtoupper(trim($countryIso2)),
        ], now()->addMinutes(60));

        return $uuid;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSearch(string $uuid): ?array
    {
        $payload = Cache::get($this->searchCacheKey($uuid));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function packagesForSearch(string $uuid): array
    {
        $search = $this->getSearch($uuid);

        if ($search === null) {
            throw new RuntimeException('eSIM search expired. Please search again.');
        }

        return $this->catalogue->packagesForCountry((string) ($search['country'] ?? ''));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function networksForSearch(string $uuid): array
    {
        $search = $this->getSearch($uuid);

        if ($search === null) {
            return [];
        }

        return $this->catalogue->networksForCountry((string) ($search['country'] ?? ''));
    }

    public function selectPackage(string $searchUuid, string $packageId): string
    {
        $search = $this->getSearch($searchUuid);

        if ($search === null) {
            throw new RuntimeException('eSIM search expired. Please search again.');
        }

        if ($packageId === '') {
            throw new RuntimeException('Please select a package.');
        }

        $packageData = $this->catalogue->resolvePackageData($packageId, $search);
        $bookingUuid = (string) Str::uuid();

        Cache::put($this->bookingCacheKey($bookingUuid), [
            'search' => $search,
            'package' => $packageData,
            'provider_source' => $this->catalogue->activeProviderSource(),
            'created_at' => now()->toISOString(),
        ], now()->addMinutes(60));

        return $bookingUuid;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBooking(string $uuid): ?array
    {
        $payload = Cache::get($this->bookingCacheKey($uuid));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array{name: string, email: string}  $customer
     */
    public function purchase(User $issuer, string $bookingUuid, array $customer): Order
    {
        $cached = $this->getBooking($bookingUuid);

        if ($cached === null) {
            throw new RuntimeException('Selected eSIM package expired. Please search again.');
        }

        $packageData = $cached['package'];
        $providerSource = is_array($cached['provider_source'] ?? null) ? $cached['provider_source'] : [];
        $esimProvider = $this->providerManager->activeProvider();

        if (! $esimProvider instanceof TenantEsimProvider) {
            throw new RuntimeException('eSIM provider is not configured.');
        }

        $providerCurrency = strtoupper((string) ($packageData['provider_currency'] ?? 'USD'));
        $providerAmount = round((float) ($packageData['provider_price'] ?? $packageData['price'] ?? 0), 2);

        $this->esimProviderWalletTransactions->assertCanWithdrawForSource(
            $providerSource,
            $esimProvider,
            $providerCurrency,
            $providerAmount,
        );

        $providerOrg = $this->providerManager->provider()->organization();
        $apiBalance = (float) ($providerOrg['balance'] ?? 0);

        if ($apiBalance < $providerAmount) {
            throw new InsufficientWalletBalanceException($providerCurrency, $providerAmount, $apiBalance);
        }

        $orderRequest = new ESimOrderRequest(
            packageId: (string) ($packageData['id'] ?? ''),
            quantity: 1,
            customerEmail: (string) ($customer['email'] ?? ''),
            customerName: (string) ($customer['name'] ?? ''),
        );

        $orderResult = $this->providerManager->provider()->processOrder($orderRequest);

        $order = $this->createOrderFromESimPurchase->execute(
            userId: $issuer->id,
            orderResult: $orderResult,
            packageData: $packageData,
            customerData: $customer,
            providerSource: $providerSource,
            esimProvider: $esimProvider,
        );

        $this->esimProviderWalletTransactions->execute($order, $esimProvider);

        return $order->fresh(['items']);
    }

    /**
     * Packages that can top up an existing issued eSIM (same destination country).
     *
     * @return list<array<string, mixed>>
     */
    public function topupPackagesForItem(OrderItem $item): array
    {
        $this->assertIssuedEsimItem($item);

        $country = (string) (
            data_get($item->item_details, 'country')
            ?: data_get($item->product_details, 'country')
            ?: ''
        );

        if ($country === '') {
            throw new RuntimeException('Unable to resolve destination country for this eSIM.');
        }

        return $this->catalogue->packagesForCountry($country);
    }

    /**
     * Purchase additional quota on an existing ICCID via L2 processOrders top-up.
     */
    public function topup(User $issuer, OrderItem $item, string $packageId): Order
    {
        $this->assertIssuedEsimItem($item);
        $item->loadMissing('order');

        $iccid = (string) ($item->ticket_number ?: data_get($item->item_details, 'iccid', ''));

        if ($iccid === '') {
            throw new RuntimeException('No ICCID found for this eSIM order item.');
        }

        $country = (string) (
            data_get($item->item_details, 'country')
            ?: data_get($item->product_details, 'country')
            ?: ''
        );

        if ($country === '') {
            throw new RuntimeException('Unable to resolve destination country for this eSIM.');
        }

        $packageData = $this->catalogue->resolvePackageData($packageId, ['country' => $country]);
        $packageData['iccid'] = $iccid;
        $providerSource = is_array(data_get($item->item_details, 'provider_source'))
            ? data_get($item->item_details, 'provider_source')
            : $this->catalogue->activeProviderSource();

        // Reconstruct provider_source from item metadata when nested keys exist.
        if (! isset($providerSource['source_type']) && data_get($item->item_details, 'provider_source_type')) {
            $providerSource = array_filter([
                'source_type' => data_get($item->item_details, 'provider_source_type'),
                'provider_selector' => data_get($item->item_details, 'provider_selector'),
                'source_agency_tenant_id' => data_get($item->item_details, 'source_agency_tenant_id'),
                'merchant_tenant_id' => data_get($item->item_details, 'merchant_tenant_id'),
                'network_membership_id' => data_get($item->item_details, 'network_membership_id'),
                'provider_allocation_id' => data_get($item->item_details, 'provider_allocation_id'),
                'source_provider_model' => data_get($item->item_details, 'source_provider_model'),
                'source_provider_id' => data_get($item->item_details, 'source_provider_id'),
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        $esimProvider = $this->providerManager->activeProvider();

        if (! $esimProvider instanceof TenantEsimProvider) {
            throw new RuntimeException('eSIM provider is not configured.');
        }

        $providerCurrency = strtoupper((string) ($packageData['provider_currency'] ?? 'USD'));
        $providerAmount = round((float) ($packageData['provider_price'] ?? $packageData['price'] ?? 0), 2);

        $this->esimProviderWalletTransactions->assertCanWithdrawForSource(
            $providerSource,
            $esimProvider,
            $providerCurrency,
            $providerAmount,
        );

        $providerOrg = $this->providerManager->provider()->organization();
        $apiBalance = (float) ($providerOrg['balance'] ?? 0);

        if ($apiBalance < $providerAmount) {
            throw new InsufficientWalletBalanceException($providerCurrency, $providerAmount, $apiBalance);
        }

        $customer = [
            'name' => (string) (
                data_get($item->item_details, 'customer.name')
                ?: data_get($item->order?->contact, 'full_name')
                ?: $issuer->name
            ),
            'email' => (string) (
                data_get($item->item_details, 'customer.email')
                ?: data_get($item->order?->contact, 'email')
                ?: $issuer->email
            ),
        ];

        $orderResult = $this->providerManager->provider()->processOrder(new ESimOrderRequest(
            packageId: (string) ($packageData['id'] ?? $packageId),
            quantity: 1,
            customerEmail: $customer['email'],
            customerName: $customer['name'],
            iccid: $iccid,
        ));

        // Keep original ICCID when top-up response omits it.
        if ($orderResult->iccid === '') {
            $orderResult = new \App\DTOs\ESim\ESimOrderResult(
                orderId: $orderResult->orderId,
                iccid: $iccid,
                activationCode: $orderResult->activationCode,
                smdpAddress: $orderResult->smdpAddress,
                qrCodeUrl: $orderResult->qrCodeUrl,
                status: $orderResult->status,
                assigned: $orderResult->assigned,
            );
        }

        $order = $this->createOrderFromESimPurchase->execute(
            userId: $issuer->id,
            orderResult: $orderResult,
            packageData: $packageData,
            customerData: $customer,
            providerSource: $providerSource,
            esimProvider: $esimProvider,
            options: [
                'transaction_type' => 'topup',
                'parent_order_item_id' => $item->id,
                'parent_order_id' => $item->order_id,
            ],
        );

        $this->esimProviderWalletTransactions->execute($order, $esimProvider);

        return $order->fresh(['items']);
    }

    protected function assertIssuedEsimItem(OrderItem $item): void
    {
        if (! in_array((string) $item->type, ['esim'], true) && (string) $item->product_type !== 'esim') {
            throw new RuntimeException('Order item is not an eSIM.');
        }

        if ((string) $item->status !== 'issued') {
            throw new RuntimeException('Only issued eSIMs can be topped up.');
        }
    }

    protected function searchCacheKey(string $uuid): string
    {
        return 'esim_search_'.$uuid;
    }

    protected function bookingCacheKey(string $uuid): string
    {
        return 'esim_booking_'.$uuid;
    }
}
