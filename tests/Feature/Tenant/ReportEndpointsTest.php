<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'report-test-'.Str::random(4),
        'company_name' => 'Report Test Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    $state['tenant'] = $tenant;

    tenancy()->initialize($tenant);

    $state['admin'] = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $state['manager'] = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $state['agent'] = User::factory()->create([
        'role' => 'agent',
        'is_active' => true,
    ]);

    $state['provider'] = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'Default',
        'is_active' => true,
        'domestic_commission_rate' => 5,
        'international_commission_rate' => 10,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

function seedIssuedOrderItems(User $user, int $count = 3): void
{
    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'ORD-'.Str::upper(Str::random(8)),
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 350 * $count,
        'tax_total' => 50 * $count,
        'grand_total' => 400 * $count,
        'amount_paid' => 400 * $count,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'payment_reference' => 'RPT001',
        'contact' => ['email' => 'john@example.com'],
    ]);

    for ($i = 0; $i < $count; $i++) {
        OrderItem::create([
            'order_id' => $order->id,
            'type' => 'flight',
            'product_type' => 'ticket',
            'product_subtype' => 'economy',
            'provider' => 'videcom',
            'provider_reference' => 'RPT001',
            'ticket_number' => '60712345678'.($i + 1),
            'item_details' => [
                'airline_code' => 'YI',
                'rloc' => 'RPT001',
                'financial_source' => 'master_agency_supply',
                'passenger_name' => 'PASSENGER '.($i + 1),
            ],
            'price' => 300,
            'net_fare' => 300,
            'taxes' => [
                ['code' => 'ST', 'type' => 'total', 'amount' => 30, 'currency' => 'LYD'],
                ['code' => 'YQ', 'type' => 'fuel', 'amount' => 20, 'currency' => 'LYD'],
            ],
            'total_tax' => 50,
            'total' => 350,
            'total_amount' => 350,
            'currency' => 'LYD',
            'status' => 'issued',
            'commission_percent' => 10,
            'commission_amount' => 30,
            'net_after_commission' => 270,
            'agent_commission' => 30,
            'net_commission' => 30,
            'paid' => 350,
            'remaining' => 0,
        ]);
    }
}

test('daily sales report returns sales grouped by date and product type', function () {
    global $state;

    seedIssuedOrderItems($state['manager'], 2);

    $this->actingAs($state['manager']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get($baseUrl.route('reports.sales', [], false));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/DailySales')
            ->has('rows')
            ->has('grandTotals')
            ->has('filters.start_date')
            ->has('filters.end_date')
        );
});

