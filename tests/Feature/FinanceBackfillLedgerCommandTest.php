<?php

use App\Models\Tenant;
use App\Models\Tenant\AirlineAccount;
use App\Models\Tenant\AirlineTransaction;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\Finance\LedgerDriver;
use Illuminate\Support\Str;

test('finance:backfill-ledger links missing ledger entries for orders with wallet transactions', function () {
    $tenant = Tenant::create([
        'id' => 'ledger-backfill-'.Str::random(4),
        'company_name' => 'Ledger Backfill Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $owner = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $wallet = $owner->getOrCreateCurrencyWallet('LYD');
    $seedTransaction = $wallet->depositFloat(500.00, ['type' => 'seed']);

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $owner->id,
        'number' => 'LDBG001AA',
        'status' => 'confirmed',
        'issued_at' => now(),
        'subtotal' => 120,
        'tax_total' => 0,
        'grand_total' => 120,
        'amount_paid' => 120,
        'currency' => 'LYD',
        'payment_method' => 'default_agency_supply',
        'payment_reference' => 'LDBGPNR',
    ]);

    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'ticket',
        'product_subtype' => 'oneway',
        'provider' => 'videcom',
        'provider_reference' => 'LDBGPNR',
        'item_details' => [
            'financial_source' => 'master_agency_supply',
        ],
        'net_fare' => 120,
        'price' => 120,
        'taxes' => [],
        'total_tax' => 0,
        'total' => 120,
        'total_amount' => 120,
        'currency' => 'LYD',
        'status' => 'issued',
        'wallet_transaction_id' => $seedTransaction->uuid,
        'paid' => 120,
        'remaining' => 0,
    ]);

    $journalId = 7001;

    $this->app->bind(LedgerDriver::class, function () use (&$journalId) {
        return new class($journalId) implements LedgerDriver
        {
            public function __construct(private int $journalId) {}

            public function postOperationJournal(string $source, string $description, array $entries): int
            {
                return $this->journalId;
            }
        };
    });

    $this->artisan('finance:backfill-ledger', [
        'identifier' => (string) $order->id,
        '--skip-initialize' => true,
    ])->assertSuccessful();

    expect($item->fresh()->ledger_entry_id)->toBe(7001);

    tenancy()->end();
});

test('finance:backfill-ledger ignores legacy airline transactions without wallet transactions', function () {
    $tenant = Tenant::create([
        'id' => 'ledger-legacy-'.Str::random(4),
        'company_name' => 'Ledger Legacy Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $owner = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $provider = TenantProvider::query()->create([
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia',
        'account_name' => 'Default',
        'provider_type' => 'videcom',
        'is_active' => true,
        'credentials' => [],
    ]);

    $account = AirlineAccount::query()->create([
        'tenant_provider_id' => $provider->id,
        'currency' => 'LYD',
        'balance' => 0,
    ]);

    $legacyTransaction = AirlineTransaction::query()->create([
        'airline_account_id' => $account->id,
        'type' => 'ticket_cost',
        'amount' => -120,
        'balance_after' => -120,
    ]);

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $owner->id,
        'number' => 'LDBGLEGACY',
        'status' => 'confirmed',
        'issued_at' => now(),
        'subtotal' => 120,
        'tax_total' => 0,
        'grand_total' => 120,
        'amount_paid' => 120,
        'currency' => 'LYD',
        'payment_method' => 'own_credentials',
        'payment_reference' => 'LEGPNR',
    ]);

    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'ticket',
        'product_subtype' => 'oneway',
        'provider' => 'videcom',
        'provider_reference' => 'LEGPNR',
        'item_details' => [
            'financial_source' => 'own_credentials',
        ],
        'net_fare' => 120,
        'price' => 120,
        'taxes' => [],
        'total_tax' => 0,
        'total' => 120,
        'total_amount' => 120,
        'currency' => 'LYD',
        'status' => 'issued',
        'airline_transaction_id' => $legacyTransaction->id,
        'paid' => 120,
        'remaining' => 0,
    ]);

    $calls = 0;

    $this->app->bind(LedgerDriver::class, function () use (&$calls) {
        return new class($calls) implements LedgerDriver
        {
            public function __construct(private int &$calls) {}

            public function postOperationJournal(string $source, string $description, array $entries): int
            {
                $this->calls++;

                return 8001;
            }
        };
    });

    $this->artisan('finance:backfill-ledger', [
        'identifier' => (string) $order->id,
        '--skip-initialize' => true,
    ])->assertSuccessful();

    expect($item->fresh()->ledger_entry_id)->toBeNull()
        ->and($calls)->toBe(0);

    tenancy()->end();
});
