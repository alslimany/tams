<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\User;
use App\Services\Finance\LedgerDriver;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'orange-flow-'.Str::random(4),
        'company_name' => 'Orange Flow Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Al Baraka Insurance',
        'credentials' => [
            'base_url' => 'https://tameen.webapi.ly',
            'token' => 'test-token',
        ],
        'is_active' => true,
        'commission_compulsory' => 5,
        'commission_travel' => 7,
        'commission_orange' => 8,
    ]);

    app()->bind(LedgerDriver::class, fn () => new class implements LedgerDriver
    {
        public function postOperationJournal(string $source, string $description, array $entries): int
        {
            return 777;
        }
    });

    $state['tenant'] = $tenant;
    $state['admin'] = $admin;
    $state['baseUrl'] = 'http://'.$tenant->domains->first()->domain;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('orange insurance full flow creates order and deducts provider wallet', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Oranges/GetCountriesLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [['Value' => 1, 'Text' => 'Tunisia']],
        ], 200),
        'https://tameen.webapi.ly/api/Oranges/GetInsuranceClauseLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [['Value' => 1, 'Text' => '16 HP']],
        ], 200),
        'https://tameen.webapi.ly/api/Oranges/GetCarsLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [['Value' => 14, 'Text' => 'Toyota']],
        ], 200),
        'https://tameen.webapi.ly/api/Oranges/GetVehicleNationalityLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [['Value' => 1, 'Text' => 'Libya']],
        ], 200),
        'https://tameen.webapi.ly/api/Oranges/CheckPolicyPrices' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => 74.565,
        ], 200),
        'https://tameen.webapi.ly/api/Oranges/Post' => Http::response([
            'Id' => 67785,
            'EncryptedId' => 'ENC-ORANGE-001',
            'CardNumber' => 'LBY/6825725',
            'totalpremium' => 74.565,
        ], 200),
        'https://tameen.webapi.ly/api/Oranges/Get*' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [[
                'Id' => 67785,
                'EncryptedId' => 'ENC-ORANGE-001',
                'CardNumber' => 'LBY/6825725',
                'TotalPremium' => 74.565,
            ]],
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $provider = TenantInsuranceProvider::query()->where('provider_type', 'albaraka')->firstOrFail();
    $wallet = $provider->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(500, ['type' => 'test_provider_fund']);

    $this->get($state['baseUrl'].route('insurance.orange.references', [], false))
        ->assertSuccessful()
        ->assertJsonPath('countries.0.value', 1)
        ->assertJsonPath('countries.0.name', 'Tunisia')
        ->assertJsonPath('documentTypes.0.value', 1)
        ->assertJsonPath('documentTypes.0.name', '16 HP');

    $priceResponse = $this->postJson($state['baseUrl'].route('insurance.orange.price', [], false), [
        'country' => 1,
        'document_type_id' => 1,
        'policy_date_from' => '2026-04-05',
        'policy_date_to' => '2026-04-12',
    ])->assertSuccessful();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://tameen.webapi.ly/api/Oranges/CheckPolicyPrices'
            && (int) $request['DocumentTypeID'] === 1
            && (int) $request['PolicyDay'] === 7
            && (int) $request['Countries'] === 1;
    });

    $quoteToken = (string) $priceResponse->json('quote_token');

    expect((float) $priceResponse->json('total_premium'))->toBe(74.565)
        ->and((float) $priceResponse->json('net_premium'))->toBe(74.565)
        ->and((float) $priceResponse->json('tax_amount'))->toBe(0.0);

    $this->get($state['baseUrl'].route('insurance.orange.beneficiary', ['quoteToken' => $quoteToken], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Tenant/Insurance/OrangeBeneficiary'));

    $issueResponse = $this->post($state['baseUrl'].route('insurance.orange.issue', [], false), [
        'quote_token' => $quoteToken,
        'name' => 'ABDULLAH MOHAMMED',
        'address' => 'Tripoli',
        'phone' => '+218911388788',
        'chassis_number' => '1VXBR12EXCP901213',
        'metal_plate_number' => '1074316',
        'manufacture_year' => 2009,
        'car_id' => 14,
        'nationality' => 1,
    ]);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://tameen.webapi.ly/api/Oranges/Post'
            && $request['Name'] === 'ABDULLAH MOHAMMED'
            && $request['ChassisNumber'] === '1VXBR12EXCP901213'
            && (int) $request['Country'] === 1
            && (int) $request['NumberOfDays'] === 7
            && (int) $request['DocumentTypeID'] === 1;
    });

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://tameen.webapi.ly/api/Oranges/Get?DateFrom=');
    });

    $order = Order::query()->latest('created_at')->firstOrFail();
    $issueResponse->assertRedirect(route('orders.show', $order, false));

    $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

    expect((string) $item->product_subtype)->toBe('orange')
        ->and((string) $item->provider_reference)->toBe('LBY/6825725')
        ->and((float) $item->commission_amount)->toBe(5.97)
        ->and($item->wallet_transaction_id)->not->toBeNull();

    $wallet->refresh();
    expect(round((float) $wallet->balanceFloat, 2))->toBe(425.43);

    $walletTransaction = WalletTransaction::query()->where('uuid', (string) $item->wallet_transaction_id)->first();

    expect($walletTransaction)->not->toBeNull()
        ->and((int) $walletTransaction->wallet_id)->toBe((int) $wallet->id)
        ->and(data_get($walletTransaction->meta, 'type'))->toBe('provider_issuance_cost')
        ->and(data_get($walletTransaction->meta, 'product_subtype'))->toBe('orange');
});

test('orange issue fails early when provider wallet is insufficient and does not call issue endpoint', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Oranges/CheckPolicyPrices' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => ['TotalPremium' => 59.995, 'NetPremium' => 50, 'Tax' => 9.995, 'Curr' => 'LYD'],
        ], 200),
        'https://tameen.webapi.ly/*' => Http::response(['Code' => 500, 'Statues' => false], 500),
    ]);

    $this->actingAs($state['admin']);

    $priceResponse = $this->postJson($state['baseUrl'].route('insurance.orange.price', [], false), [
        'country' => 1,
        'document_type_id' => 1,
        'policy_date_from' => '2026-04-05',
        'policy_date_to' => '2026-04-12',
    ])->assertSuccessful();

    $this->post($state['baseUrl'].route('insurance.orange.issue', [], false), [
        'quote_token' => (string) $priceResponse->json('quote_token'),
        'name' => 'ABDULLAH MOHAMMED',
        'address' => 'Tripoli',
        'phone' => '+218911388788',
        'chassis_number' => '1VXBR12EXCP901213',
        'metal_plate_number' => '1074316',
        'manufacture_year' => 2009,
        'car_id' => 14,
        'nationality' => 1,
    ])->assertSessionHas('error');

    expect(Order::query()->count())->toBe(0);

    Http::assertNotSent(function (Request $request): bool {
        return $request->url() === 'https://tameen.webapi.ly/api/Oranges/Post';
    });
});
