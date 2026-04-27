<?php

use App\Jobs\UpdateAirlineBalanceJob;
use App\Models\Tenant;
use App\Models\Tenant\AgencySetting;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'ticket-void-'.Str::random(4),
        'company_name' => 'Ticket Void Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    $state['tenant'] = $tenant;

    tenancy()->initialize($tenant);

    $state['user'] = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $state['provider'] = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'Default',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

function seedIssuedOrder(User $user, string $issueDate): array
{
    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'ORD-'.Str::upper(Str::random(8)),
        'status' => 'paid',
        'subtotal' => 500,
        'tax_total' => 0,
        'grand_total' => 500,
        'amount_paid' => 500,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'payment_reference' => 'ABC123',
        'contact' => ['email' => 'john@example.com'],
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight_ticket',
        'product_subtype' => 'economy',
        'provider' => 'videcom',
        'provider_reference' => 'ABC123',
        'ticket_number' => '6071234567890',
        'item_details' => [
            'airline_code' => 'YI',
            'rloc' => 'ABC123',
            'is_voidable' => true,
            'tickets' => [
                [
                    'ticket_number' => '6071234567890',
                    'issue_date' => $issueDate,
                ],
            ],
        ],
        'price' => 500,
        'taxes' => 0,
        'total' => 500,
        'currency' => 'LYD',
        'status' => 'issued',
        'paid' => 500,
        'remaining' => 0,
    ]);

    return [$order, $item];
}

test('voiding a same-day issued pnr removes tickets, deposits wallet, and logs status', function () {
    global $state;

    Queue::fake();

    [$order, $item] = seedIssuedOrder($state['user'], now()->toDateString());

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('void')
        ->once()
        ->with('ABC123')
        ->andReturn('<PNR RLOC="ABC123"><Itinerary from="MJI" to="IST" date="2026-04-24" departure="10:00" arrival="12:00" flight="123" class="Y" /></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.void', ['booking' => $order->id, 'ticket' => $item->id], false))
        ->assertRedirect()
        ->assertSessionHas('success');

    $item->refresh();
    $order->refresh();

    expect($item->status)->toBe('voided')
        ->and($item->ticket_number)->toBe('6071234567890')
        ->and($item->wallet_transaction_id)->not->toBeNull()
        ->and(data_get($item->item_details, 'tickets.0.ticket_number'))->toBe('6071234567890')
        ->and($order->status)->toBe('voided')
        ->and((float) $order->amount_refunded)->toBe(500.0);

    $wallet = $state['user']->getOrCreateCurrencyWallet('LYD');

    $this->assertDatabaseHas('transactions', [
        'wallet_id' => $wallet->id,
        'type' => 'deposit',
        'amount' => 50000,
    ]);

    $this->assertDatabaseHas('order_status_log', [
        'order_id' => $order->id,
        'old_status' => 'paid',
        'new_status' => 'voided',
        'user_id' => $state['user']->id,
    ]);

    Queue::assertPushed(UpdateAirlineBalanceJob::class, function (UpdateAirlineBalanceJob $job) use ($state): bool {
        if ($job->tenantProviderId !== $state['provider']->id) {
            return false;
        }

        if (! $job->delay instanceof \DateTimeInterface) {
            return false;
        }

        $seconds = $job->delay->getTimestamp() - now()->getTimestamp();

        return $seconds >= 590 && $seconds <= 610;
    });
});

test('voiding is rejected when ticket issue date is not today', function () {
    global $state;

    [$order, $item] = seedIssuedOrder($state['user'], now()->subDay()->toDateString());

    $providerMock = \Mockery::mock();
    $providerMock->shouldNotReceive('void');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.void', ['booking' => $order->id, 'ticket' => $item->id], false))
        ->assertRedirect()
        ->assertSessionHas('error');

    $item->refresh();
    $order->refresh();

    expect($item->status)->toBe('issued')
        ->and($order->status)->toBe('paid')
        ->and((float) $order->amount_refunded)->toBe(0.0);

    $this->assertDatabaseCount('order_status_log', 0);
    $this->assertDatabaseCount('transactions', 0);
});

