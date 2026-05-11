<?php

use App\Actions\Finance\CreateOrderFromInsuranceBooking;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'compulsory-flow-'.Str::random(4),
        'company_name' => 'Compulsory Flow Tenant',
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

test('compulsory durations reference is cached for subsequent requests', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Compulsories/DurationsLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'Messages' => null,
            'data' => [
                ['Value' => 1, 'Text' => 'خمسة ايام'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfileVehicles/DocumentTypesLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Value' => 14, 'Text' => '16 حصان فأقل', 'Group' => 'الخاصة'],
            ],
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $this->get($state['baseUrl'].route('insurance.compulsory.references.durations', [], false))
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', 1);

    $this->get($state['baseUrl'].route('insurance.compulsory.references.durations', [], false))
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', 1);

    Http::assertSentCount(1);
});

test('compulsory insurance full flow creates order and deducts wallet', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Compulsories/DurationsLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Value' => 1, 'Text' => 'خمسة ايام'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfileVehicles/DocumentTypesLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Value' => 14, 'Text' => '16 حصان فأقل', 'Group' => 'الخاصة'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfileVehicles/CarsLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Id' => 3, 'Name' => 'Sedan'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfileVehicles/ColorsLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Id' => 4, 'Name' => 'White'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfileVehicles/LicensingAuthoritiesLookup' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                ['Id' => 5, 'Name' => 'Tripoli Authority'],
            ],
        ], 200),
        'https://tameen.webapi.ly/api/Compulsories/CheckPolicyPrices' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                'TotalPremium' => 150,
                'NetPremium' => 140,
                'Taxes' => 10,
                'Curr' => 'LYD',
                'PriceDetailID' => 14,
            ],
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfiles/GetByPhone*' => Http::response([
            'Code' => 500,
            'Statues' => false,
            'Messages' => 'Client profile not found',
            'data' => null,
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfiles/Post' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => ['Id' => 101],
        ], 200),
        'https://tameen.webapi.ly/api/ClientProfileVehicles/Post' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => ['Id' => 202],
        ], 200),
        'https://tameen.webapi.ly/api/Compulsories/Post' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'policyNumber' => 'POL303',
            'data' => ['Id' => 303],
        ], 200),
        'https://tameen.webapi.ly/api/Compulsories/Get/303' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                'Id' => 303,
                'PolicyNumber' => 'POL303',
                'EncryptedId' => 'ENC303',
                'TotalPremium' => 150,
                'NetPremium' => 140,
                'Taxes' => 10,
                'Curr' => 'LYD',
            ],
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $provider = TenantInsuranceProvider::query()->where('provider_type', 'albaraka')->firstOrFail();
    $wallet = $provider->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(2000, ['type' => 'test_provider_fund']);

    $this->get($state['baseUrl'].route('insurance.compulsory.search', [], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Tenant/Insurance/CompulsorySearch'));

    $priceResponse = $this->postJson($state['baseUrl'].route('insurance.compulsory.price', [], false), [
        'document_type_id' => 2,
        'duration_id' => 1,
        'seats' => 4,
        'payload' => 0,
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://tameen.webapi.ly/api/Compulsories/CheckPolicyPrices') {
            return false;
        }

        $data = $request->data();

        return ($data['ClientProfileVehicleId'] ?? null) === 2
            && ! array_key_exists('DocumentTypeId', $data);
    });

    $quoteToken = $priceResponse->json('quote_token');

    expect($quoteToken)->not->toBeEmpty();
    expect($priceResponse->json('provider_source.source_type'))->toBe('own')
        ->and($priceResponse->json('provider_source.provider_selector'))->toBe("own:{$provider->id}")
        ->and($priceResponse->json('provider_source'))->not->toHaveKey('credentials');

    $this->get($state['baseUrl'].route('insurance.compulsory.beneficiary', ['quoteToken' => $quoteToken], false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Tenant/Insurance/CompulsoryBeneficiary'));

    $issueResponse = $this->post($state['baseUrl'].route('insurance.compulsory.issue', [], false), [
        'quote_token' => $quoteToken,
        'policy_date_from' => now()->toDateTimeString(),
        'beneficiary_name' => 'Ali Ben Salem',
        'beneficiary_phone' => '0911111111',
        'beneficiary_address' => 'Tripoli',
        'beneficiary_email' => 'ali@example.com',
        'vehicle_type_id' => 3,
        'vehicle_color_id' => 4,
        'vehicle_licensing_authority_id' => 5,
        'vehicle_manufacture_year' => 2022,
        'vehicle_chassis_number' => 'CHASSIS-123',
        'vehicle_plate_number' => 'TR-4567',
        'vehicle_type_engine_power' => 1800,
    ]);

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://tameen.webapi.ly/api/ClientProfileVehicles/Post') {
            return false;
        }

        $data = $request->data();

        return ($data['Name'] ?? null) === 'Ali Ben Salem'
            && ($data['Address'] ?? null) === 'Tripoli'
            && ($data['PriceDetailID'] ?? null) === 14;
    });

    $order = Order::query()->latest('created_at')->first();

    expect($order)->not->toBeNull();

    $issueResponse->assertRedirect(route('insurance.compulsory.issued', $order, false));

    $this->get($state['baseUrl'].route('insurance.compulsory.issued', $order, false))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Tenant/Insurance/CompulsoryIssued'));

    $item = OrderItem::query()->where('order_id', $order->id)->first();

    expect($item)->not->toBeNull()
        ->and((string) $item->product_type)->toBe('insurance')
        ->and((string) $item->product_subtype)->toBe('compulsory')
        ->and((float) $item->commission_amount)->toBe(7.0)
        ->and((int) $item->ledger_entry_id)->toBe(777)
        ->and($item->wallet_transaction_id)->not->toBeNull();

    $wallet->refresh();

    expect(round((float) $wallet->balanceFloat, 2))->toBe(1850.0);

    $walletTransaction = WalletTransaction::query()
        ->where('uuid', (string) $item->wallet_transaction_id)
        ->first();

    expect($walletTransaction)->not->toBeNull()
        ->and((int) $walletTransaction->wallet_id)->toBe((int) $wallet->id)
        ->and(data_get($walletTransaction->meta, 'type'))->toBe('provider_issuance_cost')
        ->and(data_get($walletTransaction->meta, 'provider_type'))->toBe('insurance')
        ->and(data_get($walletTransaction->meta, 'provider_source_type'))->toBe('own');
});

