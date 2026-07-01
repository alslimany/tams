<?php

use App\Models\Airport;
use App\Models\Country;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\TenantEsimProvider;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-esim-'.Str::random(4),
        'company_name' => 'API eSIM Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    tenancy()->initialize($tenant);

    $user = User::factory()->create([
        'email' => 'agent@example.com',
        'password' => 'Secret123!',
        'role' => 'agent',
        'is_active' => true,
    ]);

    TenantEsimProvider::query()->create([
        'provider_type' => 'l2',
        'name' => 'L2 Travel eSIM',
        'credentials' => [
            'base_url' => 'https://l2travelesim.com',
            'api_key' => 'test-api-key',
            'client_secret' => 'test-client-secret',
        ],
        'is_active' => true,
        'commission_esim' => 10,
        'currency' => 'USD',
    ]);

    Country::query()->updateOrCreate(
        ['alpha2' => 'tn'],
        [
            'alpha3' => 'TUN',
            'name_en' => 'Tunisia',
            'name_ar' => 'تونس',
            'name_fr' => 'Tunisie',
            'esim_featured' => true,
        ],
    );

    Airport::query()->updateOrCreate(
        ['iata_code' => 'TUN'],
        [
            'name' => ['en' => 'Tunis Carthage International Airport'],
            'city' => ['en' => 'Tunis'],
            'country' => ['en' => 'TN'],
        ],
    );

    $state['tenant'] = $tenant;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $state['token'] = $user->createToken('Test Device', ['read', 'write', 'issue'])->plainTextToken;
    $state['user'] = $user;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

function fakeL2EsimApi(): void
{
    Http::fake([
        'https://l2travelesim.com/api/whitelabel/v2/catalogue' => Http::response([
            'bundles' => [
                [
                    'name' => 'esim_1GB_30D_TN_U',
                    'description' => 'Tunisia 1GB 30 Days',
                    'countries' => [['name' => 'Tunisia', 'iso' => 'TN']],
                    'dataAmount' => 1024,
                    'duration' => 30,
                    'speed' => ['4G'],
                    'unlimited' => false,
                    'price' => 12.50,
                ],
            ],
        ], 200),
        'https://l2travelesim.com/api/whitelabel/v2/organization' => Http::response([
            'balance' => 500,
        ], 200),
        'https://l2travelesim.com/api/whitelabel/v2/processOrders' => Http::response([
            'orderReference' => 'L2-API-001',
            'assigned' => true,
            'valid' => true,
            'order' => [[
                'esims' => [[
                    'iccid' => '8988247000100000999',
                    'matchingId' => 'MATCH-API-001',
                    'smdpAddress' => 'smdp.example.com',
                ]],
            ]],
        ], 200),
    ]);
}

test('api returns esim packages for destination airport iata', function () {
    global $state;

    fakeL2EsimApi();

    $response = test()->withToken($state['token'])
        ->getJson($state['apiUrl'].'/esim/airport/TUN/packages');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.airport.iata', 'TUN')
        ->assertJsonPath('data.airport.country_iso', 'TN')
        ->assertJsonPath('data.packages.0.id', 'esim_1GB_30D_TN_U')
        ->assertJsonPath('data.packages.0.price', 12.5);
});

test('api esim search select and book flow creates issued order', function () {
    global $state;

    fakeL2EsimApi();

    $provider = TenantEsimProvider::query()->firstOrFail();
    $provider->getOrCreateCurrencyWallet('USD')->depositFloat(500, ['type' => 'test_fund']);
    $state['user']->getOrCreateCurrencyWallet('USD')->depositFloat(500, ['type' => 'test_fund']);

    $search = test()->withToken($state['token'])
        ->postJson($state['apiUrl'].'/esim/search', ['country' => 'TN'])
        ->assertOk()
        ->assertJsonPath('data.country', 'TN');

    $uuid = $search->json('data.uuid');

    test()->withToken($state['token'])
        ->getJson($state['apiUrl']."/esim/results/{$uuid}/packages")
        ->assertOk()
        ->assertJsonPath('data.packages.0.id', 'esim_1GB_30D_TN_U');

    $select = test()->withToken($state['token'])
        ->postJson($state['apiUrl'].'/esim/select', [
            'search_uuid' => $uuid,
            'package_id' => 'esim_1GB_30D_TN_U',
        ])
        ->assertOk();

    $bookingUuid = $select->json('data.booking_uuid');

    test()->withToken($state['token'])
        ->postJson($state['apiUrl'].'/esim/book', [
            'booking_uuid' => $bookingUuid,
            'customer' => [
                'name' => 'Mobile User',
                'email' => 'mobile@example.com',
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.esim.iccid', '8988247000100000999')
        ->assertJsonPath('data.order.status', 'issued');

    expect(Order::query()->count())->toBe(1);
});

test('api esim countries endpoint returns featured countries', function () {
    global $state;

    test()->withToken($state['token'])
        ->getJson($state['apiUrl'].'/esim/countries')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'countries',
                'featured_countries',
            ],
        ]);
});
