<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantHotelProvider;
use App\Models\User;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'hotel-flow-'.Str::random(4),
        'company_name' => 'Hotel Flow Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    TenantHotelProvider::query()->create([
        'provider_type' => '3t',
        'name' => '3T Hotels',
        'credentials' => [
            'base_url' => 'https://btob.3t.tn',
            'api_key' => 'test-api-key',
            'login' => 'test-login',
            'password' => 'test-password',
        ],
        'is_active' => true,
        'commission_hotel' => 10,
        'currency' => 'LYD',
    ]);

    $state['tenant'] = $tenant;
    $state['admin'] = $admin;
    $state['baseUrl'] = 'http://'.$tenant->domains->first()->domain;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('hotel full flow books order, deducts provider wallet, and cancels with refund', function () {
    global $state;

    Http::fake([
        'https://btob.3t.tn/hotels-api?method=availability' => Http::response([
            'method' => 'availability',
            'response' => [[
                'source' => 129,
                'hotel' => [
                    'hotelId' => 45,
                    'hotelUid' => 'hotel_45',
                    'name' => 'Hotel Paris',
                    'rating' => 4,
                ],
                'rooms' => [[
                    [
                        'rateKey' => 'RATE-123',
                        'roomName' => 'Double Room',
                        'boardName' => 'BED AND BREAKFAST',
                        'price' => 250.50,
                        'currency' => 'LYD',
                        'available' => true,
                        'cancellationPolicies' => [['amount' => 0]],
                    ],
                ]],
            ]],
            'searchCode' => 'SEARCH-123',
            'pages' => 1,
            'hotelsCount' => 1,
            'error' => false,
        ], 200),
        'https://btob.3t.tn/hotels-api?method=checkRate' => Http::response([
            'method' => 'checkRate',
            'tokenForBook' => 'TOKEN-123',
            'response' => [[
                'searchCode' => 'SEARCH-123',
                'rooms' => [[['rateKey' => 'RATE-123', 'price' => 250.50, 'currency' => 'LYD']]],
            ]],
            'error' => false,
        ], 200),
        'https://btob.3t.tn/hotels-api?method=book' => Http::response([
            'method' => 'book',
            'response' => [
                'bookingId' => '352',
                'confirmed' => true,
                'booking' => [
                    'hotel' => [
                        'hotelId' => 45,
                        'hotelName' => 'Hotel Paris',
                        'ratingId' => 4,
                        'rating' => '4 étoiles',
                        'countryName' => 'France',
                        'cityName' => 'Paris',
                        'thumbImage' => 'https://example.test/hotel.jpg',
                    ],
                    'from' => now()->addMonth()->toDateString(),
                    'to' => now()->addMonth()->addDays(3)->toDateString(),
                    'rooms' => [[
                        'roomIndex' => 1,
                        'associationId' => 123,
                        'rateKey' => 'RATE-123',
                        'boardName' => 'BED AND BREAKFAST',
                        'price' => 250.50,
                        'currency' => 'LYD',
                        'name' => 'Double Room',
                        'cancellationPolicies' => [['from' => now()->addMonth()->subDay()->toDateString(), 'amount' => 0]],
                        'noShow' => 66.50,
                    ]],
                ],
                'bookingRef' => '',
                'bookingSource' => 129,
                'comments' => '<div>Tourist tax payable at hotel.</div>',
                'returnedPrice' => true,
                'totalPurchase' => 250.50,
                'currency' => 'LYD',
            ],
            'error' => false,
        ], 200),
        'https://btob.3t.tn/hotels-api?method=cancel' => Http::response([
            'method' => 'cancel',
            'response' => [
                'bookingId' => '352',
                'canceled' => true,
                'cancellationFee' => 25.50,
            ],
            'error' => false,
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $provider = TenantHotelProvider::query()->where('provider_type', '3t')->firstOrFail();
    $wallet = $provider->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(1000, ['type' => 'test_provider_fund']);

    $searchResponse = $this->post($state['baseUrl'].route('hotels.search', [], false), [
        'city' => 'Paris, France',
        'check_in' => now()->addMonth()->toDateString(),
        'check_out' => now()->addMonth()->addDays(3)->toDateString(),
        'rooms' => [
            ['adult' => 2, 'children' => []],
        ],
    ]);

    $searchResponse->assertRedirect();
    $searchUuid = Str::afterLast((string) $searchResponse->headers->get('Location'), '/');

    $availabilityResponse = $this->getJson($state['baseUrl'].route('hotels.availability', ['uuid' => $searchUuid], false))
        ->assertSuccessful()
        ->assertJsonPath('hotels.0.name', 'Hotel Paris')
        ->assertJsonPath('hotels.0.rooms.0.rate_key', 'RATE-123')
        ->assertJsonPath('hotels.0.rooms.0.provider_price', 250.50)
        ->assertJsonPath('hotels.0.rooms.0.price', 275.55);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://btob.3t.tn/hotels-api?method=availability'
            && $request['city'] === 'Paris'
            && $request['hotelName'] === ''
            && $request['boards'] === []
            && $request['rating'] === []
            && $request['hotelId'] === []
            && $request['language'] === 'fr_FR'
            && $request['filtreSearch'] === []
            && ! isset($request['cityId'])
            && ! isset($request['page']);
    });

    $room = $availabilityResponse->json('hotels.0.rooms.0');

    $selectResponse = $this->post($state['baseUrl'].route('hotels.select', [], false), [
        'search_uuid' => $searchUuid,
        'hotel_id' => '45',
        'hotel_uid' => 'hotel_45',
        'hotel_name' => 'Hotel Paris',
        'source' => 129,
        'rate_key' => 'RATE-123',
        'room_name' => 'Double Room',
        'board_name' => 'BED AND BREAKFAST',
        'price' => $room['price'],
        'currency' => 'LYD',
        'available' => true,
        'cancellation_policies' => [['amount' => 0]],
        'raw' => $room,
    ]);

    $selectResponse->assertRedirect();
    $bookingUuid = Str::afterLast((string) $selectResponse->headers->get('Location'), '/');

    $bookResponse = $this->post($state['baseUrl'].route('hotels.book', [], false), [
        'booking_uuid' => $bookingUuid,
        'recommandations' => 'Late arrival',
        'customer' => [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'mobile' => '44300400',
            'country' => 'Tunisia',
            'city' => 'Sfax',
        ],
        'rooms' => [[
            'rate_key' => 'RATE-123',
            'paxes' => [
                ['civility' => 'Mr', 'first_name' => 'John', 'last_name' => 'Doe'],
                ['civility' => 'Mme', 'first_name' => 'Jane', 'last_name' => 'Doe'],
            ],
        ]],
    ]);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://btob.3t.tn/hotels-api?method=book'
            && $request['tokenForBook'] === 'TOKEN-123'
            && $request['rooms'][0]['ratekey'] === 'RATE-123';
    });

    $order = Order::query()->latest('created_at')->firstOrFail();
    $bookResponse->assertRedirect(route('orders.show', $order, false));

    $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

    expect((string) $item->product_type)->toBe('hotel')
        ->and((string) $item->provider_reference)->toBe('352')
        ->and((float) $item->commission_amount)->toBe(25.05)
        ->and((float) $item->total_amount)->toBe(275.55)
        ->and((float) $item->net_fare)->toBe(250.50)
        ->and((float) data_get($item->item_details, 'provider_cost'))->toBe(250.50)
        ->and((float) data_get($item->item_details, 'selling_price'))->toBe(275.55)
        ->and((float) data_get($item->item_details, 'markup_amount'))->toBe(25.05)
        ->and((float) data_get($item->item_details, 'markup_percent'))->toBe(10.0)
        ->and((bool) data_get($item->item_details, 'confirmed'))->toBeTrue()
        ->and(data_get($item->product_details, 'hotel.name'))->toBe('Hotel Paris')
        ->and(data_get($item->product_details, 'hotel.city_name'))->toBe('Paris')
        ->and(data_get($item->product_details, 'rooms.0.name'))->toBe('Double Room')
        ->and(data_get($item->item_details, 'comments'))->toContain('Tourist tax')
        ->and($item->wallet_transaction_id)->not->toBeNull();

    $wallet->refresh();
    expect(round((float) $wallet->balanceFloat, 2))->toBe(749.50);

    expect(WalletTransaction::query()
        ->where('uuid', (string) $item->wallet_transaction_id)
        ->where('wallet_id', $wallet->id)
        ->exists())->toBeTrue();

    $cancelResponse = $this->post($state['baseUrl'].route('hotels.order-items.cancel', ['order' => $order, 'item' => $item], false));

    $cancelResponse->assertRedirect();
    $item->refresh();
    $wallet->refresh();

    expect((string) $item->status)->toBe('cancelled')
        ->and((float) data_get($item->item_details, 'cancellation.cancellation_fee'))->toBe(25.50)
        ->and((float) data_get($item->item_details, 'cancellation.refund_amount'))->toBe(225.0)
        ->and(round((float) $wallet->balanceFloat, 2))->toBe(974.50);
});