test('insurance order creation preserves agency network source metadata for wallet traceability', function () {
    global $state;

    $this->actingAs($state['admin']);

    $provider = TenantInsuranceProvider::query()->where('provider_type', 'albaraka')->firstOrFail();
    $providerSource = [
        'source_type' => 'agency_network',
        'provider_selector' => 'agency_network:987',
        'source_agency_tenant_id' => 'agency-source-001',
        'merchant_tenant_id' => $state['tenant']->id,
        'network_membership_id' => 654,
        'provider_allocation_id' => 987,
        'source_provider_model' => TenantInsuranceProvider::class,
        'source_provider_id' => $provider->id,
    ];

    $order = app(CreateOrderFromInsuranceBooking::class)->createFromPolicyDetails(
        userId: $state['admin']->id,
        productSubtype: 'compulsory',
        policyDetails: [
            'policy_number' => 'POL-NET-001',
            'report_reference' => 'REP-NET-001',
            'total_amount' => 150,
            'net_amount' => 140,
            'tax_amount' => 10,
            'currency' => 'LYD',
            'raw' => ['Code' => 200],
        ],
        beneficiaryData: [
            'name' => 'Network Beneficiary',
            'phone' => '0911111111',
            'address' => 'Tripoli',
        ],
        requestPayload: [
            'provider_source' => $providerSource,
        ],
        insuranceProvider: $provider,
        processAgencyWallet: false,
    );

    $item = $order->items->first();

    expect($item)->not->toBeNull()
        ->and(data_get($item->item_details, 'financial_source'))->toBe('agency_network_supply')
        ->and(data_get($item->item_details, 'provider_source_type'))->toBe('agency_network')
        ->and(data_get($item->item_details, 'provider_selector'))->toBe('agency_network:987')
        ->and(data_get($item->item_details, 'source_agency_tenant_id'))->toBe('agency-source-001')
        ->and(data_get($item->item_details, 'merchant_tenant_id'))->toBe($state['tenant']->id)
        ->and(data_get($item->item_details, 'network_membership_id'))->toBe(654)
        ->and(data_get($item->item_details, 'provider_allocation_id'))->toBe(987);

    $wallet = $provider->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(500, ['type' => 'test_provider_fund']);

    app(\App\Actions\Finance\ProcessInsuranceProviderWalletTransactions::class)->execute($order, $provider);

    $item->refresh();

    $walletTransaction = WalletTransaction::query()
        ->where('uuid', (string) data_get($item->item_details, 'provider_wallet_transaction_id'))
        ->first();

    expect($walletTransaction)->not->toBeNull()
        ->and(data_get($walletTransaction->meta, 'provider_source_type'))->toBe('agency_network')
        ->and(data_get($walletTransaction->meta, 'source_agency_tenant_id'))->toBe('agency-source-001')
        ->and(data_get($walletTransaction->meta, 'merchant_tenant_id'))->toBe($state['tenant']->id)
        ->and(data_get($walletTransaction->meta, 'network_membership_id'))->toBe(654)
        ->and(data_get($walletTransaction->meta, 'provider_allocation_id'))->toBe(987);
});

