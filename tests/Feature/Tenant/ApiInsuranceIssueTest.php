<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\User;
use App\Services\Finance\LedgerDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-ins-issue-'.Str::random(4),
        'company_name' => 'API Insurance Issue Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $user = User::factory()->create([
        'email' => 'agent@example.com',
        'password' => 'Secret123!',
        'role' => 'admin',
        'is_active' => true,
    ]);

    $provider = TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Al Baraka Insurance',
        'credentials' => [
            'base_url' => 'https://tameen.webapi.ly',
            'token' => 'test-token',
        ],
        'is_active' => true,
        'commission_compulsory' => 5,
        'commission_travel' => 10,
        'commission_orange' => 8,
    ]);

    $provider->getOrCreateCurrencyWallet('LYD')->depositFloat(2000, ['type' => 'test_fund']);
    $user->getOrCreateCurrencyWallet('LYD')->depositFloat(2000, ['type' => 'test_fund']);

    app()->bind(LedgerDriver::class, fn () => new class implements LedgerDriver
    {
        public function postOperationJournal(string $source, string $description, array $entries): int
        {
            return 777;
        }
    });

    $state['tenant'] = $tenant;
    $state['user'] = $user;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $state['token'] = $user->createToken('Test Device', ['read', 'write', 'issue'])->plainTextToken;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('api travel insurance price then issue returns order json', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Travelers/DurationsLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Value' => 12, 'Text' => '5 أيام'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/Travelers/ZonesLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Value' => 4, 'Text' => 'دول الشنغن'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/Travelers/CheckPolicyAgePrices' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => ['TotalPremium' => 100, 'NetPremium' => 90, 'TaxAmount' => 10, 'Curr' => 'LYD'],
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfiles/GetByPhone*' => Http::response([
            'Code' => 404,
            'Statues' => false,
            'Messages' => 'Not found',
            'data' => null,
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfiles/Post' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => ['Id' => 66272],
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfilePaxes/Post' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => 7001,
        ], 200),
        'https://tameen.webapi.ly/api/Travelers/Post' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                'Id' => 8001,
                'PolicyNo' => 'TRV-8001',
                'EncryptedId' => 'ENC-API-TRV-001',
                'TotalPremium' => 100,
                'NetPremium' => 90,
                'TaxAmount' => 10,
                'Curr' => 'LYD',
            ],
        ], 200),
    ]);

    $price = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/insurance/travel/price', [
            'zone_id' => 4,
            'policy_date_from' => '2026-04-09',
            'policy_date_to' => '2026-04-13',
            'passengers' => [
                [
                    'first_name' => 'Travel',
                    'last_name' => 'Passenger',
                    'birth_date' => '1993-05-07',
                    'gender_id' => 1,
                    'birth_place' => 'Tripoli',
                    'passport_number' => 'TRV123',
                    'nationality_id' => 1,
                ],
            ],
        ])
        ->assertSuccessful();

    $quoteToken = (string) $price->json('quote_token');
    expect($quoteToken)->not->toBeEmpty();

    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/insurance/travel/issue', [
            'quote_token' => $quoteToken,
            'client_name' => 'Travel Client',
            'client_phone' => '+218911111111',
            'client_address' => 'Tripoli',
            'client_email' => 'travel-api@example.com',
            'passengers' => [
                [
                    'first_name' => 'Travel',
                    'last_name' => 'Passenger',
                    'birth_date' => '1993-05-07',
                    'gender_id' => 1,
                    'birth_place' => 'Tripoli',
                    'passport_number' => 'TRV123',
                    'nationality_id' => 1,
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.items.0.report_reference', 'ENC-API-TRV-001');

    expect(Order::query()->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tameen.webapi.ly/api/Travelers/Post');
});

test('api orange insurance price then issue returns order json', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Oranges/CheckPolicyPrices' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => 74.565,
        ], 200),
        'https://tameen.webapi.ly/api/Oranges/Post' => Http::response([
            'Id' => 67785,
            'EncryptedId' => 'ENC-ORANGE-API',
            'CardNumber' => 'LBY/6825725',
            'totalpremium' => 74.565,
        ], 200),
        'https://tameen.webapi.ly/api/Oranges/Get*' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [[
                'Id' => 67785,
                'EncryptedId' => 'ENC-ORANGE-API',
                'CardNumber' => 'LBY/6825725',
                'TotalPremium' => 74.565,
            ]],
        ], 200),
    ]);

    $price = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/insurance/orange/price', [
            'country' => 1,
            'document_type_id' => 1,
            'policy_date_from' => '2026-04-05',
            'policy_date_to' => '2026-04-12',
        ])
        ->assertSuccessful();

    $quoteToken = (string) $price->json('quote_token');
    expect($quoteToken)->not->toBeEmpty();

    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/insurance/orange/issue', [
            'quote_token' => $quoteToken,
            'name' => 'ABDULLAH MOHAMMED',
            'address' => 'Tripoli',
            'phone' => '+218911388788',
            'chassis_number' => '1VXBR12EXCP901213',
            'metal_plate_number' => '1074316',
            'manufacture_year' => 2009,
            'car_id' => 14,
            'nationality' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.report_reference', 'ENC-ORANGE-API')
        ->assertJsonPath('data.policy_number', 'LBY/6825725');

    expect(Order::query()->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tameen.webapi.ly/api/Oranges/Post');
});

test('api travel issue returns 410 when quote is missing', function () {
    global $state;

    $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/insurance/travel/issue', [
            'quote_token' => 'missing-quote',
            'client_name' => 'Travel Client',
            'client_phone' => '+218911111111',
            'passengers' => [
                [
                    'first_name' => 'Travel',
                    'last_name' => 'Passenger',
                    'birth_date' => '1993-05-07',
                    'gender_id' => 1,
                    'birth_place' => 'Tripoli',
                    'passport_number' => 'TRV123',
                    'nationality_id' => 1,
                ],
            ],
        ])
        ->assertStatus(410)
        ->assertJsonPath('success', false);
});
