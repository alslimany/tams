<?php

use App\Actions\Finance\PostToLedger;
use App\Jobs\PostToLedgerJob;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    $tenant = Tenant::create([
        'id' => 'ledger-job-'.Str::random(4),
        'company_name' => 'Ledger Job Test',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    tenancy()->initialize($tenant);

    $this->admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
});

afterEach(function () {
    tenancy()->end();
});

test('PostToLedgerJob posts order items to ledger', function () {
    $order = Order::create([
        'owner_type' => User::class,
        'owner_id' => $this->admin->id,
        'number' => 'ORD-JOB-001',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 300,
        'tax_total' => 50,
        'grand_total' => 350,
        'amount_paid' => 350,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'contact' => ['email' => 'test@example.com'],
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'ticket',
        'product_subtype' => 'economy',
        'provider' => 'videcom',
        'provider_reference' => 'JOB001',
        'item_details' => ['financial_source' => 'master_agency_supply'],
        'price' => 300,
        'net_fare' => 300,
        'taxes' => [['code' => 'ST', 'type' => 'total', 'amount' => 50]],
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

    $ledgerPoster = \Mockery::mock(PostToLedger::class);
    $ledgerPoster->shouldReceive('execute')->once()->withArgs(function (Order $orderArg, bool $includeOwn): bool {
        return $orderArg->id === \App\Models\Tenant\Order::first()->id && $includeOwn === true;
    });
    app()->instance(PostToLedger::class, $ledgerPoster);

    $job = new PostToLedgerJob($order->id);
    $job->handle(app(PostToLedger::class));
});

test('PostToLedgerJob handles missing order gracefully', function () {
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'Order not found'));

    $job = new PostToLedgerJob('non-existent-uuid');
    $job->handle(app(PostToLedger::class));

    // Should not throw — just log and return.
    $this->expectNotToPerformAssertions();
});

test('PostToLedgerJob retries on failure', function () {
    $job = new PostToLedgerJob('some-order-uuid');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(30);
});