test('merchant agency wallet deposit funds a wallet scoped to the joined agency', function () {
    global $state;

    $agency = Tenant::create([
        'id' => 'wallet-agency-'.Str::random(4),
        'company_name' => 'Wallet Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $membership = NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $state['tenant']->id,
        'status' => NetworkMembership::StatusActive,
        'accepted_at' => now(),
    ]);

    app(MerchantAgencyWalletManager::class)->depositForMembership($membership, 250, 'LYD', [
        'reference_number' => 'DEP-001',
        'notes' => 'Cash deposit confirmed by agency.',
    ]);

    $wallet = app(MerchantAgencyWalletManager::class)->getOrCreateWalletForMembership($membership, 'LYD');

    expect(round((float) $wallet->balanceFloat, 2))->toBe(250.0)
        ->and($wallet->meta['type'])->toBe('merchant_agency_network_wallet')
        ->and($wallet->meta['source_agency_tenant_id'])->toBe($agency->id)
        ->and($wallet->meta['network_membership_id'])->toBe($membership->id);

    $transaction = WalletTransaction::query()
        ->where('wallet_id', $wallet->id)
        ->first();

    expect($transaction)->not->toBeNull()
        ->and(data_get($transaction->meta, 'type'))->toBe('merchant_agency_wallet_deposit')
        ->and(data_get($transaction->meta, 'source_agency_tenant_id'))->toBe($agency->id)
        ->and(data_get($transaction->meta, 'merchant_tenant_id'))->toBe($state['tenant']->id)
        ->and(data_get($transaction->meta, 'reference_number'))->toBe('DEP-001');
});