test('daily sales report respects date filters', function () {
    global $state;

    seedIssuedOrderItems($state['manager'], 1);

    $this->actingAs($state['manager']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get($baseUrl.route('reports.sales', [
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
    ], false));

    $response->assertSuccessful();

    $rows = $response->inertiaPage()['props']['rows'];
    expect($rows)->not->toBeEmpty();
});

test('daily sales report returns empty for far-future date range', function () {
    global $state;

    seedIssuedOrderItems($state['manager'], 1);

    $this->actingAs($state['manager']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get($baseUrl.route('reports.sales', [
        'start_date' => '2030-01-01',
        'end_date' => '2030-12-31',
    ], false));

    $response->assertSuccessful();

    $rows = $response->inertiaPage()['props']['rows'];
    expect($rows)->toBeEmpty();
});

test('commission report returns items with commission breakdown', function () {
    global $state;

    seedIssuedOrderItems($state['manager'], 2);

    $this->actingAs($state['manager']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get($baseUrl.route('reports.commissions', [], false));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/Commissions')
            ->has('items')
            ->has('summary')
            ->has('filters')
        );
});

test('commission report summary groups by supply type', function () {
    global $state;

    seedIssuedOrderItems($state['manager'], 2);

    $this->actingAs($state['manager']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get($baseUrl.route('reports.commissions', [], false));

    $summary = $response->inertiaPage()['props']['summary'];
    expect($summary)->not->toBeEmpty();

    $masterSupply = collect($summary)->firstWhere('supply_type', 'master_agency_supply');
    expect($masterSupply)->not->toBeNull()
        ->and((float) $masterSupply['total_commission'])->toBeGreaterThan(0);
});

test('tax breakdown report returns tax aggregation by code', function () {
    global $state;

    seedIssuedOrderItems($state['manager'], 2);

    $this->actingAs($state['manager']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get($baseUrl.route('reports.taxes', [], false));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/Taxes')
            ->has('items')
            ->has('taxBreakdown')
            ->has('filters')
        );

    $breakdown = $response->inertiaPage()['props']['taxBreakdown'];
    expect($breakdown)->not->toBeEmpty();

    $stTax = collect($breakdown)->firstWhere('code', 'ST');
    expect($stTax)->not->toBeNull()
        ->and((float) $stTax['total_amount'])->toBeGreaterThan(0);

    $yqTax = collect($breakdown)->firstWhere('code', 'YQ');
    expect($yqTax)->not->toBeNull()
        ->and((float) $yqTax['total_amount'])->toBeGreaterThan(0);
});

test('wallet transaction history returns transactions with filters', function () {
    global $state;

    // Fund wallet to create a deposit transaction.
    $wallet = $state['manager']->getOrCreateCurrencyWallet('LYD');
    $wallet->deposit(10000, ['type' => 'initial_fund', 'description' => 'Test deposit']);

    $this->actingAs($state['manager']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get($baseUrl.route('wallet.transactions', [], false));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/WalletTransactions')
            ->has('transactions')
            ->has('balanceSummary')
            ->has('filters')
        );
});

test('wallet transaction history filters by type', function () {
    global $state;

    $wallet = $state['manager']->getOrCreateCurrencyWallet('LYD');
    $wallet->deposit(10000, ['type' => 'initial_fund']);
    $wallet->withdraw(5000, ['type' => 'ticket_purchase']);

    $this->actingAs($state['manager']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get($baseUrl.route('wallet.transactions', ['type' => 'deposit'], false));

    $response->assertSuccessful();

    $transactions = $response->inertiaPage()['props']['transactions']['data'];
    foreach ($transactions as $tx) {
        expect($tx['type'])->toBe('deposit');
    }
});

test('wallet transaction history links to order when meta contains order_id', function () {
    global $state;

    seedIssuedOrderItems($state['manager'], 1);

    $wallet = $state['manager']->getOrCreateCurrencyWallet('LYD');
    $wallet->deposit(50000, ['type' => 'initial_fund']);
    $wallet->withdraw(35000, [
        'type' => 'ticket_purchase',
        'order_id' => Order::first()->id,
        'description' => 'Ticket for PASSENGER 1',
    ]);

    $this->actingAs($state['manager']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get($baseUrl.route('wallet.transactions', [], false));

    $transactions = $response->inertiaPage()['props']['transactions']['data'];
    $orderTx = collect($transactions)->firstWhere('description', 'Ticket for PASSENGER 1');
    expect($orderTx)->not->toBeNull()
        ->and($orderTx['order_id'])->not->toBeNull();
});

test('wallet transaction history includes airline provider wallet transactions', function () {
    global $state;

    $providerWallet = $state['provider']->getOrCreateCurrencyWallet('LYD');
    $providerWallet->depositFloat(1000, ['type' => 'seed_provider_balance']);
    $providerWallet->withdrawFloat(350, [
        'type' => 'provider_issuance_cost',
        'provider_type' => 'airline',
        'description' => 'Ticket provider withdrawal',
    ]);

    $this->actingAs($state['manager']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get($baseUrl.route('wallet.transactions', [], false));

    $transactions = $response->inertiaPage()['props']['transactions']['data'];
    $providerTx = collect($transactions)->firstWhere('description', 'Ticket provider withdrawal');

    expect($providerTx)->not->toBeNull()
        ->and($providerTx['wallet_holder_type'])->toBe('Airline Provider Wallet')
        ->and($providerTx['wallet_holder_name'])->toBe('Oya');
});

test('reconciliation report is accessible by admin only', function () {
    global $state;

    seedIssuedOrderItems($state['admin'], 1);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    // Admin can access.
    $this->actingAs($state['admin']);
    $this->get($baseUrl.route('reports.reconciliation', [], false))->assertSuccessful();

    // Manager cannot access.
    $this->actingAs($state['manager']);
    $this->get($baseUrl.route('reports.reconciliation', [], false))->assertForbidden();

    // Agent cannot access.
    $this->actingAs($state['agent']);
    $this->get($baseUrl.route('reports.reconciliation', [], false))->assertForbidden();
});

test('reconciliation report compares order totals against wallet and airline', function () {
    global $state;

    seedIssuedOrderItems($state['admin'], 2);

    $this->actingAs($state['admin']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $response = $this->get($baseUrl.route('reports.reconciliation', [], false));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/Reconciliation')
            ->has('reconciliationRows')
            ->has('filters')
        );

    $rows = $response->inertiaPage()['props']['reconciliationRows'];
    expect($rows)->not->toBeEmpty();

    $lydRow = collect($rows)->firstWhere('currency', 'LYD');
    expect($lydRow)->not->toBeNull()
        ->and((float) $lydRow['order_amount'])->toBeGreaterThan(0)
        ->and((float) $lydRow['order_commission'])->toBeGreaterThan(0);
});

test('report routes require authentication', function () {
    global $state;

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->get($baseUrl.route('reports.sales', [], false))->assertRedirect();
    $this->get($baseUrl.route('reports.commissions', [], false))->assertRedirect();
    $this->get($baseUrl.route('reports.taxes', [], false))->assertRedirect();
    $this->get($baseUrl.route('wallet.transactions', [], false))->assertRedirect();
});
