<?php

use App\Actions\Insurance\FetchInsurancePolicyReport;
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
        'id' => 'orange-pdf-'.Str::random(4),
        'company_name' => 'Orange PDF Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Al Baraka Insurance',
        'credentials' => [
            'base_url' => 'https://tameen.webapi.ly',
            'token' => 'test-token',
        ],
        'is_active' => true,
        'commission_orange' => 8,
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'C0C00027N',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 60,
        'tax_total' => 0,
        'grand_total' => 60,
        'amount_paid' => 60,
        'currency' => 'LYD',
        'payment_method' => 'provider_wallet',
        'contact' => ['name' => 'rayan fathi'],
    ]);

    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'type' => 'insurance',
        'product_type' => 'insurance',
        'product_subtype' => 'orange',
        'provider' => 'albaraka',
        'provider_reference' => 'LBY/6884971',
        'ticket_number' => 'LBY/6884971',
        'item_details' => [
            'insurance' => [
                'report_reference' => 'LBY/6884971',
                'provider_response' => [
                    'Id' => 71223,
                    'EncryptedId' => 'ENC-ORANGE-71223',
                    'CardNumber' => 'LBY/6884971',
                ],
            ],
        ],
        'product_details' => [
            'policy_details' => [
                'policy_id' => 71223,
                'policy_number' => 'LBY/6884971',
                'card_number' => 'LBY/6884971',
                'report_reference' => 'ENC-ORANGE-71223',
            ],
        ],
        'status' => 'issued',
        'price' => 60,
        'total' => 60,
        'total_amount' => 60,
        'currency' => 'LYD',
    ]);

    $state['tenant'] = $tenant;
    $state['user'] = $user;
    $state['order'] = $order;
    $state['item'] = $item;
    $state['baseUrl'] = 'http://'.$tenant->domains->first()->domain;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('orange print skips card-number-only json error and succeeds with id plus card number', function () {
    global $state;

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (! str_contains($url, '/api/Oranges/GetReportById')) {
            return Http::response('unexpected', 500);
        }

        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

        // Current production failure mode: CardNumber alone returns HTTP 200 JSON.
        if (($query['CardNumber'] ?? null) === 'LBY/6884971' && ! isset($query['Id']) && ! isset($query['EncryptedId'])) {
            return Http::response(
                '{"code":0,"status":false,"message":"Certificate number doesnot exists or it doesnot exists under the user signed in."}',
                200,
                ['Content-Type' => 'application/pdf'],
            );
        }

        // Working request shape confirmed with Al Baraka guidance: Id + CardNumber.
        if (($query['Id'] ?? null) === '71223' && ($query['CardNumber'] ?? null) === 'LBY/6884971') {
            return Http::response('%PDF-1.4 orange-ok', 200, ['Content-Type' => 'application/pdf']);
        }

        return Http::response('{"code":0,"status":false,"message":"not this variant"}', 200, [
            'Content-Type' => 'application/pdf',
        ]);
    });

    $report = app(FetchInsurancePolicyReport::class)->execute($state['item']);

    expect($report['content'])->toStartWith('%PDF-1.4 orange-ok')
        ->and($report['content_type'])->toBe('application/pdf');

    Http::assertSent(function (Request $request): bool {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return str_contains($request->url(), '/api/Oranges/GetReportById')
            && ($query['Id'] ?? null) === '71223'
            && ($query['CardNumber'] ?? null) === 'LBY/6884971';
    });
});

test('orange print rejects non-pdf bodies even when http status is 200', function () {
    global $state;

    $state['item']->update([
        'product_details' => [
            'policy_details' => [
                'policy_number' => 'LBY/6884971',
                'card_number' => 'LBY/6884971',
                'report_reference' => 'LBY/6884971',
            ],
        ],
        'item_details' => [
            'insurance' => [
                'report_reference' => 'LBY/6884971',
            ],
        ],
        'provider_reference' => 'LBY/6884971',
        'ticket_number' => 'LBY/6884971',
    ]);

    Http::fake([
        'https://tameen.webapi.ly/api/Oranges/GetReportById*' => Http::response(
            '{"code":0,"status":false,"message":"Certificate number doesnot exists or it doesnot exists under the user signed in."}',
            200,
            ['Content-Type' => 'application/pdf'],
        ),
    ]);

    expect(fn () => app(FetchInsurancePolicyReport::class)->execute($state['item']->fresh()))
        ->toThrow(\App\Services\Insurance\InsuranceApiException::class);
});

test('tenant orange item report route returns pdf when id and card number succeed', function () {
    global $state;

    Http::fake(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        if (($query['Id'] ?? null) === '71223' && ($query['CardNumber'] ?? null) === 'LBY/6884971') {
            return Http::response('%PDF-1.4 route-ok', 200, ['Content-Type' => 'application/pdf']);
        }

        return Http::response('{"code":0,"status":false,"message":"Certificate number doesnot exists"}', 200, [
            'Content-Type' => 'application/pdf',
        ]);
    });

    $this->actingAs($state['user'])
        ->get($state['baseUrl'].route('insurance.order-items.report', [
            'order' => $state['order']->id,
            'item' => $state['item']->id,
        ], false))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertSee('%PDF-1.4 route-ok', false);
});