test('merchant agency network insurance issuance deducts merchant agency wallet and agency provider wallet', function () {
    global $state;

    $agency = Tenant::create([
        'id' => 'dual-wallet-agency-'.Str::random(4),
        'company_name' => 'Dual Wallet Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    tenancy()->initialize($agency);
    $agencyProvider = TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Agency Al Baraka',
        'credentials' => [
            'base_url' => 'https://agency-albaraka.test',
            'token' => 'agency-token',
        ],
        'is_active' => true,
        'commission_compulsory' => 5,
    ]);
    $agencyProviderWallet = $agencyProvider->getOrCreateCurrencyWallet('LYD');
    $agencyProviderWallet->depositFloat(500, ['type' => 'test_provider_fund']);
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
        'provider_identity' => 'ALBARAKA',
        'source_provider_model' => TenantInsuranceProvider::class,
        'source_provider_id' => $agencyProvider->id,
        'status' => ProviderAllocation::StatusActive,
        'is_offered_by_agency' => true,
        'is_enabled_by_merchant' => true,
        'enabled_at' => now(),
    ]);

    tenancy()->initialize($state['tenant']);
    app(MerchantAgencyWalletManager::class)->depositForMembership($membership, 250, 'LYD', [
        'reference_number' => 'MER-DEP-001',
    ]);

    Http::fake([
        'https://agency-albaraka.test/api/Compulsories/CheckPolicyPrices' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                'TotalPremium' => 150,
                'NetPremium' => 140,
                'Taxes' => 10,
                'Curr' => 'LYD',
                'PriceDetailID' => 14,
            ],
        ], 200),
        'https://agency-albaraka.test/api/ClientProfiles/GetByPhone*' => Http::response([
            'Code' => 500,
            'Statues' => false,
            'Messages' => 'Client profile not found',
            'data' => null,
        ], 200),
        'https://agency-albaraka.test/api/ClientProfiles/Post' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => ['Id' => 101],
        ], 200),
        'https://agency-albaraka.test/api/ClientProfileVehicles/Post' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => ['Id' => 202],
        ], 200),
        'https://agency-albaraka.test/api/Compulsories/Post' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'policyNumber' => 'POL303',
            'data' => ['Id' => 303],
        ], 200),
        'https://agency-albaraka.test/api/Compulsories/Get/303' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                'Id' => 303,
                'PolicyNumber' => 'POL303',
                'EncryptedId' => 'ENC303',
                'TotalPremium' => 150,
                'NetPremium' => 140,
                'Taxes' => 10,
                'Curr' => 'LYD',
            ],
        ], 200),
    ]);

    $this->actingAs($state['admin']);

    $priceResponse = $this->postJson($state['baseUrl'].route('insurance.compulsory.price', [], false), [
        'document_type_id' => 2,
        'duration_id' => 1,
        'seats' => 4,
        'payload' => 0,
    ])->assertSuccessful();

    expect($priceResponse->json('provider_source.source_type'))->toBe('agency_network')
        ->and($priceResponse->json('provider_source.provider_selector'))->toBe("agency_network:{$allocation->id}");

    $issueResponse = $this->post($state['baseUrl'].route('insurance.compulsory.issue', [], false), [
        'quote_token' => (string) $priceResponse->json('quote_token'),
        'policy_date_from' => now()->toDateTimeString(),
        'beneficiary_name' => 'Merchant Customer',
        'beneficiary_phone' => '0911111111',
        'beneficiary_address' => 'Tripoli',
        'beneficiary_email' => 'merchant-customer@example.com',
        'vehicle_type_id' => 3,
        'vehicle_color_id' => 4,
        'vehicle_licensing_authority_id' => 5,
        'vehicle_manufacture_year' => 2022,
        'vehicle_chassis_number' => 'CHASSIS-NET-123',
        'vehicle_plate_number' => 'TR-NET-4567',
        'vehicle_type_engine_power' => 1800,
    ]);

    $order = Order::query()->latest('created_at')->firstOrFail();
    $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

    $issueResponse->assertRedirect(route('insurance.compulsory.issued', $order, false));

    expect(data_get($item->item_details, 'financial_source'))->toBe('agency_network_supply')
        ->and(data_get($item->item_details, 'provider_wallet_transaction_id'))->not->toBeNull()
        ->and($item->wallet_transaction_id)->not->toBeNull();

    $merchantWallet = app(MerchantAgencyWalletManager::class)->getOrCreateWalletForMembership($membership, 'LYD');
    expect(round((float) $merchantWallet->balanceFloat, 2))->toBe(100.0);

    tenancy()->initialize($agency);
    $agencyProviderWallet = TenantInsuranceProvider::query()->findOrFail($agencyProvider->id)->getOrCreateCurrencyWallet('LYD');
    expect(round((float) $agencyProviderWallet->balanceFloat, 2))->toBe(350.0);
    tenancy()->initialize($state['tenant']);

    $merchantWithdrawal = WalletTransaction::query()->where('uuid', (string) $item->wallet_transaction_id)->first();
    expect($merchantWithdrawal)->not->toBeNull()
        ->and(data_get($merchantWithdrawal->meta, 'type'))->toBe('merchant_agency_wallet_issuance_payment')
        ->and(data_get($merchantWithdrawal->meta, 'source_agency_tenant_id'))->toBe($agency->id)
        ->and(data_get($merchantWithdrawal->meta, 'provider_allocation_id'))->toBe($allocation->id);

    tenancy()->initialize($agency);
    $providerWithdrawal = WalletTransaction::query()->where('uuid', (string) data_get($item->item_details, 'provider_wallet_transaction_id'))->first();
    expect($providerWithdrawal)->not->toBeNull()
        ->and(data_get($providerWithdrawal->meta, 'provider_source_type'))->toBe('agency_network')
        ->and(data_get($providerWithdrawal->meta, 'provider_allocation_id'))->toBe($allocation->id);
    tenancy()->initialize($state['tenant']);
});

