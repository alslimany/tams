<?php

use App\Models\NetworkMembership;
use App\Models\ProviderAllocation;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\User;
use App\Services\AgencyNetwork\MerchantAgencyWalletManager;
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

    $provider->getOrCreateCurrencyWallet('LYD');

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
        ->push(['Code' => 200, 'Statues' => true, 'data' => 7001], 200)
        ->push(['Code' => 200, 'Statues' => true, 'data' => 7002], 200);

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

    $provider = TenantInsuranceProvider::query()->where('provider_type', 'albaraka')->firstOrFail();
    $wallet = $provider->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(1000, ['type' => 'seed_balance']);

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
        ->and((string) $items[0]->wallet_transaction_id)->not->toBe('')
        ->and((string) $items[1]->wallet_transaction_id)->not->toBe('');

    expect((float) $order->grand_total)->toBe(220.0)
        ->and((float) $order->subtotal)->toBe(198.0)
        ->and((float) $order->tax_total)->toBe(22.0)
        ->and((string) $order->payment_method)->toBe('provider_wallet');

    $wallet->refresh();

    expect(round((float) $wallet->balanceFloat, 2))->toBe(780.0);

    $walletTransaction = WalletTransaction::query()
        ->where('uuid', (string) $items[0]->wallet_transaction_id)
        ->first();

    expect($walletTransaction)->not->toBeNull()
        ->and((int) $walletTransaction->wallet_id)->toBe((int) $wallet->id)
        ->and(data_get($walletTransaction->meta, 'type'))->toBe('provider_issuance_cost')
        ->and(data_get($walletTransaction->meta, 'provider_type'))->toBe('insurance');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://tameen.webapi.ly/api/Travelers/Post'
            && (int) $request['ClientProfileId'] === 66272
            && (int) $request['ClientProfilePaxeId'] === 7001
            && (string) $request['ZoneID'] === '4'
            && (string) $request['InsuranceDurationID'] === '12'
            && (string) $request['PolicyDateFrom'] === '2026-04-09'
            && $request['IsPolicyPaid'] === false;
    });

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://tameen.webapi.ly/api/Travelers/Post'
            && (int) $request['ClientProfileId'] === 66272
            && (int) $request['ClientProfilePaxeId'] === 7002
            && (string) $request['ZoneID'] === '4'
            && (string) $request['InsuranceDurationID'] === '12'
            && (string) $request['PolicyDateFrom'] === '2026-04-09'
            && $request['IsPolicyPaid'] === false;
    });
});

test('travel insurance issue fails early when wallet is insufficient and does not call issuance endpoints', function () {
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
        'https://tameen.webapi.ly/api/Travelers/CheckPolicyAgePrices' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => ['TotalPremium' => 100, 'NetPremium' => 90, 'TaxAmount' => 10, 'Curr' => 'LYD'],
        ], 200),
        'https://tameen.webapi.ly/*' => Http::response([
            'Code' => 500,
            'Statues' => false,
            'Messages' => 'Should not be called in insufficient wallet flow',
        ], 500),
    ]);

    $this->actingAs($state['admin']);

    $provider = TenantInsuranceProvider::query()->where('provider_type', 'albaraka')->firstOrFail();
    $wallet = $provider->getOrCreateCurrencyWallet('LYD');
    $balance = (float) $wallet->balanceFloat;

    if ($balance > 0) {
        $wallet->forceWithdrawFloat($balance, ['type' => 'test_reset_balance']);
    }

    $this->get($state['baseUrl'].route('insurance.travel.references', [], false))->assertSuccessful();

    $priceResponse = $this->postJson($state['baseUrl'].route('insurance.travel.price', [], false), [
        'zone_id' => 4,
        'policy_date_from' => '2026-04-09',
        'policy_date_to' => '2026-04-13',
        'passengers' => [
            [
                'first_name' => 'A',
                'last_name' => 'B',
                'birth_date' => '1993-05-07',
                'gender_id' => 1,
                'birth_place' => 'Tripoli',
                'passport_number' => 'HPPLRF3K',
                'nationality_id' => 1,
            ],
        ],
    ])->assertSuccessful();

    $quoteToken = (string) $priceResponse->json('quote_token');

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
        ],
    ]);

    $issueResponse->assertSessionHas('error');

    expect(Order::query()->get()->count())->toBe(0);

    Http::assertNotSent(function ($request): bool {
        return str_contains($request->url(), '/api/ClientProfiles/GetByPhone')
            || str_contains($request->url(), '/api/ClientProfiles/Post')
            || str_contains($request->url(), '/api/ClientProfilePaxes/Post')
            || str_contains($request->url(), '/api/Travelers/Post');
    });
});

