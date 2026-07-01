<?php

namespace App\Services\ESim;

use App\Actions\Finance\CreateOrderFromESimPurchase;
use App\Actions\Finance\ProcessESimProviderWalletTransactions;
use App\DTOs\ESim\ESimOrderRequest;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Tenant\Order;
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

        $currency = strtoupper((string) ($packageData['currency'] ?? 'USD'));
        $amount = round((float) ($packageData['price'] ?? 0), 2);

        $this->esimProviderWalletTransactions->assertCanWithdrawForSource($providerSource, $esimProvider, $currency, $amount);

        $providerOrg = $this->providerManager->provider()->organization();
        $apiBalance = (float) ($providerOrg['balance'] ?? 0);

        if ($apiBalance < $amount) {
            throw new InsufficientWalletBalanceException(
                "Insufficient provider balance. Required: \${$amount} {$currency}, available: \${$apiBalance} {$currency}."
            );
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

    protected function searchCacheKey(string $uuid): string
    {
        return 'esim_search_'.$uuid;
    }

    protected function bookingCacheKey(string $uuid): string
    {
        return 'esim_booking_'.$uuid;
    }
}