test('compulsory order creation does not deduct agency wallet when using provider wallet', function () {
    global $state;

    $this->actingAs($state['admin']);

    $wallet = $state['admin']->getOrCreateCurrencyWallet('LYD');
    expect(round((float) $wallet->balanceFloat, 2))->toBe(0.0);

    $provider = TenantInsuranceProvider::query()->where('provider_type', 'albaraka')->firstOrFail();

    $order = app(CreateOrderFromInsuranceBooking::class)->createFromPolicyDetails(
        userId: $state['admin']->id,
        productSubtype: 'compulsory',
        policyDetails: [
            'policy_number' => 'POL-ZERO-001',
            'report_reference' => 'REP-ZERO-001',
            'total_amount' => 150,
            'net_amount' => 140,
            'tax_amount' => 10,
            'currency' => 'LYD',
            'raw' => ['Code' => 200],
        ],
        beneficiaryData: [
            'name' => 'Zero Wallet Beneficiary',
            'phone' => '0911111111',
            'address' => 'Tripoli',
        ],
        requestPayload: [],
        insuranceProvider: $provider,
        processAgencyWallet: false,
    );

    $item = $order->items->first();

    expect($item)->not->toBeNull()
        ->and($item->wallet_transaction_id)->toBeNull()
        ->and($item->ledger_entry_id)->toBeNull();

    $wallet->refresh();

    expect(round((float) $wallet->balanceFloat, 2))->toBe(0.0);
});

test('compulsory issue fails early when provider wallet is insufficient and does not call issuance endpoints', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Compulsories/CheckPolicyPrices' => Http::response([
            'Code' => 200,
            'Statues' => true,
            'data' => [
                'TotalPremium' => 150,
                'NetPremium' => 140,
                'Taxes' => 10,
                'Curr' => 'LYD',
                'PriceDetailID' => 14,
            ],
        ], 200),
        'https://tameen.webapi.ly/*' => Http::response([
            'Code' => 500,
            'Statues' => false,
            'Messages' => 'Should not be called in insufficient wallet flow',
        ], 500),
    ]);

    $this->actingAs($state['admin']);

    $priceResponse = $this->postJson($state['baseUrl'].route('insurance.compulsory.price', [], false), [
        'document_type_id' => 2,
        'duration_id' => 1,
        'seats' => 4,
        'payload' => 0,
    ])->assertSuccessful();

    $quoteToken = (string) $priceResponse->json('quote_token');

    $this->post($state['baseUrl'].route('insurance.compulsory.issue', [], false), [
        'quote_token' => $quoteToken,
        'policy_date_from' => now()->toDateTimeString(),
        'beneficiary_name' => 'Ali Ben Salem',
        'beneficiary_phone' => '0911111111',
        'beneficiary_address' => 'Tripoli',
        'beneficiary_email' => 'ali@example.com',
        'vehicle_type_id' => 3,
        'vehicle_color_id' => 4,
        'vehicle_licensing_authority_id' => 5,
        'vehicle_manufacture_year' => 2022,
        'vehicle_chassis_number' => 'CHASSIS-123',
        'vehicle_plate_number' => 'TR-4567',
        'vehicle_type_engine_power' => 1800,
    ])->assertRedirect()->assertSessionHas('error');

    expect(Order::query()->exists())->toBeFalse();

    Http::assertNotSent(function ($request): bool {
        return str_contains($request->url(), '/api/ClientProfiles/GetByPhone')
            || str_contains($request->url(), '/api/ClientProfiles/Post')
            || str_contains($request->url(), '/api/ClientProfileVehicles/Post')
            || str_contains($request->url(), '/api/Compulsories/Post');
    });
});