test('printing travel policy fetches traveler report pdf by encrypted reference', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Travelers/GetReportById?EncryptedId=ENC-TRV-001' => Http::response('%PDF-1.4 travel-pdf', 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $provider = TenantInsuranceProvider::query()->where('provider_type', 'albaraka')->firstOrFail();

    $order = app(\App\Actions\Finance\CreateOrderFromInsuranceBooking::class)->createFromTravelPolicies(
        userId: $state['admin']->id,
        clientProfileData: [
            'name' => 'Travel Report Client',
            'phone' => '0911111111',
            'address' => 'Tripoli',
            'email' => 'travel@example.com',
            'client_profile_id' => 66272,
        ],
        policyItems: [
            [
                'passenger' => [
                    'first_name' => 'Travel',
                    'last_name' => 'Passenger',
                    'birth_date' => '1993-05-07',
                    'gender_id' => 1,
                    'birth_place' => 'Tripoli',
                    'passport_number' => 'TRV123',
                    'nationality_id' => 1,
                ],
                'policy_details' => [
                    'policy_id' => 81001,
                    'policy_number' => 'TRV-81001',
                    'report_reference' => 'ENC-TRV-001',
                    'zone_id' => 4,
                    'duration_id' => 12,
                    'policy_date_from' => '2026-04-09',
                    'policy_date_to' => '2026-04-13',
                    'raw' => ['data' => ['Id' => 81001, 'EncryptedId' => 'ENC-TRV-001']],
                ],
                'net_amount' => 90,
                'total_amount' => 100,
                'tax_amount' => 10,
                'currency' => 'LYD',
            ],
        ],
        insuranceProvider: $provider,
        processAgencyWallet: false,
    );

    $item = $order->items->firstOrFail();

    $this->actingAs($state['admin'])
        ->get($state['baseUrl'].route('insurance.order-items.report', ['order' => $order->id, 'item' => $item->id], false))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://tameen.webapi.ly/api/Travelers/GetReportById?EncryptedId=ENC-TRV-001';
    });
});

