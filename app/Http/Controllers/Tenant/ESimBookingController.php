<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\CreateOrderFromESimPurchase;
use App\Actions\Finance\ProcessESimProviderWalletTransactions;
use App\DTOs\ESim\ESimOrderRequest;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ESim\ESimBookRequest;
use App\Http\Requests\Tenant\ESim\ESimSearchRequest;
use App\Models\Tenant\TenantEsimProvider;
use App\Models\User;
use App\Services\ESim\ESimApiException;
use App\Services\ESim\ESimProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ESimBookingController extends Controller
{
    public function __construct(
        protected ESimProviderManager $providerManager,
        protected CreateOrderFromESimPurchase $createOrderFromESimPurchase,
        protected ProcessESimProviderWalletTransactions $esimProviderWalletTransactions,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Tenant/ESim/Search');
    }

    public function search(ESimSearchRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $uuid = (string) Str::uuid();

        Cache::put($this->searchCacheKey($uuid), $validated, now()->addMinutes(60));

        return redirect()->route('esim.results', $uuid);
    }

    public function results(string $uuid): Response|RedirectResponse
    {
        $search = $this->pullSearch($uuid);

        if ($search === null) {
            return redirect()->route('esim.index')->with('error', 'eSIM search expired. Please search again.');
        }

        return Inertia::render('Tenant/ESim/Results', [
            'searchUuid' => $uuid,
            'search' => $search,
        ]);
    }

    public function packages(string $uuid): JsonResponse
    {
        $search = $this->pullSearch($uuid);

        if ($search === null) {
            return response()->json(['message' => 'eSIM search expired. Please search again.'], 404);
        }

        try {
            $filters = array_filter([
                'country' => (string) ($search['country'] ?? ''),
                'data_mb' => isset($search['data_mb']) ? (int) $search['data_mb'] : null,
                'validity_days' => isset($search['validity_days']) ? (int) $search['validity_days'] : null,
            ], fn (mixed $v): bool => $v !== null && $v !== '');

            $packages = $this->providerManager->provider()->catalogue($filters);
            $providerSource = $this->providerManager->activeProviderSource();

            return response()->json([
                'packages' => array_map(fn ($pkg): array => array_merge($pkg->toArray(), [
                    'provider_source' => $providerSource,
                ]), $packages),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage() ?: 'Unable to load eSIM packages right now.'], 422);
        }
    }

    public function select(string $uuid): RedirectResponse
    {
        $search = $this->pullSearch($uuid);

        if ($search === null) {
            return redirect()->route('esim.index')->with('error', 'eSIM search expired. Please search again.');
        }

        $packageId = (string) request()->input('package_id', '');

        if ($packageId === '') {
            return back()->with('error', 'Please select a package.');
        }

        try {
            $packageData = $this->resolvePackageData($packageId, $search);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage() ?: 'Unable to load package details.');
        }

        $bookingUuid = (string) Str::uuid();
        $providerSource = $this->providerManager->activeProviderSource();

        Cache::put($this->bookingCacheKey($bookingUuid), [
            'search' => $search,
            'package' => $packageData,
            'provider_source' => $providerSource,
            'created_at' => now()->toISOString(),
        ], now()->addMinutes(60));

        return redirect()->route('esim.checkout', $bookingUuid);
    }

    public function checkout(string $uuid): Response|RedirectResponse
    {
        $booking = $this->pullBooking($uuid);

        if ($booking === null) {
            return redirect()->route('esim.index')->with('error', 'Selected eSIM package expired. Please search again.');
        }

        return Inertia::render('Tenant/ESim/Checkout', [
            'bookingUuid' => $uuid,
            'package' => $booking['package'],
            'search' => $booking['search'],
        ]);
    }

    public function book(ESimBookRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $cached = $this->pullBooking((string) $validated['booking_uuid']);

        if ($cached === null) {
            return redirect()->route('esim.index')->with('error', 'Selected eSIM package expired. Please search again.');
        }

        $packageData = $cached['package'];
        $providerSource = is_array($cached['provider_source'] ?? null) ? $cached['provider_source'] : [];
        $esimProvider = $this->providerManager->activeProvider();

        if (! $esimProvider instanceof TenantEsimProvider) {
            return back()->with('error', 'eSIM provider is not configured.');
        }

        $currency = strtoupper((string) ($packageData['currency'] ?? 'USD'));
        $amount = round((float) ($packageData['price'] ?? 0), 2);

        try {
            $this->esimProviderWalletTransactions->assertCanWithdrawForSource($providerSource, $esimProvider, $currency, $amount);
        } catch (InsufficientWalletBalanceException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $issuer = $request->user();

        if (! $issuer instanceof User) {
            return back()->with('error', 'Authentication is required to purchase eSIM.');
        }

        $customerData = [
            'name' => (string) ($validated['customer']['name'] ?? ''),
            'email' => (string) ($validated['customer']['email'] ?? ''),
        ];

        try {
            $orderRequest = new ESimOrderRequest(
                packageId: (string) ($packageData['id'] ?? ''),
                quantity: 1,
                customerEmail: $customerData['email'],
                customerName: $customerData['name'],
            );

            $orderResult = $this->providerManager->provider()->processOrder($orderRequest);

            $order = $this->createOrderFromESimPurchase->execute(
                userId: $issuer->id,
                orderResult: $orderResult,
                packageData: $packageData,
                customerData: $customerData,
                providerSource: $providerSource,
                esimProvider: $esimProvider,
            );

            $this->esimProviderWalletTransactions->execute($order, $esimProvider);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception instanceof ESimApiException ? $exception->getMessage() : 'Unable to complete eSIM purchase right now.');
        }

        return redirect()->route('orders.show', $order)->with('success', 'eSIM purchased successfully.');
    }

    /**
     * @param  array<string, mixed>  $search
     * @return array<string, mixed>
     */
    protected function resolvePackageData(string $packageId, array $search): array
    {
        $filters = array_filter([
            'country' => (string) ($search['country'] ?? ''),
            'data_mb' => isset($search['data_mb']) ? (int) $search['data_mb'] : null,
            'validity_days' => isset($search['validity_days']) ? (int) $search['validity_days'] : null,
        ], fn (mixed $v): bool => $v !== null && $v !== '');

        $packages = $this->providerManager->provider()->catalogue($filters);

        foreach ($packages as $pkg) {
            if ($pkg->id === $packageId) {
                return $pkg->toArray();
            }
        }

        // Fallback: fetch bundle details directly
        $bundle = $this->providerManager->provider()->bundles($packageId);

        return array_merge($bundle, ['id' => $packageId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pullSearch(string $uuid): ?array
    {
        $payload = Cache::get($this->searchCacheKey($uuid));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pullBooking(string $uuid): ?array
    {
        $payload = Cache::get($this->bookingCacheKey($uuid));

        return is_array($payload) ? $payload : null;
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
