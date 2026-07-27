<?php

use App\Actions\Finance\CreateOrderFromInsuranceBooking;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-ins-pdf-'.Str::random(4),
        'company_name' => 'API Insurance PDF Agency',
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
        'commission_orange' => 5,
    ]);

    $state['tenant'] = $tenant;
    $state['user'] = $user;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $state['token'] = $user->createToken('Test Device', ['read'])->plainTextToken;
    $state['provider'] = TenantInsuranceProvider::query()->where('provider_type', 'albaraka')->firstOrFail();
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

function createApiCompulsoryInsuranceOrder(User $user, TenantInsuranceProvider $provider): Order
{
    $wallet = $user->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(2000, ['type' => 'test_fund']);

    return app(CreateOrderFromInsuranceBooking::class)->createFromPolicyDetails(
        userId: $user->id,
        productSubtype: 'compulsory',
        policyDetails: [
            'policy_id' => 58737,
            'policy_number' => 'CMP-58737',
            'report_reference' => 'ENC-API-001',
            'total_amount' => 150,
            'net_amount' => 140,
            'tax_amount' => 10,
            'currency' => 'LYD',
            'raw' => ['Code' => 200],
        ],
        beneficiaryData: [
            'name' => 'API Print Client',
            'phone' => '0911111111',
            'address' => 'Tripoli',
        ],
        requestPayload: [],
        insuranceProvider: $provider,
    );
}

test('api insurance policy pdf returns al baraka compulsory report', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Compulsories/GetReportById?EncryptedId=ENC-API-001' => Http::response('%PDF-1.4 compulsory-pdf', 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $order = createApiCompulsoryInsuranceOrder($state['user'], $state['provider']);
    $item = $order->items->firstOrFail();

    $this->withToken($state['token'])
        ->get($state['apiUrl'].'/orders/'.$order->id.'/insurance-items/'.$item->id.'/policy-pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertSee('%PDF-1.4 compulsory-pdf', false);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://tameen.webapi.ly/api/Compulsories/GetReportById?EncryptedId=ENC-API-001';
    });
});

test('api insurance policy pdf falls back to policy id when encrypted id fails', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Compulsories/GetReportById?EncryptedId=ENC-API-001' => Http::response('Server error', 500),
        'https://tameen.webapi.ly/api/Compulsories/GetReportById?EncryptedId=58737' => Http::response('%PDF-1.4 fallback-pdf', 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $order = createApiCompulsoryInsuranceOrder($state['user'], $state['provider']);
    $item = $order->items->firstOrFail();

    $this->withToken($state['token'])
        ->get($state['apiUrl'].'/orders/'.$order->id.'/insurance-items/'.$item->id.'/policy-pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertSee('%PDF-1.4 fallback-pdf', false);
});

test('api insurance policy pdf returns travel report by encrypted reference', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Travelers/GetReportById?EncryptedId=ENC-TRV-API' => Http::response('%PDF-1.4 travel-pdf', 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $order = app(CreateOrderFromInsuranceBooking::class)->createFromTravelPolicies(
        userId: $state['user']->id,
        clientProfileData: [
            'name' => 'Travel API Client',
            'phone' => '0911111111',
            'address' => 'Tripoli',
            'email' => 'travel-api@example.com',
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
                    'report_reference' => 'ENC-TRV-API',
                    'zone_id' => 4,
                    'duration_id' => 12,
                    'policy_date_from' => '2026-04-09',
                    'policy_date_to' => '2026-04-13',
                    'raw' => ['data' => ['Id' => 81001, 'EncryptedId' => 'ENC-TRV-API']],
                ],
                'net_amount' => 90,
                'total_amount' => 100,
                'tax_amount' => 10,
                'currency' => 'LYD',
            ],
        ],
        insuranceProvider: $state['provider'],
        processAgencyWallet: false,
    );

    $item = $order->items->firstOrFail();

    $this->withToken($state['token'])
        ->get($state['apiUrl'].'/orders/'.$order->id.'/insurance-items/'.$item->id.'/policy-pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertSee('%PDF-1.4 travel-pdf', false);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://tameen.webapi.ly/api/Travelers/GetReportById?EncryptedId=ENC-TRV-API';
    });
});

test('api insurance policy pdf returns orange report by card number', function () {
    global $state;

    Http::fake([
        'https://tameen.webapi.ly/api/Oranges/GetReportById?CardNumber=ORG-CARD-001' => Http::response('%PDF-1.4 orange-pdf', 200, [
            'Content-Type' => 'application/pdf',
        ]),
    ]);

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $state['user']->id,
        'number' => 'ORD-ORG-001',
        'status' => 'confirmed',
        'issued_at' => now(),
        'subtotal' => 200,
        'tax_total' => 0,
        'grand_total' => 200,
        'amount_paid' => 200,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'payment_reference' => 'ORG-CARD-001',
        'contact' => ['name' => 'Orange Client'],
    ]);

    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'type' => 'insurance',
        'product_type' => 'insurance',
        'product_subtype' => 'orange',
        'provider' => 'albaraka',
        'provider_reference' => 'ORG-CARD-001',
        'ticket_number' => 'ORG-CARD-001',
        'item_details' => [
            'insurance' => [
                'report_reference' => 'ORG-CARD-001',
            ],
        ],
        'product_details' => [
            'policy_details' => [
                'policy_id' => 9001,
                'report_reference' => 'ORG-CARD-001',
            ],
        ],
        'status' => 'confirmed',
        'price' => 200,
        'total' => 200,
        'total_amount' => 200,
        'currency' => 'LYD',
    ]);

    $this->withToken($state['token'])
        ->get($state['apiUrl'].'/orders/'.$order->id.'/insurance-items/'.$item->id.'/policy-pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertSee('%PDF-1.4 orange-pdf', false);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://tameen.webapi.ly/api/Oranges/GetReportById?CardNumber=ORG-CARD-001';
    });
});

test('api insurance policy pdf returns 422 when no printable reference exists', function () {
    global $state;

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $state['user']->id,
        'number' => 'ORD-NO-REF',
        'status' => 'confirmed',
        'issued_at' => now(),
        'subtotal' => 100,
        'tax_total' => 0,
        'grand_total' => 100,
        'amount_paid' => 100,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'contact' => ['name' => 'No Ref Client'],
    ]);

    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'type' => 'insurance',
        'product_type' => 'insurance',
        'product_subtype' => 'compulsory',
        'provider' => 'albaraka',
        'provider_reference' => null,
        'item_details' => [],
        'product_details' => [],
        'status' => 'confirmed',
        'price' => 100,
        'total' => 100,
        'total_amount' => 100,
        'currency' => 'LYD',
    ]);

    $this->withToken($state['token'])
        ->get($state['apiUrl'].'/orders/'.$order->id.'/insurance-items/'.$item->id.'/policy-pdf')
        ->assertStatus(422);
});

test('api order show includes insurance report reference', function () {
    global $state;

    $order = createApiCompulsoryInsuranceOrder($state['user'], $state['provider']);

    $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/orders/'.$order->id)
        ->assertOk()
        ->assertJsonPath('data.order.items.0.report_reference', 'ENC-API-001');
});
