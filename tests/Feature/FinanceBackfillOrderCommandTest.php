<?php

use App\Models\Tenant;
use App\Models\Tenant\AgencySetting;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;

test('finance:backfill-order fills financial source commission and wallet links', function () {
    $tenant = Tenant::create([
        'id' => 'backfill-'.Str::random(4),
        'company_name' => 'Backfill Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $issuer = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'Default',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    AgencySetting::current()->update([
        'can_use_own_airline_credentials' => false,
        'master_commission_percent' => 10,
    ]);

    $wallet = $issuer->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(300.00, ['type' => 'initial_fund']);

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $issuer->id,
        'number' => 'BACKF001AA',
        'status' => 'confirmed',
        'issued_at' => now(),
        'subtotal' => 123.45,
        'tax_total' => 0,
        'grand_total' => 123.45,
        'amount_paid' => 123.45,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
        'payment_reference' => 'BACKPNR',
    ]);

    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'ticket',
        'product_subtype' => 'oneway',
        'provider' => 'videcom',
        'provider_reference' => 'BACKPNR',
        'item_details' => [
            'airline_code' => 'YI',
            'segments' => [[
                'departure_airport' => 'MJI',
                'arrival_airport' => 'IST',
            ]],
        ],
        'product_details' => [],
        'net_fare' => 100.00,
        'price' => 123.45,
        'taxes' => [],
        'total_tax' => 0,
        'total' => 123.45,
        'total_amount' => 123.45,
        'currency' => 'LYD',
        'status' => 'confirmed',
        'paid' => 123.45,
        'remaining' => 0,
    ]);

    $this->artisan('finance:backfill-order', [
        'identifier' => (string) $order->id,
        '--skip-ledger' => true,
    ])->assertSuccessful();

    $item = $item->fresh();
    $order = $order->fresh();
    $wallet->refresh();

    expect(data_get($item->item_details, 'financial_source'))->toBe('master_agency_supply')
        ->and((float) $item->commission_amount)->toBe(0.0)
        ->and((float) $item->agent_commission)->toBe(10.0)
        ->and($item->wallet_transaction_id)->not->toBeNull()
        ->and((string) $order->payment_method)->toBe('default_agency_supply');

    expect((float) $wallet->balanceFloat)->toBe(176.55);

    tenancy()->end();
});
