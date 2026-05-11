<?php

namespace App\Actions\Finance;

use App\Models\Tenant\Order;
use App\Models\Tenant\TenantHotelProvider;
use App\Models\User;
use App\Services\Orders\OrderNumberGenerator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class CreateOrderFromHotelBooking
{
    public function __construct(
        protected OrderNumberGenerator $orderNumberGenerator,
    ) {}

    /**
     * @param  array<string, mixed>  $booking
     * @param  array<string, mixed>  $selectedOffer
     * @param  array<string, mixed>  $customer
     * @param  array<int, array<string, mixed>>  $rooms
     * @param  array<string, mixed>  $search
     */
    public function create(
        int $userId,
        array $booking,
        array $selectedOffer,
        array $customer,
        array $rooms,
        array $search,
        TenantHotelProvider $provider,
        ?array $providerSource = null,
    ): Order {
        $issuer = User::query()->find($userId);

        if (! $issuer instanceof User) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        $totalAmount = round((float) ($selectedOffer['price'] ?? 0), 2);
        $providerCost = round((float) ($selectedOffer['provider_price'] ?? $totalAmount), 2);
        $currency = strtoupper((string) ($selectedOffer['currency'] ?? 'USD'));
        $commissionPercent = $provider->markupForProductType('hotel');
        $commissionAmount = round($totalAmount - $providerCost, 2);
        $bookingResponse = is_array($booking['response'] ?? null) ? $booking['response'] : [];
        $bookingId = (string) ($bookingResponse['bookingId'] ?? '');
        $bookingSource = $bookingResponse['bookingSource'] ?? ($selectedOffer['source'] ?? null);
        $confirmed = (bool) ($bookingResponse['confirmed'] ?? false);
        $providerBooking = is_array($bookingResponse['booking'] ?? null) ? $bookingResponse['booking'] : [];
        $providerHotel = is_array($providerBooking['hotel'] ?? null) ? $providerBooking['hotel'] : [];
        $providerRooms = array_values(array_filter(
            is_array($providerBooking['rooms'] ?? null) ? $providerBooking['rooms'] : [],
            fn (mixed $room): bool => is_array($room),
        ));
        $providerTotal = (float) ($bookingResponse['totalPurchase'] ?? $totalAmount);
        $bookingCurrency = strtoupper((string) ($bookingResponse['currency'] ?? $currency));
        $providerSourceDetails = $this->providerSourceItemDetails($providerSource ?? []);
        $financialSource = (string) data_get($providerSource, 'source_type') === 'agency_network'
            ? 'agency_network_supply'
            : 'own_provider_wallet';

        return DB::transaction(function () use ($issuer, $totalAmount, $providerCost, $currency, $commissionPercent, $commissionAmount, $booking, $bookingResponse, $providerBooking, $providerHotel, $providerRooms, $providerTotal, $bookingCurrency, $bookingId, $bookingSource, $confirmed, $selectedOffer, $customer, $rooms, $search, $provider, $providerSourceDetails, $financialSource): Order {
            $order = Order::query()->create([
                'owner_type' => $issuer::class,
                'owner_id' => $issuer->id,
                'number' => $this->generateUniqueOrderNumber(),
                'status' => $confirmed ? 'issued' : 'pending',
                'issued_at' => now(),
                'subtotal' => $totalAmount,
                'tax_total' => 0,
                'grand_total' => $totalAmount,
                'amount_paid' => $totalAmount,
                'currency' => $currency,
                'payment_method' => 'provider_wallet',
                'payment_reference' => $bookingId,
                'contact' => $customer,
            ]);

            $order->items()->create([
                'type' => 'hotel',
                'product_type' => 'hotel',
                'product_subtype' => 'hotel',
                'provider' => $provider->provider_type,
                'provider_reference' => $bookingId,
                'ticket_number' => (string) ($bookingResponse['bookingRef'] ?? ''),
                'item_details' => array_merge($providerSourceDetails, [
                    'financial_source' => $financialSource,
                    'booking_id' => $bookingId,
                    'booking_source' => $bookingSource,
                    'booking_ref' => $bookingResponse['bookingRef'] ?? null,
                    'confirmed' => $confirmed,
                    'returned_price' => (bool) ($bookingResponse['returnedPrice'] ?? false),
                    'total_purchase' => round($providerTotal, 2),
                    'provider_cost' => $providerCost,
                    'selling_price' => $totalAmount,
                    'markup_percent' => $commissionPercent,
                    'markup_amount' => $commissionAmount,
                    'provider_currency' => $bookingCurrency,
                    'comments' => $bookingResponse['comments'] ?? null,
                    'provider_booking' => [
                        'hotel' => $providerHotel,
                        'from' => $providerBooking['from'] ?? null,
                        'to' => $providerBooking['to'] ?? null,
                        'deadline' => $providerBooking['deadline'] ?? null,
                        'rooms' => $providerRooms,
                    ],
                    'search' => $search,
                    'selected_offer' => $selectedOffer,
                    'customer' => $customer,
                    'rooms' => $rooms,
                    'provider_response' => $booking,
                ]),
                'product_details' => [
                    'provider' => $provider->name,
                    'product_subtype' => 'hotel',
                    'hotel' => array_filter([
                        ...($selectedOffer['hotel'] ?? []),
                        'hotel_id' => $providerHotel['hotelId'] ?? data_get($selectedOffer, 'hotel.hotel_id'),
                        'hotel_name' => $providerHotel['hotelName'] ?? data_get($selectedOffer, 'hotel.name'),
                        'name' => $providerHotel['hotelName'] ?? data_get($selectedOffer, 'hotel.name'),
                        'country_name' => $providerHotel['countryName'] ?? null,
                        'city_name' => $providerHotel['cityName'] ?? null,
                        'rating' => $providerHotel['rating'] ?? null,
                        'rating_id' => $providerHotel['ratingId'] ?? null,
                        'thumb_image' => $providerHotel['thumbImage'] ?? null,
                    ], fn (mixed $value): bool => $value !== null && $value !== ''),
                    'room' => $selectedOffer,
                    'customer' => $customer,
                    'rooms' => $providerRooms ?: $rooms,
                    'stay' => [
                        'from' => $providerBooking['from'] ?? ($search['check_in'] ?? null),
                        'to' => $providerBooking['to'] ?? ($search['check_out'] ?? null),
                        'deadline' => $providerBooking['deadline'] ?? null,
                    ],
                    'comments' => $bookingResponse['comments'] ?? null,
                ],
                'price' => $totalAmount,
                'net_fare' => $providerCost,
                'taxes' => [],
                'total_tax' => 0,
                'total' => $totalAmount,
                'total_amount' => $totalAmount,
                'currency' => $currency,
                'exchange_rate' => 1,
                'status' => $confirmed ? 'issued' : 'pending',
                'transaction_type' => 'issue',
                'commission_percent' => $commissionPercent,
                'commission_amount' => $commissionAmount,
                'net_after_commission' => $providerCost,
                'agent_commission' => $commissionAmount,
                'net_commission' => $commissionAmount,
                'paid' => $totalAmount,
                'remaining' => 0,
            ]);

            return $order->fresh('items');
        });
    }

    protected function generateUniqueOrderNumber(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $number = $this->orderNumberGenerator->generate();

            if (! Order::query()->where('number', $number)->exists()) {
                return $number;
            }
        }

        return $this->orderNumberGenerator->generate();
    }

    /**
     * @param  array<string, mixed>  $providerSource
     * @return array<string, mixed>
     */
    protected function providerSourceItemDetails(array $providerSource): array
    {
        if ($providerSource === []) {
            return [];
        }

        $details = [
            'provider_source_type' => data_get($providerSource, 'source_type'),
        ];

        foreach (['provider_selector', 'source_agency_tenant_id', 'merchant_tenant_id', 'network_membership_id', 'provider_allocation_id', 'source_provider_model', 'source_provider_id'] as $metadataKey) {
            $metadataValue = data_get($providerSource, $metadataKey);

            if ($metadataValue !== null) {
                $details[$metadataKey] = $metadataValue;
            }
        }

        return $details;
    }
}