test('merchant agency network travel issuance deducts merchant agency wallet and agency provider wallet', function () {
    global $state;

    $agency = Tenant::create([
        'id' => 'travel-net-agency-'.Str::random(4),
        'company_name' => 'Travel Network Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    tenancy()->initialize($agency);
    $agencyProvider = TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Agency Travel Al Baraka',
        'credentials' => [
            'base_url' => 'https://agency-travel.test',
            'token' => 'agency-travel-token',
        ],
        'is_active' => true,
        'commission_travel' => 10,
    ]);
    $agencyProvider->getOrCreateCurrencyWallet('LYD')->depositFloat(500, ['type' => 'test_provider_fund']);
    tenancy()->end();

    $membership = NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $state['tenant']->id,
        'status' => NetworkMembership::StatusActive,
        'accepted_at' => now(),
    ]);

    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $state['tenant']->id,
        'provider_type' => 'insurance',
        'provider_driver' => 'albaraka',
        'provider_identity' => 'ALBARAKA-TRAVEL',
        'source_provider_model' => TenantInsuranceProvider::class,
        'source_provider_id' => $agencyProvider->id,
        'status' => ProviderAllocation::StatusActive,
        'is_offered_by_agency' => true,
        'is_enabled_by_merchant' => true,
        'enabled_at' => now(),
    ]);

    tenancy()->initialize($state['tenant']);
    app(MerchantAgencyWalletManager::class)->depositForMembership($membership, 250, 'LYD', [
        'reference_number' => 'TRAVEL-MER-DEP-001',
    ]);

    Http::fake([
        'https://agency-travel.test/api/Travelers/ZonesLookup' => Http::response(['Code' => 200, 'Statues' => true, 'data' => [['Value' => 4, 'Text' => 'Schengen']]], 200),
        'https://agency-travel.test/api/Travelers/DurationsLookup' => Http::response(['Code' => 200, 'Statues' => true, 'data' => [['Value' => 12, 'Text' => '5 days']]], 200),
        'https://agency-travel.test/api/Travelers/CheckPolicyAgePrices' => Http::response(['Code' => 200, 'Statues' => true, 'data' => ['TotalPremium' => 100, 'NetPremium' => 90, 'TaxAmount' => 10, 'Curr' => 'LYD']], 200),
        'https://agency-travel.test/api/ClientProfiles/GetByPhone*' => Http::response(['Code' => 404, 'Statues' => false, 'data' => null], 200),
        'https://agency-travel.test/api/ClientProfiles/Post' => Http::response(['Code' => 200, 'Statues' => true, 'data' => ['Id' => 66272]], 200),
        'https://agency-travel.test/api/ClientProfilePaxes/Post' => Http::response(['Code' => 200, 'Statues' => true, 'data' => 7001], 200),
        'https://agency-travel.test/api/Travelers/Post' => Http::response(['Code' => 200, 'Statues' => true, 'data' => ['Id' => 8001, 'PolicyNo' => 'TRV-NET-8001', 'EncryptedId' => 'ENC-TRV-NET-8001', 'TotalPremium' => 100, 'NetPremium' => 90, 'TaxAmount' => 10, 'Curr' => 'LYD']], 200),
    ]);

    $this->actingAs($state['admin']);

    $priceResponse = $this->postJson($state['baseUrl'].route('insurance.travel.price', [], false), [
        'zone_id' => 4,
        'policy_date_from' => '2026-04-09',
        'policy_date_to' => '2026-04-13',
        'passengers' => [[
            'first_name' => 'Travel',
            'last_name' => 'Merchant',
            'birth_date' => '1993-05-07',
            'gender_id' => 1,
            'birth_place' => 'Tripoli',
            'passport_number' => 'TRVNET1',
            'nationality_id' => 1,
        ]],
    ])->assertSuccessful();

    expect($priceResponse->json('provider_source.source_type'))->toBe('agency_network')
        ->and($priceResponse->json('provider_source.provider_selector'))->toBe("agency_network:{$allocation->id}");

    $issueResponse = $this->post($state['baseUrl'].route('insurance.travel.issue', [], false), [
        'quote_token' => (string) $priceResponse->json('quote_token'),
        'client_name' => 'Travel Merchant',
        'client_phone' => '+218911111111',
        'client_address' => 'Tripoli',
        'client_email' => 'travel-merchant@example.com',
        'passengers' => [[
            'first_name' => 'Travel',
            'last_name' => 'Merchant',
            'birth_date' => '1993-05-07',
            'gender_id' => 1,
            'birth_place' => 'Tripoli',
            'passport_number' => 'TRVNET1',
            'nationality_id' => 1,
        ]],
    ]);

    $order = Order::query()->latest('created_at')->firstOrFail();
    $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

    $issueResponse->assertRedirect(route('orders.show', $order, false));

    expect(data_get($item->item_details, 'financial_source'))->toBe('agency_network_supply')
        ->and(data_get($item->item_details, 'provider_wallet_transaction_id'))->not->toBeNull()
        ->and($item->wallet_transaction_id)->not->toBeNull();

    $merchantWallet = app(MerchantAgencyWalletManager::class)->getOrCreateWalletForMembership($membership, 'LYD');
    expect(round((float) $merchantWallet->balanceFloat, 2))->toBe(150.0);

    tenancy()->initialize($agency);
    $agencyProviderWallet = TenantInsuranceProvider::query()->findOrFail($agencyProvider->id)->getOrCreateCurrencyWallet('LYD');
    expect(round((float) $agencyProviderWallet->balanceFloat, 2))->toBe(400.0);

    $providerWithdrawal = WalletTransaction::query()->where('uuid', (string) data_get($item->item_details, 'provider_wallet_transaction_id'))->first();
    expect($providerWithdrawal)->not->toBeNull()
        ->and(data_get($providerWithdrawal->meta, 'provider_source_type'))->toBe('agency_network')
        ->and(data_get($providerWithdrawal->meta, 'provider_allocation_id'))->toBe($allocation->id);

    tenancy()->initialize($state['tenant']);
});