test('voiding master-supply ticket uses source tenant provider, preserves pnr, and skips later order-show sync', function () {
    global $state;

    Queue::fake();

    // Simulate buyer agency without own provider configuration.
    $state['provider']->delete();

    $masterTenant = Tenant::create([
        'id' => 'void-master-'.Str::random(4),
        'company_name' => 'Void Master Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);
    $masterTenant->domains()->create(['domain' => $masterTenant->id.'.localhost']);

    $masterProvider = $masterTenant->run(function (): TenantProvider {
        return TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'YI',
            'airline_name' => 'Oya',
            'account_name' => 'Master Profile',
            'is_active' => true,
            'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
        ]);
    });

    [$order, $item] = seedIssuedOrder($state['user'], now()->toDateString());

    $item->update([
        'ledger_entry_id' => 'LEDGER-ORIG-001',
        'item_details' => array_merge((array) $item->item_details, [
            'financial_source' => 'master_agency_supply',
            'default_agency_tenant_id' => $masterTenant->id,
            'financial_source_tenant_id' => $masterTenant->id,
            'financial_provider_id' => $masterProvider->id,
        ]),
    ]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('void')
        ->once()
        ->with('ABC123')
        ->andReturn('<PNR RLOC="ABC123"><Itinerary from="MJI" to="IST" date="2026-04-24" departure="10:00" arrival="12:00" flight="123" class="Y" /></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->once()
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.void', ['booking' => $order->id, 'ticket' => $item->id], false))
        ->assertRedirect()
        ->assertSessionHas('success');

    $item = $item->fresh();

    expect($item->status)->toBe('voided')
        ->and((string) $item->provider_reference)->toBe('ABC123')
        ->and((string) $item->ledger_entry_id)->toBe('LEDGER-ORIG-001');

    $wallet = $state['user']->getOrCreateCurrencyWallet('LYD');
    $this->assertDatabaseHas('transactions', [
        'wallet_id' => $wallet->id,
        'type' => 'deposit',
        'amount' => 50000,
    ]);

    // After item becomes voided, order show must skip PNR query sync.
    $this->get($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->assertOk();

    Queue::assertPushed(UpdateAirlineBalanceJob::class, function (UpdateAirlineBalanceJob $job) use ($masterProvider): bool {
        return $job->tenantProviderId === $masterProvider->id;
    });

    $masterTenant->delete();
});

test('voiding uses source tenant provider even when financial_source flag is missing', function () {
    global $state;

    Queue::fake();

    // Buyer agency has no local provider; action must resolve from source tenant metadata.
    $state['provider']->delete();

    $masterTenant = Tenant::create([
        'id' => 'void-master-legacy-'.Str::random(4),
        'company_name' => 'Void Master Legacy',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);
    $masterTenant->domains()->create(['domain' => $masterTenant->id.'.localhost']);

    $masterProvider = $masterTenant->run(function (): TenantProvider {
        return TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'YI',
            'airline_name' => 'Oya',
            'account_name' => 'Master Legacy Profile',
            'is_active' => true,
            'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
        ]);
    });

    [$order, $item] = seedIssuedOrder($state['user'], now()->toDateString());

    $item->update([
        'item_details' => array_merge((array) $item->item_details, [
            // Intentionally omit financial_source to emulate legacy records.
            'default_agency_tenant_id' => $masterTenant->id,
            'financial_source_tenant_id' => $masterTenant->id,
            'financial_provider_id' => $masterProvider->id,
        ]),
    ]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('void')
        ->once()
        ->with('ABC123')
        ->andReturn('<PNR RLOC="ABC123"><Itinerary from="MJI" to="IST" date="2026-04-24" departure="10:00" arrival="12:00" flight="123" class="Y" /></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->once()
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.void', ['booking' => $order->id, 'ticket' => $item->id], false))
        ->assertRedirect()
        ->assertSessionHas('success');

    $item = $item->fresh();

    expect($item->status)->toBe('voided');

    Queue::assertPushed(UpdateAirlineBalanceJob::class, function (UpdateAirlineBalanceJob $job) use ($masterProvider): bool {
        return $job->tenantProviderId === $masterProvider->id;
    });

    $masterTenant->delete();
});

test('voiding falls back to default agency tenant when financial source tenant id is null', function () {
    global $state;

    Queue::fake();

    $state['provider']->delete();

    $masterTenant = Tenant::create([
        'id' => 'void-master-null-source-'.Str::random(4),
        'company_name' => 'Void Master Null Source',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);
    $masterTenant->domains()->create(['domain' => $masterTenant->id.'.localhost']);

    $masterProvider = $masterTenant->run(function (): TenantProvider {
        return TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'BM',
            'airline_name' => 'Berniq',
            'account_name' => 'Master BM Profile',
            'is_active' => true,
            'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
        ]);
    });

    [$order, $item] = seedIssuedOrder($state['user'], now()->toDateString());

    $item->update([
        'item_details' => array_merge((array) $item->item_details, [
            'iata' => 'BM',
            'airline_code' => null,
            'financial_source' => 'master_agency_supply',
            'financial_source_tenant_id' => null,
            'default_agency_tenant_id' => $masterTenant->id,
            'financial_provider_id' => null,
        ]),
    ]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('void')
        ->once()
        ->with('ABC123')
        ->andReturn('<PNR RLOC="ABC123"><Itinerary from="MJI" to="BEN" date="2026-05-01" departure="10:30" arrival="11:45" flight="122" class="S" /></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->once()
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.void', ['booking' => $order->id, 'ticket' => $item->id], false))
        ->assertRedirect()
        ->assertSessionHas('success');

    $item = $item->fresh();

    expect($item->status)->toBe('voided');

    Queue::assertPushed(UpdateAirlineBalanceJob::class, function (UpdateAirlineBalanceJob $job) use ($masterProvider): bool {
        return $job->tenantProviderId === $masterProvider->id;
    });

    $masterTenant->delete();
});

test('voiding falls back to agency settings default tenant when financial metadata was stripped by sync', function () {
    global $state;

    Queue::fake();

    $state['provider']->delete();

    $masterTenant = Tenant::create([
        'id' => 'void-master-agency-setting-'.Str::random(4),
        'company_name' => 'Void Master Agency Setting',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);
    $masterTenant->domains()->create(['domain' => $masterTenant->id.'.localhost']);

    $masterProvider = $masterTenant->run(function (): TenantProvider {
        return TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'BM',
            'airline_name' => 'Berniq',
            'account_name' => 'Master BM Profile',
            'is_active' => true,
            'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
        ]);
    });

    AgencySetting::current()->update([
        'default_agency_tenant_id' => $masterTenant->id,
    ]);

    [$order, $item] = seedIssuedOrder($state['user'], now()->toDateString());

    $order->update(['payment_method' => 'default_agency_supply']);

    $item->update([
        'item_details' => array_merge((array) $item->item_details, [
            'iata' => 'BM',
            'airline_code' => null,
            // Simulate sync snapshot that dropped finance metadata.
            'financial_source' => null,
            'financial_source_tenant_id' => null,
            'default_agency_tenant_id' => null,
            'financial_provider_id' => null,
        ]),
    ]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('void')
        ->once()
        ->with('ABC123')
        ->andReturn('<PNR RLOC="ABC123"><Itinerary from="MJI" to="BEN" date="2026-05-01" departure="10:30" arrival="11:45" flight="122" class="S" /></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->once()
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.void', ['booking' => $order->id, 'ticket' => $item->id], false))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($item->fresh()->status)->toBe('voided');

    Queue::assertPushed(UpdateAirlineBalanceJob::class, function (UpdateAirlineBalanceJob $job) use ($masterProvider): bool {
        return $job->tenantProviderId === $masterProvider->id;
    });

    $masterTenant->delete();
});

test('voiding falls back to global default agency when tenant default setting is missing', function () {
    global $state;

    Queue::fake();

    $state['provider']->delete();

    $masterTenant = Tenant::create([
        'id' => 'void-master-global-default-'.Str::random(4),
        'company_name' => 'Void Master Global Default',
        'status' => 'active',
        'subscription_status' => 'trial',
        'is_default_agency' => true,
    ]);
    $masterTenant->domains()->create(['domain' => $masterTenant->id.'.localhost']);

    $masterProvider = $masterTenant->run(function (): TenantProvider {
        return TenantProvider::create([
            'provider_type' => 'videcom',
            'airline_code' => 'BM',
            'airline_name' => 'Berniq',
            'account_name' => 'Master BM Profile',
            'is_active' => true,
            'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
        ]);
    });

    AgencySetting::current()->update([
        'default_agency_tenant_id' => null,
    ]);

    [$order, $item] = seedIssuedOrder($state['user'], now()->toDateString());

    $order->update(['payment_method' => 'default_agency_supply']);

    $item->update([
        'item_details' => array_merge((array) $item->item_details, [
            'iata' => 'BM',
            'airline_code' => null,
            'financial_source' => null,
            'financial_source_tenant_id' => null,
            'default_agency_tenant_id' => null,
            'financial_provider_id' => null,
        ]),
    ]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('void')
        ->once()
        ->with('ABC123')
        ->andReturn('<PNR RLOC="ABC123"><Itinerary from="MJI" to="BEN" date="2026-05-01" departure="10:30" arrival="11:45" flight="122" class="S" /></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->once()
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.void', ['booking' => $order->id, 'ticket' => $item->id], false))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($item->fresh()->status)->toBe('voided');

    Queue::assertPushed(UpdateAirlineBalanceJob::class, function (UpdateAirlineBalanceJob $job) use ($masterProvider): bool {
        return $job->tenantProviderId === $masterProvider->id;
    });

    $masterTenant->delete();
});

test('void refund is deposited back to the same wallet that was withdrawn on issue', function () {
    global $state;

    Queue::fake();

    [$order, $item] = seedIssuedOrder($state['user'], now()->toDateString());

    // Use a different wallet holder than the acting user to verify wallet targeting.
    $chargedUser = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $chargedWallet = $chargedUser->getOrCreateCurrencyWallet('LYD');
    $chargedWallet->depositFloat(700.00, ['type' => 'seed_before_issue']);

    $issueWithdrawTx = $chargedWallet->withdrawFloat(500.00, [
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'type' => 'ticket_purchase',
        'description' => 'Issue withdrawal for test',
    ]);

    $item->update([
        // Keep item total different to verify refund amount follows original withdraw transaction.
        'total' => 550,
        'wallet_transaction_id' => $issueWithdrawTx->uuid,
    ]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('void')
        ->once()
        ->with('ABC123')
        ->andReturn('<PNR RLOC="ABC123"><Itinerary from="MJI" to="IST" date="2026-04-24" departure="10:00" arrival="12:00" flight="123" class="Y" /></PNR>');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->from($baseUrl.route('orders.show', ['order' => $order->id], false))
        ->post($baseUrl.route('tickets.void', ['booking' => $order->id, 'ticket' => $item->id], false))
        ->assertRedirect()
        ->assertSessionHas('success');

    $refreshedItem = $item->fresh();
    $refundTx = WalletTransaction::query()->where('uuid', (string) $refreshedItem->wallet_transaction_id)->first();

    expect($refundTx)->not->toBeNull()
        ->and($refundTx->type)->toBe('deposit')
        ->and((int) $refundTx->wallet_id)->toBe((int) $chargedWallet->id)
        ->and((int) $refundTx->amount)->toBe(50000)
        ->and((float) $order->fresh()->amount_refunded)->toBe(550.0);

    expect((float) $chargedWallet->fresh()->balanceFloat)->toBe(700.0);
});
