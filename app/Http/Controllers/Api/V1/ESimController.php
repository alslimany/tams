<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Api\Controller;
use App\Models\Country;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use App\Services\ESim\ESimApiException;
use App\Services\ESim\ESimBookingService;
use App\Services\ESim\ESimCatalogueService;
use App\Services\ESim\ESimProviderManager;
use App\Services\ESim\ESimUsagePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ESimController extends Controller
{
    public function __construct(
        protected ESimBookingService $bookingService,
        protected ESimCatalogueService $catalogueService,
        protected ESimProviderManager $providerManager,
        protected ESimUsagePresenter $usagePresenter,
    ) {}

    /**
     * List countries available for eSIM purchase.
     */
    public function countries(): JsonResponse
    {
        $allCountries = Country::query()
            ->orderBy('name_en')
            ->get(['alpha2', 'name_en', 'name_ar', 'name_fr', 'esim_featured']);

        return $this->success([
            'countries' => $allCountries->map(fn (Country $country): array => [
                'alpha2' => strtoupper($country->alpha2),
                'name_en' => $country->name_en,
                'name_ar' => $country->name_ar,
                'name_fr' => $country->name_fr,
                'esim_featured' => (bool) $country->esim_featured,
            ])->values(),
            'featured_countries' => $allCountries
                ->filter(fn (Country $country): bool => (bool) $country->esim_featured)
                ->map(fn (Country $country): array => [
                    'alpha2' => strtoupper($country->alpha2),
                    'name_en' => $country->name_en,
                    'name_ar' => $country->name_ar,
                    'name_fr' => $country->name_fr,
                ])->values(),
        ]);
    }

    /**
     * Start an eSIM catalogue search for a destination country (ISO 3166-1 alpha-2).
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country' => ['required', 'string', 'size:2'],
        ]);

        $country = strtoupper((string) $validated['country']);
        $uuid = $this->bookingService->startSearch($country);

        return $this->success([
            'uuid' => $uuid,
            'country' => $country,
            'expires_in_minutes' => 60,
        ], 'eSIM search started.');
    }

    /**
     * Packages for a cached country search.
     */
    public function packages(string $uuid): JsonResponse
    {
        try {
            return $this->success([
                'packages' => $this->bookingService->packagesForSearch($uuid),
            ]);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 410);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error($exception->getMessage() ?: 'Unable to load eSIM packages right now.', 422);
        }
    }

    /**
     * Carrier networks for a cached country search.
     */
    public function networks(string $uuid): JsonResponse
    {
        return $this->success([
            'networks' => $this->bookingService->networksForSearch($uuid),
        ]);
    }

    /**
     * Destination airport bundles for flight-booking upsell (mobile-friendly).
     */
    public function airportPackages(string $iata): JsonResponse
    {
        try {
            $payload = $this->catalogueService->packagesForAirport($iata);

            if ($payload['airport'] === null) {
                return $this->notFound('Airport not found.');
            }

            return $this->success([
                'airport' => $payload['airport'],
                'packages' => $payload['packages'],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error($exception->getMessage() ?: 'Unable to load airport eSIM packages.', 422);
        }
    }

    /**
     * Select a package from a cached search session.
     */
    public function select(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search_uuid' => ['required', 'string'],
            'package_id' => ['required', 'string'],
        ]);

        try {
            $bookingUuid = $this->bookingService->selectPackage(
                (string) $validated['search_uuid'],
                (string) $validated['package_id'],
            );

            $booking = $this->bookingService->getBooking($bookingUuid);

            return $this->success([
                'booking_uuid' => $bookingUuid,
                'package' => $booking['package'] ?? null,
                'search' => $booking['search'] ?? null,
                'expires_in_minutes' => 60,
            ], 'eSIM package selected. Proceed to book with customer details.');
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 410);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error($exception->getMessage() ?: 'Unable to select eSIM package.', 422);
        }
    }

    /**
     * Purchase the selected eSIM and create an issued order.
     */
    public function book(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_uuid' => ['required', 'string'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.email' => ['required', 'email', 'max:255'],
        ]);

        $issuer = $request->user();

        if (! $issuer instanceof User) {
            return $this->error('Authentication is required to purchase eSIM.', 401);
        }

        try {
            $order = $this->bookingService->purchase(
                $issuer,
                (string) $validated['booking_uuid'],
                [
                    'name' => (string) $validated['customer']['name'],
                    'email' => (string) $validated['customer']['email'],
                ],
            );

            $item = $order->items->first();

            return $this->success([
                'order' => [
                    'id' => $order->id,
                    'number' => $order->number,
                    'status' => $order->status,
                    'grand_total' => (float) $order->grand_total,
                    'currency' => $order->currency,
                ],
                'esim' => [
                    'iccid' => $item?->ticket_number,
                    'activation_code' => data_get($item?->item_details, 'activation_code'),
                    'lpa_string' => data_get($item?->item_details, 'lpa_string'),
                    'qr_code_url' => data_get($item?->item_details, 'qr_code_url'),
                    'status' => $item?->status,
                    'provider_order_id' => data_get($item?->item_details, 'provider_order_id'),
                ],
                'package' => data_get($item?->item_details, 'package'),
            ], 'eSIM purchased successfully.', 201);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 410);
        } catch (InsufficientWalletBalanceException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                $exception instanceof ESimApiException ? $exception->getMessage() : 'Unable to complete eSIM purchase right now.',
                422,
            );
        }
    }

    /**
     * Latest utilisation / remaining quota for an issued eSIM item.
     */
    public function usage(Order $order, OrderItem $item): JsonResponse
    {
        abort_unless($item->order_id === $order->id, 404);
        abort_unless($item->type === 'esim' || $item->product_type === 'esim', 404);

        $usage = $this->usagePresenter->fromOrderItem($item);

        return $this->success([
            'order_id' => $order->id,
            'item_id' => $item->id,
            'iccid' => (string) ($item->ticket_number ?: data_get($item->item_details, 'iccid', '')),
            'usage' => $usage,
            'has_usage_data' => $usage !== null,
        ]);
    }

    /**
     * Catalogue packages available to top up this eSIM (same country).
     */
    public function topupPackages(Order $order, OrderItem $item): JsonResponse
    {
        abort_unless($item->order_id === $order->id, 404);

        try {
            return $this->success([
                'order_id' => $order->id,
                'item_id' => $item->id,
                'iccid' => (string) ($item->ticket_number ?: data_get($item->item_details, 'iccid', '')),
                'packages' => $this->bookingService->topupPackagesForItem($item),
            ]);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error($exception->getMessage() ?: 'Unable to load top-up packages.', 422);
        }
    }

    /**
     * Add extra quota to an existing eSIM (L2 processOrders with iccid).
     */
    public function topup(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        abort_unless($item->order_id === $order->id, 404);

        $validated = $request->validate([
            'package_id' => ['required', 'string'],
        ]);

        $issuer = $request->user();

        if (! $issuer instanceof User) {
            return $this->error('Authentication is required to top up eSIM.', 401);
        }

        try {
            $topupOrder = $this->bookingService->topup(
                $issuer,
                $item,
                (string) $validated['package_id'],
            );

            $topupItem = $topupOrder->items->first();

            return $this->success([
                'order' => [
                    'id' => $topupOrder->id,
                    'number' => $topupOrder->number,
                    'status' => $topupOrder->status,
                    'grand_total' => (float) $topupOrder->grand_total,
                    'currency' => $topupOrder->currency,
                ],
                'esim' => [
                    'iccid' => $topupItem?->ticket_number,
                    'status' => $topupItem?->status,
                    'provider_order_id' => data_get($topupItem?->item_details, 'provider_order_id'),
                    'transaction_type' => 'topup',
                    'parent_order_id' => $order->id,
                    'parent_item_id' => $item->id,
                ],
                'package' => data_get($topupItem?->item_details, 'package'),
            ], 'eSIM topped up successfully.', 201);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (InsufficientWalletBalanceException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                $exception instanceof ESimApiException ? $exception->getMessage() : 'Unable to complete eSIM top-up right now.',
                422,
            );
        }
    }

    /**
     * Refund an issued eSIM order item.
     */
    public function refund(Order $order, OrderItem $item): JsonResponse
    {
        abort_unless($item->order_id === $order->id, 404);
        abort_unless($item->type === 'esim' || $item->product_type === 'esim', 404);
        abort_unless($item->status === 'issued', 422, 'Only issued eSIMs can be refunded.');

        $iccid = (string) ($item->ticket_number ?: data_get($item->item_details, 'iccid', ''));

        if ($iccid === '') {
            return $this->error('No ICCID found for this eSIM order item.', 422);
        }

        try {
            $this->providerManager->provider()->deleteEsim($iccid);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                $exception instanceof ESimApiException ? $exception->getMessage() : 'Unable to delete eSIM from provider.',
                422,
            );
        }

        $walletTransactionUuid = data_get($item->item_details, 'provider_wallet_transaction_id');
        $esimProvider = $this->providerManager->activeProvider();

        if ($walletTransactionUuid && $esimProvider instanceof \App\Models\Tenant\TenantEsimProvider) {
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

        return $this->success([
            'order_id' => $order->id,
            'item_id' => $item->id,
            'iccid' => $iccid,
            'status' => 'refunded',
        ], 'eSIM refunded successfully.');
    }
}