test('merchant agency network travel issuance fails early when merchant agency wallet is insufficient', function () {
    global $state;

    $agency = Tenant::create([
        'id' => 'travel-net-low-merchant-'.Str::random(4),
        'company_name' => 'Travel Low Merchant Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    tenancy()->initialize($agency);
    $agencyProvider = TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Agency Travel Wallet Guard',
        'credentials' => [
            'base_url' => 'https://agency-travel-low-merchant.test',
            'token' => 'agency-travel-token',
        ],
        'is_active' => true,
        'commission_travel' => 10,
    ]);
    $agencyProvider->getOrCreateCurrencyWallet('LYD')->depositFloat(500, ['type' => 'test_provider_fund']);
    tenancy()->end();

    $membership = NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $state['tenant']->id,
        'status' => NetworkMembership::StatusActive,
        'accepted_at' => now(),
    ]);

    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $state['tenant']->id,
        'provider_type' => 'insurance',
        'provider_driver' => 'albaraka',
        'provider_identity' => 'ALBARAKA-TRAVEL-LOW-MERCHANT',
        'source_provider_model' => TenantInsuranceProvider::class,
        'source_provider_id' => $agencyProvider->id,
        'status' => ProviderAllocation::StatusActive,
        'is_offered_by_agency' => true,
        'is_enabled_by_merchant' => true,
        'enabled_at' => now(),
    ]);

    tenancy()->initialize($state['tenant']);
    $merchantWallet = app(MerchantAgencyWalletManager::class)->getOrCreateWalletForMembership($membership, 'LYD');

    Http::fake([
        'https://agency-travel-low-merchant.test/api/Travelers/ZonesLookup' => Http::response(['Code' => 200, 'Statues' => true, 'data' => [['Value' => 4, 'Text' => 'Schengen']]], 200),
        'https://agency-travel-low-merchant.test/api/Travelers/DurationsLookup' => Http::response(['Code' => 200, 'Statues' => true, 'data' => [['Value' => 12, 'Text' => '5 days']]], 200),
        'https://agency-travel-low-merchant.test/api/Travelers/CheckPolicyAgePrices' => Http::response(['Code' => 200, 'Statues' => true, 'data' => ['TotalPremium' => 100, 'NetPremium' => 90, 'TaxAmount' => 10, 'Curr' => 'LYD']], 200),
        'https://agency-travel-low-merchant.test/*' => Http::response(['Code' => 500, 'Statues' => false], 500),
    ]);

    $this->actingAs($state['admin']);

    $priceResponse = $this->postJson($state['baseUrl'].route('insurance.travel.price', [], false), [
        'zone_id' => 4,
        'policy_date_from' => '2026-04-09',
        'policy_date_to' => '2026-04-13',
        'passengers' => [[
            'first_name' => 'Travel',
            'last_name' => 'Merchant',
            'birth_date' => '1993-05-07',
            'gender_id' => 1,
            'birth_place' => 'Tripoli',
            'passport_number' => 'TRVLOW1',
            'nationality_id' => 1,
        ]],
    ])->assertSuccessful();

    expect($priceResponse->json('provider_source.provider_selector'))->toBe("agency_network:{$allocation->id}");

    $this->post($state['baseUrl'].route('insurance.travel.issue', [], false), [
        'quote_token' => (string) $priceResponse->json('quote_token'),
        'client_name' => 'Travel Merchant',
        'client_phone' => '+218911111111',
        'client_address' => 'Tripoli',
        'client_email' => 'travel-merchant@example.com',
        'passengers' => [[
            'first_name' => 'Travel',
            'last_name' => 'Merchant',
            'birth_date' => '1993-05-07',
            'gender_id' => 1,
            'birth_place' => 'Tripoli',
            'passport_number' => 'TRVLOW1',
            'nationality_id' => 1,
        ]],
    ])->assertSessionHas('error');

    expect(Order::query()->count())->toBe(0)
        ->and(round((float) $merchantWallet->fresh()->balanceFloat, 2))->toBe(0.0);

    tenancy()->initialize($agency);
    $agencyProviderWallet = TenantInsuranceProvider::query()->findOrFail($agencyProvider->id)->getOrCreateCurrencyWallet('LYD');
    expect(round((float) $agencyProviderWallet->balanceFloat, 2))->toBe(500.0);

    tenancy()->initialize($state['tenant']);

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/api/ClientProfiles/GetByPhone')
            || str_contains($request->url(), '/api/ClientProfiles/Post')
            || str_contains($request->url(), '/api/ClientProfilePaxes/Post')
            || str_contains($request->url(), '/api/Travelers/Post');
    });
});
