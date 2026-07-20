<?php

use App\Models\Tenant;
use App\Models\Tenant\AccountRouting;
use App\Services\Accounting\AccountRoutingDefaults;
use App\Services\Accounting\AccountRoutingService;
use Tests\AccountingTestCase;

// ─────────────────────────────────────────────────────────────────────────────
// Upgrade v2 — Account routing resolution, overrides, reset, missing-route error.
// Covers plan §7 "Account Routing" checklist items.
// ─────────────────────────────────────────────────────────────────────────────

function uv2RoutingTenant(): Tenant
{
    return Tenant::findOrFail(AccountingTestCase::sharedTenantId());
}

test('all standard event types are seeded for the tenant', function () {
    uv2RoutingTenant()->run(function () {
        $expected = collect(AccountRoutingDefaults::rows());

        $existing = AccountRouting::query()
            ->get()
            ->keyBy(fn (AccountRouting $row) => "{$row->event_type}:{$row->event_category}");

        $expected->each(function (array $row) use ($existing) {
            expect($existing->has("{$row['event_type']}:{$row['event_category']}"))
                ->toBeTrue("Missing routing for {$row['event_type']}:{$row['event_category']}");
        });
    });
    tenancy()->end();
});

test('resolve returns the seeded debit and credit accounts', function () {
    uv2RoutingTenant()->run(function () {
        $routing = app(AccountRoutingService::class)->resolve('inventory_receive', 'inventory');

        expect($routing['debit'])->toBe('1420')
            ->and($routing['credit'])->toBe('2510');
    });
    tenancy()->end();
});

test('a missing routing record throws a clear error naming the event', function () {
    uv2RoutingTenant()->run(function () {
        app(AccountRoutingService::class)->resolve('nonexistent_event', 'general');
    });
})->throws(RuntimeException::class, 'nonexistent_event');

test('changing a routing account takes effect after the cache is cleared', function () {
    uv2RoutingTenant()->run(function () {
        $service = app(AccountRoutingService::class);

        AccountRouting::where('event_type', 'sale_revenue')
            ->where('event_category', 'esim')
            ->update(['credit_account' => '4500']);
        $service->clearCache();

        expect($service->resolve('sale_revenue', 'esim')['credit'])->toBe('4500');

        // Restore for other tests.
        app(AccountRoutingDefaults::class)->seed(force: true);
        $service->clearCache();
    });
    tenancy()->end();
});

test('reset to defaults reverts overridden routing to seeded values', function () {
    uv2RoutingTenant()->run(function () {
        AccountRouting::where('event_type', 'inventory_deliver')
            ->where('event_category', 'inventory')
            ->update(['debit_account' => '7300']);

        app(AccountRoutingDefaults::class)->seed(force: true);
        $service = app(AccountRoutingService::class);
        $service->clearCache();

        $routing = $service->resolve('inventory_deliver', 'inventory');

        expect($routing['debit'])->toBe('5000')
            ->and($routing['credit'])->toBe('1420');
    });
    tenancy()->end();
});
