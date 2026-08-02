<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\TenantEsimProvider;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'esim-wh-'.Str::random(4),
        'company_name' => 'eSIM Webhook Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    tenancy()->initialize($tenant);

    User::factory()->create([
        'email' => 'agent@example.com',
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

    $state['tenant'] = $tenant;
    $state['webhookUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1/webhooks/l2-esim';
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

function l2WebhookSignature(string $rawBody, string $apiKey = 'test-api-key'): string
{
    return base64_encode(hash_hmac('sha256', $rawBody, $apiKey, true));
}

test('l2 esim webhook records utilisation on matching order item', function () {
    global $state;

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => User::query()->first()->id,
        'number' => 'ORD-ESIM-WH-1',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 12.5,
        'tax_total' => 0,
        'grand_total' => 12.5,
        'amount_paid' => 12.5,
        'currency' => 'USD',
        'payment_method' => 'wallet',
        'payment_reference' => 'L2-1',
        'contact' => ['full_name' => 'Test', 'email' => 't@example.com'],
    ]);

    $item = $order->items()->create([
        'type' => 'esim',
        'product_type' => 'esim',
        'product_subtype' => 'esim',
        'provider' => 'l2',
        'provider_reference' => 'L2-1',
        'ticket_number' => '8944538532008160222',
        'item_details' => [
            'iccid' => '8944538532008160222',
            'country' => 'LY',
            'package_id' => 'esim_1GB_30D_LY_U',
        ],
        'product_details' => [
            'iccid' => '8944538532008160222',
            'country' => 'LY',
        ],
        'price' => 12.5,
        'net_fare' => 12.5,
        'taxes' => [],
        'total_tax' => 0,
        'total' => 12.5,
        'total_amount' => 12.5,
        'currency' => 'USD',
        'exchange_rate' => 1,
        'status' => 'issued',
        'transaction_type' => 'purchase',
        'commission_percent' => 0,
        'commission_amount' => 0,
        'net_after_commission' => 12.5,
        'agent_commission' => 0,
        'net_commission' => 0,
        'paid' => 12.5,
        'remaining' => 0,
    ]);

    $payload = [
        'iccid' => '8944538532008160222',
        'alertType' => 'Utilisation',
        'bundle' => [
            'id' => '215009266',
            'reference' => 'bundle-ref-1',
            'name' => 'esim_1GB_30D_LY_U',
            'description' => 'Libya 1GB',
            'initialQuantity' => 1073741824,
            'remainingQuantity' => 536870912,
            'unit' => 'BYTES',
            'startTime' => '2026-06-17T11:00:00Z',
            'endTime' => '2026-07-17T11:00:00Z',
            'unlimited' => false,
        ],
    ];

    $raw = json_encode($payload);

    $response = test()->call(
        'POST',
        $state['webhookUrl'],
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HMAC_SIGNATURE' => l2WebhookSignature($raw),
        ],
        $raw,
    );

    expect($response->getStatusCode())->toBe(200);

    $item->refresh();

    expect(data_get($item->item_details, 'usage.remaining_mb'))->toEqual(512)
        ->and(data_get($item->item_details, 'usage.percent_used'))->toEqual(50)
        ->and(data_get($item->item_details, 'usage.bundle_reference'))->toBe('bundle-ref-1');
});

test('l2 esim webhook rejects invalid hmac signature', function () {
    global $state;

    $raw = json_encode(['iccid' => 'x', 'alertType' => 'Utilisation', 'bundle' => []]);

    $response = test()->call(
        'POST',
        $state['webhookUrl'],
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HMAC_SIGNATURE' => 'invalid',
        ],
        $raw,
    );

    expect($response->getStatusCode())->toBe(401);
});