test('hotel autocomplete forwards query to provider and normalizes configured base url', function () {
    global $state;

    TenantHotelProvider::query()->where('provider_type', '3t')->update([
        'credentials' => [
            'base_url' => 'https://babaldiwan.com.ly/hotels-api',
            'api_key' => 'test-api-key',
            'login' => 'test-login',
            'password' => 'test-password',
        ],
    ]);

    Http::fake([
        'https://babaldiwan.com.ly/hotels-api?method=autocomplete' => Http::response([
            'method' => 'autocomplete',
            'response' => [[
                'label' => 'Djerba',
                'country' => 'Tunisia',
                'category' => 'VILLE',
            ]],
            'error' => false,
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $this->getJson($state['baseUrl'].route('hotels.autocomplete', ['q' => 'Djerb'], false))
        ->assertSuccessful()
        ->assertJsonPath('destinations.0.label', 'Djerba')
        ->assertJsonMissingPath('destinations.0.id')
        ->assertJsonMissingPath('destinations.0.city_id');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://babaldiwan.com.ly/hotels-api?method=autocomplete'
            && $request['termSearch'] === 'Djerb';
    });
});

test('hotel autocomplete strips destination ids and does not call content endpoints', function () {
    global $state;

    TenantHotelProvider::query()->where('provider_type', '3t')->update([
        'credentials' => [
            'base_url' => 'https://babaldiwan.com.ly',
            'api_key' => 'test-api-key',
            'login' => 'test-login',
            'password' => 'test-password',
        ],
    ]);

    Http::fake([
        'https://babaldiwan.com.ly/hotels-api?method=autocomplete' => Http::response([
            'method' => 'autocomplete',
            'response' => [[
                'label' => 'Djerba, tunisie',
                'category' => 'VILLE',
            ]],
            'error' => false,
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $this->getJson($state['baseUrl'].route('hotels.autocomplete', ['q' => 'Djer'], false))
        ->assertSuccessful()
        ->assertJsonMissingPath('destinations.0.id')
        ->assertJsonMissingPath('destinations.0.city_id')
        ->assertJsonPath('destinations.0.label', 'Djerba, tunisie');

    Http::assertSentCount(1);
});

test('hotel booking fails early when provider wallet is insufficient', function () {
    global $state;

    Http::fake([
        'https://btob.3t.tn/hotels-api?method=book' => Http::response([
            'method' => 'book',
            'response' => ['bookingId' => '352'],
            'error' => false,
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $bookingUuid = (string) Str::uuid();
    Cache::put('hotel_booking_'.$bookingUuid, [
        'search' => [
            'city' => 'Paris',
            'city_id' => 3349,
            'check_in' => now()->addMonth()->toDateString(),
            'check_out' => now()->addMonth()->addDays(3)->toDateString(),
            'rooms' => [['adult' => 2, 'children' => []]],
            'language' => 'fr-FR',
        ],
        'selected_offer' => [
            'hotel_id' => '45',
            'hotel_name' => 'Hotel Paris',
            'source' => 129,
            'rate_key' => 'RATE-123',
            'room_name' => 'Double Room',
            'price' => 250.50,
            'currency' => 'LYD',
            'search_code' => 'SEARCH-123',
        ],
        'check_rate' => ['token_for_book' => 'TOKEN-123'],
    ], now()->addMinutes(60));

    $response = $this->from($state['baseUrl'].route('hotels.details', ['uuid' => $bookingUuid], false))
        ->post($state['baseUrl'].route('hotels.book', [], false), [
            'booking_uuid' => $bookingUuid,
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'mobile' => '44300400',
                'country' => 'Tunisia',
                'city' => 'Sfax',
            ],
            'rooms' => [[
                'paxes' => [
                    ['civility' => 'Mr', 'first_name' => 'John', 'last_name' => 'Doe'],
                ],
            ]],
        ]);

    $response->assertRedirect();

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://btob.3t.tn/hotels-api?method=book');
    expect(Order::query()->count())->toBe(0);
});

test('hotel cancellation denial with provider request sent marks cancellation pending', function () {
    global $state;

    Http::fake([
        'https://btob.3t.tn/hotels-api?method=cancel' => Http::response([
            'error' => true,
            'errorCode' => '502',
            'errorMessage' => 'Cancellation Denied, booking closed please contact Administrator !!!  Cancellation Not Allowed Throught API a cancellation request have been sent !!!',
            'response' => [],
            'method' => 'cancel',
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $provider = TenantHotelProvider::query()->where('provider_type', '3t')->firstOrFail();
    $wallet = $provider->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(500, ['type' => 'test_provider_fund']);

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $state['admin']->id,
        'number' => 'HOTEL-CANCEL-REQ',
        'status' => 'issued',
        'subtotal' => 100,
        'tax_total' => 0,
        'grand_total' => 110,
        'amount_paid' => 110,
        'currency' => 'LYD',
        'payment_method' => 'provider_wallet',
    ]);

    $item = $order->items()->create([
        'type' => 'hotel',
        'product_type' => 'hotel',
        'product_subtype' => 'hotel',
        'provider' => '3t',
        'provider_reference' => '32617',
        'item_details' => [
            'booking_id' => '32617',
            'booking_source' => 623,
            'provider_cost' => 100,
            'selling_price' => 110,
        ],
        'product_details' => [],
        'price' => 110,
        'net_fare' => 100,
        'taxes' => [],
        'total_tax' => 0,
        'total' => 110,
        'total_amount' => 110,
        'currency' => 'LYD',
        'status' => 'issued',
    ]);

    $this->post($state['baseUrl'].route('hotels.order-items.cancel', ['order' => $order, 'item' => $item], false))
        ->assertRedirect()
        ->assertSessionHas('success', 'Auto cancellation was denied by 3T, but a cancellation request has been sent for your booking.');

    $item->refresh();
    $order->refresh();
    $wallet->refresh();

    expect((string) $item->status)->toBe('cancellation')
        ->and((string) $order->status)->toBe('cancellation')
        ->and((bool) data_get($item->item_details, 'cancellation_request.auto_cancellation_denied'))->toBeTrue()
        ->and((string) data_get($item->item_details, 'cancellation_request.status'))->toBe('requested')
        ->and(round((float) $wallet->balanceFloat, 2))->toBe(500.0);
});

test('hotel configuration displays and deposits into provider default LYD wallet', function () {
    global $state;

    $this->actingAs($state['admin']);

    $provider = TenantHotelProvider::query()->where('provider_type', '3t')->firstOrFail();
    $provider->getOrCreateCurrencyWallet('LYD')->depositFloat(1000, ['type' => 'test_lyd_fund']);
    $provider->getOrCreateCurrencyWallet('USD')->depositFloat(50, ['type' => 'test_usd_fund']);

    $this->get($state['baseUrl'].route('settings.hotels.index', [], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Settings/Hotels')
            ->where('providers.0.currency', 'LYD')
            ->where('providers.0.remaining_balance', 1000)
        );

    $this->post($state['baseUrl'].route('settings.hotels.deposit', [], false), [
        'provider_type' => '3t',
        'currency' => 'LYD',
        'amount' => 250,
    ])->assertRedirect();

    expect(round((float) $provider->getOrCreateCurrencyWallet('LYD')->fresh()->balanceFloat, 2))->toBe(1250.0)
        ->and(round((float) $provider->getOrCreateCurrencyWallet('USD')->fresh()->balanceFloat, 2))->toBe(50.0);
});

test('hotel configuration syncs 3T credit check without changing provider wallet balance', function () {
    global $state;

    Http::fake([
        'https://btob.3t.tn/hotels-api?method=creditCheck' => Http::response([
            'method' => 'creditCheck',
            'response' => [
                'balance' => 987.65,
                'currency' => 'LYD',
            ],
            'error' => false,
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $provider = TenantHotelProvider::query()->where('provider_type', '3t')->firstOrFail();
    $provider->getOrCreateCurrencyWallet('LYD')->depositFloat(1000, ['type' => 'test_lyd_fund']);

    $this->post($state['baseUrl'].route('settings.hotels.credit-check', [], false), [
        'provider_type' => '3t',
    ])->assertRedirect();

    $provider->refresh();

    expect(data_get($provider->credentials, 'last_credit_check.balance'))->toBe(987.65)
        ->and(data_get($provider->credentials, 'last_credit_check.currency'))->toBe('LYD')
        ->and(round((float) $provider->getOrCreateCurrencyWallet('LYD')->fresh()->balanceFloat, 2))->toBe(1000.0);
});
