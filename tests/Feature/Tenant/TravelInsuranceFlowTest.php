<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\User;
use App\Services\Finance\LedgerDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'travel-flow-'.Str::random(4),
        'company_name' => 'Travel Flow Tenant',
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
        'commission_travel' => 10,
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

test('travel references endpoint is cached for 24 hours', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Travelers/ZonesLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Value' => 4, 'Text' => 'دول الشنغن'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/Travelers/DurationsLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Value' => 12, 'Text' => '5 أيام'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfilePaxes/NationalityLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Value' => 1, 'Text' => 'الليبية'],
            ],
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $this->get($state['baseUrl'].route('insurance.travel.references', [], false))
        ->assertSuccessful()
        ->assertJsonPath('zones.0.id', 4)
        ->assertJsonPath('durations.0.id', 12)
        ->assertJsonPath('nationalities.0.id', 1);

    $this->get($state['baseUrl'].route('insurance.travel.references', [], false))
        ->assertSuccessful();

    Http::assertSentCount(3);
});

test('travel insurance flow issues policies per passenger and creates one order', function () {
    global $state;

    $checkPolicySequence = Http::sequence()
        ->push([
            'Code' => 200,
            'Statues' => true,
            'data' => ['TotalPremium' => 100, 'NetPremium' => 90, 'TaxAmount' => 10, 'Curr' => 'LYD'],
        ], 200)
        ->push([
            'Code' => 200,
            'Statues' => true,
            'data' => ['TotalPremium' => 120, 'NetPremium' => 108, 'TaxAmount' => 12, 'Curr' => 'LYD'],
        ], 200);

    $paxSequence = Http::sequence()
        ->push(['Code' => 200, 'Statues' => true, 'data' => ['Id' => 7001]], 200)
        ->push(['Code' => 200, 'Statues' => true, 'data' => ['Id' => 7002]], 200);

    $policySequence = Http::sequence()
        ->push([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                'Id' => 8001,
                'PolicyNo' => 'TRV-8001',
                'EncryptedId' => 'ENC-8001',
                'TotalPremium' => 100,
                'NetPremium' => 90,
                'TaxAmount' => 10,
                'Curr' => 'LYD',
            ],
        ], 200)
        ->push([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                'Id' => 8002,
                'PolicyNo' => 'TRV-8002',
                'EncryptedId' => 'ENC-8002',
                'TotalPremium' => 120,
                'NetPremium' => 108,
                'TaxAmount' => 12,
                'Curr' => 'LYD',
            ],
        ], 200);

    Http::fake([
        'https://tameen.webapi.ly/api/Travelers/ZonesLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Value' => 4, 'Text' => 'دول الشنغن'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/Travelers/DurationsLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Value' => 12, 'Text' => '5 أيام'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfilePaxes/NationalityLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Value' => 1, 'Text' => 'الليبية'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/Travelers/CheckPolicyAgePrices' => $checkPolicySequence,
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
        'https://tameen.webapi.ly/api/ClientProfilePaxes/Post' => $paxSequence,
        'https://tameen.webapi.ly/api/Travelers/Post' => $policySequence,
    ]);

    $this->actingAs($state['admin']);

    $wallet = $state['admin']->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(1000, ['type' => 'test_fund']);

    $this->get($state['baseUrl'].route('insurance.travel.references', [], false))
        ->assertSuccessful()
        ->assertJsonPath('zones.0.id', 4);

    $priceResponse = $this->postJson($state['baseUrl'].route('insurance.travel.price', [], false), [
        'zone_id' => 4,
        'policy_date_from' => '2026-04-09',
        'policy_date_to' => '2026-04-13',
        'passengers' => [
            [
                'first_name' => 'Abdullah',
                'last_name' => 'Ishtiwy',
                'birth_date' => '1993-05-07',
                'gender_id' => 1,
                'birth_place' => 'Tripoli',
                'passport_number' => 'HPPLRF3K',
                'nationality_id' => 1,
            ],
            [
                'first_name' => 'Sami',
                'last_name' => 'Khaled',
                'birth_date' => '1988-10-12',
                'gender_id' => 1,
                'birth_place' => 'Tripoli',
                'passport_number' => 'PZX11122',
                'nationality_id' => 1,
            ],
        ],
    ])->assertSuccessful();

    $quoteToken = $priceResponse->json('quote_token');
    expect($quoteToken)->not->toBeEmpty();
    expect((float) $priceResponse->json('total_premium'))->toBe(220.0);
    expect((int) $priceResponse->json('duration_days'))->toBe(4);
    expect((string) $priceResponse->json('duration_text'))->toBe('4 days');

    $issueResponse = $this->post($state['baseUrl'].route('insurance.travel.issue', [], false), [
        'quote_token' => $quoteToken,
        'client_name' => 'Abdullah Ishtiwy',
        'client_phone' => '+218911111111',
        'client_address' => 'Tripoli',
        'client_email' => 'abdullah@example.com',
        'passengers' => [
            [
                'first_name' => 'Abdullah',
                'last_name' => 'Ishtiwy',
                'birth_date' => '1993-05-07',
                'gender_id' => 1,
                'birth_place' => 'Tripoli',
                'passport_number' => 'HPPLRF3K',
                'nationality_id' => 1,
            ],
            [
                'first_name' => 'Sami',
                'last_name' => 'Khaled',
                'birth_date' => '1988-10-12',
                'gender_id' => 1,
                'birth_place' => 'Tripoli',
                'passport_number' => 'PZX11122',
                'nationality_id' => 1,
            ],
        ],
    ]);

    $order = Order::query()->latest('created_at')->firstOrFail();

    $issueResponse->assertRedirect(route('orders.show', $order, false));

    $items = OrderItem::query()
        ->orderBy('id', 'asc')
        ->get()
        ->filter(fn (OrderItem $item): bool => (string) $item->order_id === (string) $order->id)
        ->values();

    expect($items)->toHaveCount(2)
        ->and((string) $items[0]->product_subtype)->toBe('travel')
        ->and((string) $items[1]->product_subtype)->toBe('travel')
        ->and((float) $items[0]->commission_amount)->toBe(9.0)
        ->and((float) $items[1]->commission_amount)->toBe(10.8)
        ->and((int) $items[0]->ledger_entry_id)->toBe(777)
        ->and((int) $items[1]->ledger_entry_id)->toBe(777)
        ->and($items[0]->wallet_transaction_id)->not->toBeNull()
        ->and($items[1]->wallet_transaction_id)->not->toBeNull();

    expect((float) $order->grand_total)->toBe(220.0)
        ->and((float) $order->subtotal)->toBe(198.0)
        ->and((float) $order->tax_total)->toBe(22.0);

    $wallet->refresh();

    expect(round((float) $wallet->balanceFloat, 2))->toBe(780.0);
});
