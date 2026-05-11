<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\CreateOrderFromInsuranceBooking;
use App\Actions\Finance\InitializeTenantLedger;
use App\Actions\Finance\PostToLedger;
use App\Actions\Finance\ProcessInsuranceProviderWalletTransactions;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Insurance\OrangeIssueRequest;
use App\Http\Requests\Tenant\Insurance\OrangePriceRequest;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\User;
use App\Services\AgencyNetwork\MerchantAgencyWalletManager;
use App\Services\Insurance\InsuranceProviderManager;
use App\Services\Insurance\Providers\AlBarakaProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OrangeInsuranceController extends Controller
{
    public function __construct(
        protected AlBarakaProvider $provider,
        protected InsuranceProviderManager $providerManager,
        protected CreateOrderFromInsuranceBooking $createOrderFromInsuranceBooking,
        protected ProcessInsuranceProviderWalletTransactions $insuranceProviderWalletTransactions,
        protected MerchantAgencyWalletManager $merchantAgencyWalletManager,
    ) {}

    public function references(): JsonResponse
    {
        try {
            return response()->json([
                'countries' => $this->cachedReference('countries', fn (): array => $this->provider->lookup('orange', 'countries')),
                'documentTypes' => $this->cachedReference('document_types', fn (): array => $this->provider->lookup('orange', 'document_types')),
                'cars' => $this->cachedReference('cars', fn (): array => $this->provider->lookup('orange', 'cars')),
                'vehicleNationalities' => $this->cachedReference('vehicle_nationalities', fn (): array => $this->provider->lookup('orange', 'vehicle_nationalities')),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to fetch orange insurance references at the moment.',
            ], 422);
        }
    }

    public function price(OrangePriceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $dateFrom = CarbonImmutable::parse((string) $validated['policy_date_from']);
            $dateTo = CarbonImmutable::parse((string) $validated['policy_date_to']);
            $numberOfDays = max(1, (int) ceil($dateFrom->floatDiffInDays($dateTo)));

            $price = $this->provider->calculateOrangePrice([
                'DocumentTypeID' => (int) $validated['document_type_id'],
                'PolicyDay' => $numberOfDays,
                'Countries' => (int) $validated['country'],
            ]);

            $quoteToken = (string) Str::uuid();
            $quote = [
                'quote_token' => $quoteToken,
                'provider_source' => $this->providerManager->activeProviderSource(),
                'country' => (int) $validated['country'],
                'document_type_id' => (int) $validated['document_type_id'],
                'policy_date_from' => $dateFrom->toDateString(),
                'policy_date_to' => $dateTo->toDateString(),
                'number_of_days' => $numberOfDays,
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
                'message' => 'Unable to calculate orange insurance price. Please verify your inputs and try again.',
            ], 422);
        }
    }

    public function beneficiaryPage(string $quoteToken): Response|RedirectResponse
    {
        $quote = $this->pullQuote($quoteToken, keepInSession: true);

        if ($quote === null) {
            return redirect()
                ->route('insurance.search')
                ->with('error', 'The selected quote has expired. Please get a new price.');
        }

        return Inertia::render('Tenant/Insurance/OrangeBeneficiary', [
            'quoteToken' => $quoteToken,
            'quote' => $quote,
            'cars' => $this->cachedReference('cars', fn (): array => $this->provider->lookup('orange', 'cars')),
            'vehicleNationalities' => $this->cachedReference('vehicle_nationalities', fn (): array => $this->provider->lookup('orange', 'vehicle_nationalities')),
            'countries' => $this->cachedReference('countries', fn (): array => $this->provider->lookup('orange', 'countries')),
            'documentTypes' => $this->cachedReference('document_types', fn (): array => $this->provider->lookup('orange', 'document_types')),
        ]);
    }

    public function issue(OrangeIssueRequest $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();
        $quote = $this->pullQuote((string) $validated['quote_token'], keepInSession: true);

        if ($quote === null) {
            return back()->with('error', 'The selected quote has expired. Please calculate the price again.');
        }

        $issuer = $request->user();

        if (! $issuer instanceof User) {
            return back()->with('error', 'Authentication is required to issue orange insurance.');
        }

        $insuranceProvider = $this->providerManager->activeProvider();
        $providerSource = is_array($quote['provider_source'] ?? null) ? $quote['provider_source'] : [];

        if ($insuranceProvider instanceof TenantInsuranceProvider) {
            try {
                if ((string) data_get($providerSource, 'source_type') === 'agency_network') {
                    $this->merchantAgencyWalletManager->assertCanWithdrawForSource(
                        $issuer,
                        $providerSource,
                        strtoupper((string) ($quote['currency'] ?? 'LYD')),
                        round((float) ($quote['total_premium'] ?? 0), 3),
                    );
                }

                $this->insuranceProviderWalletTransactions->assertCanWithdrawForSource(
                    $providerSource,
                    $insuranceProvider,
                    strtoupper((string) ($quote['currency'] ?? 'LYD')),
                    round((float) ($quote['total_premium'] ?? 0), 3),
                );
            } catch (InsufficientWalletBalanceException $exception) {
                return back()->with('error', $exception->getMessage());
            }
        }

        try {
            $policyDateFrom = CarbonImmutable::parse((string) $quote['policy_date_from'])->startOfDay();
            $manufactureYear = CarbonImmutable::create((int) $validated['manufacture_year'], 1, 1)->startOfDay();

            $policy = $this->provider->createOrangePolicy([
                'Check' => null,
                'Name' => (string) $validated['name'],
                'Address' => (string) $validated['address'],
                'Phone' => (string) ($validated['phone'] ?? ''),
                'ChassisNumber' => (string) $validated['chassis_number'],
                'MetalPlateNo' => (string) $validated['metal_plate_number'],
                'ManufactureYear' => $manufactureYear->toISOString(),
                'CarID' => (int) $validated['car_id'],
                'Nationality' => (int) $validated['nationality'],
                'Country' => (int) $quote['country'],
                'PolicyDateFrom' => $policyDateFrom->toISOString(),
                'NumberOfDays' => (int) $quote['number_of_days'],
                'DocumentTypeID' => (int) $quote['document_type_id'],
                'IsPolicyPaid' => true,
                'VoucherCode' => null,
            ]);

            $fullPolicy = [];
            if ((string) $policy['report_reference'] !== '') {
                $fullPolicy = $this->provider->getOrangePolicyByDates(
                    $policyDateFrom->toISOString(),
                    $policyDateFrom->addDays((int) $quote['number_of_days'])->endOfDay()->toISOString(),
                    (string) $policy['report_reference'],
                );
            }

            $policyDetails = [
                'policy_id' => (int) $policy['policy_id'],
                'policy_number' => (string) $policy['card_number'],
                'report_reference' => (string) $policy['report_reference'],
                'total_amount' => (float) ($quote['total_premium'] ?? $policy['total_premium']),
                'net_amount' => (float) ($quote['net_premium'] ?? $policy['net_premium']),
                'tax_amount' => (float) ($quote['tax_amount'] ?? $policy['tax_amount']),
                'currency' => strtoupper((string) ($quote['currency'] ?? $policy['currency'] ?? 'LYD')),
                'raw' => [
                    'issue' => $policy['raw'],
                    'full_policy' => $fullPolicy,
                ],
            ];

            $beneficiaryData = [
                'name' => (string) $validated['name'],
                'address' => (string) $validated['address'],
                'phone' => (string) ($validated['phone'] ?? ''),
                'chassis_number' => (string) $validated['chassis_number'],
                'metal_plate_number' => (string) $validated['metal_plate_number'],
                'manufacture_year' => (int) $validated['manufacture_year'],
                'car_id' => (int) $validated['car_id'],
                'nationality' => (int) $validated['nationality'],
                'country' => (int) $quote['country'],
                'document_type_id' => (int) $quote['document_type_id'],
                'policy_date_from' => (string) $quote['policy_date_from'],
                'policy_date_to' => (string) $quote['policy_date_to'],
                'number_of_days' => (int) $quote['number_of_days'],
            ];

            $order = $this->createOrderFromInsuranceBooking->createFromPolicyDetails(
                userId: $issuer->id,
                productSubtype: 'orange',
                policyDetails: $policyDetails,
                beneficiaryData: $beneficiaryData,
                requestPayload: [
                    'quote' => $quote,
                    'provider_source' => $quote['provider_source'] ?? null,
                    'issue_request' => $validated,
                    'full_policy' => $fullPolicy,
                ],
                insuranceProvider: $insuranceProvider,
                processAgencyWallet: false,
            );

            if ((string) data_get($providerSource, 'source_type') === 'agency_network') {
                $order->loadMissing('items');

                foreach ($order->items as $item) {
                    $this->merchantAgencyWalletManager->withdrawForOrderItem($order, $item, $issuer);
                }
            }

            if ($insuranceProvider instanceof TenantInsuranceProvider) {
                $this->insuranceProviderWalletTransactions->execute($order, $insuranceProvider);

                try {
                    app(InitializeTenantLedger::class)->execute((string) $order->currency);
                    app(PostToLedger::class)->execute($order, includeOwnCredentials: false);
                } catch (Throwable $exception) {
                    report($exception);

                    Log::warning('Orange insurance ledger posting failed after successful issuance.', [
                        'order_id' => (string) $order->id,
                        'order_number' => (string) $order->number,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $this->forgetQuote((string) $validated['quote_token']);

            return redirect()
                ->route('orders.show', $order)
                ->with('success', 'Orange insurance policy issued successfully.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Unable to issue orange insurance at the moment. Please try again.');
        }
    }

    /**
     * @param  callable():array<int, array<string, mixed>>  $resolver
     * @return array<int, array<string, mixed>>
     */
    protected function cachedReference(string $key, callable $resolver): array
    {
        return Cache::remember('insurance:orange:reference:'.(tenant()?->id ?? 'central').':'.$key, now()->addDay(), $resolver);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pullQuote(string $quoteToken, bool $keepInSession): ?array
    {
        $quotes = session()->get('insurance.orange_quotes', []);

        if (! is_array($quotes) || ! isset($quotes[$quoteToken]) || ! is_array($quotes[$quoteToken])) {
            return null;
        }

        $quote = $quotes[$quoteToken];

        if (! $keepInSession) {
            unset($quotes[$quoteToken]);
            session()->put('insurance.orange_quotes', $quotes);
        }

        return $quote;
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    protected function storeQuote(string $quoteToken, array $quote): void
    {
        $quotes = session()->get('insurance.orange_quotes', []);

        if (! is_array($quotes)) {
            $quotes = [];
        }

        $quotes[$quoteToken] = $quote;
        session()->put('insurance.orange_quotes', $quotes);
    }

    protected function forgetQuote(string $quoteToken): void
    {
        $quotes = session()->get('insurance.orange_quotes', []);

        if (! is_array($quotes)) {
            return;
        }

        unset($quotes[$quoteToken]);
        session()->put('insurance.orange_quotes', $quotes);
    }
}
