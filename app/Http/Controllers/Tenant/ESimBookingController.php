<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ESim\ESimBookRequest;
use App\Http\Requests\Tenant\ESim\ESimSearchRequest;
use App\Models\Country;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantEsimProvider;
use App\Models\User;
use App\Services\ESim\ESimApiException;
use App\Services\ESim\ESimBookingService;
use App\Services\ESim\ESimCatalogueService;
use App\Services\ESim\ESimProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class ESimBookingController extends Controller
{
    public function __construct(
        protected ESimProviderManager $providerManager,
        protected ESimBookingService $bookingService,
        protected ESimCatalogueService $catalogueService,
    ) {}

    public function index(): Response
    {
        $allCountries = Country::orderBy('name_en')
            ->get(['alpha2', 'name_en', 'name_ar', 'name_fr', 'esim_featured']);

        $countries = $allCountries
            ->map(fn (Country $c): array => [
                'alpha2' => $c->alpha2,
                'name_en' => $c->name_en,
                'name_ar' => $c->name_ar,
                'name_fr' => $c->name_fr,
            ])
            ->values();

        $featuredCountries = $allCountries
            ->filter(fn (Country $c): bool => $c->esim_featured)
            ->map(fn (Country $c): array => [
                'alpha2' => $c->alpha2,
                'name_en' => $c->name_en,
                'name_ar' => $c->name_ar,
                'name_fr' => $c->name_fr,
            ])
            ->values();

        return Inertia::render('Tenant/ESim/Search', [
            'countries' => $countries,
            'featuredCountries' => $featuredCountries,
        ]);
    }

    public function search(ESimSearchRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $uuid = $this->bookingService->startSearch((string) $validated['country']);

        return redirect()->route('esim.results', $uuid);
    }

    public function results(string $uuid): Response|RedirectResponse
    {
        $search = $this->bookingService->getSearch($uuid);

        if ($search === null) {
            return redirect()->route('esim.index')->with('error', 'eSIM search expired. Please search again.');
        }

        $country = Country::where('alpha2', $search['country'] ?? '')->first(['name_en', 'name_ar', 'name_fr']);

        return Inertia::render('Tenant/ESim/Results', [
            'searchUuid' => $uuid,
            'search' => $search,
            'countryNames' => $country ? [
                'en' => $country->name_en,
                'ar' => $country->name_ar,
                'fr' => $country->name_fr,
            ] : null,
        ]);
    }

    public function packages(string $uuid): JsonResponse
    {
        try {
            return response()->json([
                'packages' => $this->bookingService->packagesForSearch($uuid),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage() ?: 'Unable to load eSIM packages right now.'], 422);
        }
    }

    public function networks(string $uuid): JsonResponse
    {
        return response()->json([
            'networks' => $this->bookingService->networksForSearch($uuid),
        ]);
    }

    public function airportPackages(string $iata): JsonResponse
    {
        try {
            $payload = $this->catalogueService->packagesForAirport($iata);

            return response()->json([
                'packages' => $payload['packages'],
                'country_iso' => $payload['airport']['country_iso'] ?? null,
                'airport' => $payload['airport'],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'packages' => [],
                'country_iso' => null,
                'airport' => null,
            ]);
        }
    }

    public function select(string $uuid): RedirectResponse
    {
        $packageId = (string) request()->input('package_id', '');

        try {
            $bookingUuid = $this->bookingService->selectPackage($uuid, $packageId);
        } catch (RuntimeException $exception) {
            return redirect()->route('esim.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage() ?: 'Unable to load package details.');
        }

        return redirect()->route('esim.checkout', $bookingUuid);
    }

    public function checkout(string $uuid): Response|RedirectResponse
    {
        $booking = $this->bookingService->getBooking($uuid);

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
        $issuer = $request->user();

        if (! $issuer instanceof User) {
            return back()->with('error', 'Authentication is required to purchase eSIM.');
        }

        try {
            $order = $this->bookingService->purchase(
                $issuer,
                (string) $validated['booking_uuid'],
                [
                    'name' => (string) ($validated['customer']['name'] ?? ''),
                    'email' => (string) ($validated['customer']['email'] ?? ''),
                ],
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception instanceof ESimApiException ? $exception->getMessage() : 'Unable to complete eSIM purchase right now.');
        }

        return redirect()->route('orders.show', $order)->with('success', 'eSIM purchased successfully.');
    }

    public function refund(Order $order, OrderItem $item): RedirectResponse
    {
        abort_unless($item->order_id === $order->id, 404);
        abort_unless($item->type === 'esim' || $item->product_type === 'esim', 404);
        abort_unless($item->status === 'issued', 404, 'Only issued eSIMs can be refunded.');

        $iccid = (string) ($item->ticket_number ?: data_get($item->item_details, 'iccid', ''));

        if ($iccid === '') {
            return back()->with('error', 'No ICCID found for this eSIM order item.');
        }

        try {
            $this->providerManager->provider()->deleteEsim($iccid);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception instanceof ESimApiException ? $exception->getMessage() : 'Unable to delete eSIM from provider. Please try again.');
        }

        $walletTransactionUuid = data_get($item->item_details, 'provider_wallet_transaction_id');
        $esimProvider = $this->providerManager->activeProvider();

        if ($walletTransactionUuid && $esimProvider instanceof TenantEsimProvider) {
            $wallet = $esimProvider->getOrCreateCurrencyWallet(strtoupper((string) ($item->currency ?? 'USD')));
            $refundAmount = round((float) data_get($item->item_details, 'provider_wallet_withdrawal_amount', (float) ($item->total_amount ?? $item->total ?? 0)), 2);

            if ($refundAmount > 0) {
                $wallet->depositFloat($refundAmount, [
                    'type' => 'esim_refund',
                    'description' => 'eSIM refund for ICCID '.$iccid.'.',
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'original_transaction_uuid' => $walletTransactionUuid,
                    'product_type' => 'esim',
                ]);
            }
        }

        $item->update([
            'status' => 'refunded',
            'item_details' => array_merge((array) $item->item_details, [
                'refunded_at' => now()->toISOString(),
                'refund_iccid' => $iccid,
            ]),
        ]);

        return back()->with('success', 'eSIM refunded successfully.');
    }
}
