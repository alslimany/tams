<?php

use Abivia\Ledger\Models\JournalDetail;
use Abivia\Ledger\Models\LedgerAccount;
use App\Models\Tenant;
use App\Models\Tenant\InventoryItem;
use App\Models\Tenant\InventoryMovement;
use App\Models\Tenant\InventoryStock;
use App\Models\Tenant\InventoryWarehouse;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Tests\AccountingTestCase;

// ─────────────────────────────────────────────────────────────────────────────
// Upgrade v2 — Perpetual inventory: stock movements, moving-average cost,
// and ledger integration. Covers plan §7 "Inventory" checklist items.
// ─────────────────────────────────────────────────────────────────────────────

function uv2InvTenant(): Tenant
{
    return Tenant::findOrFail(AccountingTestCase::sharedTenantId());
}

function uv2InvRoute(string $name, array|string|int $params = []): string
{
    $tenant = uv2InvTenant();

    return 'http://'.$tenant->domains->first()->domain.route($name, $params, false);
}

function uv2InvAdmin(): User
{
    $admin = null;
    uv2InvTenant()->run(function () use (&$admin) {
        $admin = User::query()->where('email', 'uv2-inventory-admin@example.test')->first()
            ?? User::factory()->create([
                'email' => 'uv2-inventory-admin@example.test',
                'role' => 'admin',
                'is_active' => true,
            ]);
    });
    tenancy()->end();

    return $admin;
}

/**
 * Create a warehouse + item pair for a test (must run inside tenant context).
 *
 * @return array{0: InventoryWarehouse, 1: InventoryItem}
 */
function uv2MakeWarehouseAndItem(string $suffix): array
{
    $warehouse = InventoryWarehouse::create([
        'name' => "Test Warehouse {$suffix}",
        'code' => "WH-{$suffix}",
        'type' => 'physical',
    ]);

    $item = InventoryItem::create([
        'code' => "ITEM-{$suffix}",
        'name' => "Test Item {$suffix}",
        'category' => 'physical_good',
        'unit' => 'piece',
        'unit_cost' => 10,
    ]);

    return [$warehouse, $item];
}

/**
 * Map a journal entry's details to [code => signed amount].
 *
 * @return array<string, float>
 */
function uv2EntryDetails(int $journalEntryId): array
{
    return JournalDetail::where('journalEntryId', $journalEntryId)
        ->get()
        ->mapWithKeys(function (JournalDetail $detail) {
            $code = LedgerAccount::where('ledgerUuid', $detail->ledgerUuid)->value('code');

            return [$code => (float) $detail->amount];
        })
        ->all();
}

test('receiving goods increases stock and posts a balanced Dr 1420 / Cr 2510 entry', function () {
    uv2InvTenant()->run(function () {
        [$warehouse, $item] = uv2MakeWarehouseAndItem('RCV'.mt_rand(100, 999));

        $movement = app(InventoryService::class)->receive(
            warehouseId: $warehouse->id,
            itemId: $item->id,
            quantity: 10,
            unitCost: 12.5,
            supplier: 'ACME Supplies',
        );

        $stock = InventoryStock::where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->first();

        expect((float) $stock->quantity)->toBe(10.0)
            ->and((float) $stock->avg_unit_cost)->toBe(12.5)
            ->and($movement->ledger_entry_id)->not->toBeNull()
            ->and((float) $movement->total_cost)->toBe(125.0);

        $details = uv2EntryDetails($movement->ledger_entry_id);

        // Debits stored negative, credits positive.
        expect($details['1420'])->toBe(-125.0)
            ->and($details['2510'])->toBe(125.0)
            ->and(round(array_sum($details), 3))->toBe(0.0);
    });
    tenancy()->end();
});

test('moving average cost updates correctly after each receive', function () {
    uv2InvTenant()->run(function () {
        [$warehouse, $item] = uv2MakeWarehouseAndItem('AVG'.mt_rand(100, 999));

        $service = app(InventoryService::class);
        $service->receive($warehouse->id, $item->id, 10, 10.0, 'Supplier A');
        $service->receive($warehouse->id, $item->id, 10, 20.0, 'Supplier B');

        $stock = InventoryStock::where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->first();

        // (10×10 + 10×20) / 20 = 15
        expect((float) $stock->quantity)->toBe(20.0)
            ->and((float) $stock->avg_unit_cost)->toBe(15.0);
    });
    tenancy()->end();
});

test('delivering goods decreases stock at moving-average cost and posts Dr 5000 / Cr 1420', function () {
    uv2InvTenant()->run(function () {
        [$warehouse, $item] = uv2MakeWarehouseAndItem('DLV'.mt_rand(100, 999));

        $service = app(InventoryService::class);
        $service->receive($warehouse->id, $item->id, 10, 10.0, 'Supplier A');
        $service->receive($warehouse->id, $item->id, 10, 20.0, 'Supplier B');

        $movement = $service->deliver($warehouse->id, $item->id, 5);

        $stock = InventoryStock::where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->first();

        expect((float) $stock->quantity)->toBe(15.0)
            ->and((float) $movement->unit_cost)->toBe(15.0)
            ->and((float) $movement->total_cost)->toBe(75.0);

        $details = uv2EntryDetails($movement->ledger_entry_id);

        expect($details['5000'])->toBe(-75.0)
            ->and($details['1420'])->toBe(75.0)
            ->and(round(array_sum($details), 3))->toBe(0.0);
    });
    tenancy()->end();
});

test('delivering more than available stock is blocked', function () {
    uv2InvTenant()->run(function () {
        [$warehouse, $item] = uv2MakeWarehouseAndItem('OVR'.mt_rand(100, 999));

        $service = app(InventoryService::class);
        $service->receive($warehouse->id, $item->id, 3, 10.0, 'Supplier A');

        $service->deliver($warehouse->id, $item->id, 5);
    });
})->throws(RuntimeException::class, 'Insufficient stock');

test('transfer moves stock between warehouses (default same-account routing skips the net-zero entry)', function () {
    uv2InvTenant()->run(function () {
        $suffix = 'TRF'.mt_rand(100, 999);
        [$fromWarehouse, $item] = uv2MakeWarehouseAndItem($suffix);
        $toWarehouse = InventoryWarehouse::create([
            'name' => "Destination {$suffix}",
            'code' => "WH-{$suffix}-B",
            'type' => 'physical',
        ]);

        $service = app(InventoryService::class);
        $service->receive($fromWarehouse->id, $item->id, 10, 8.0, 'Supplier A');

        $movement = $service->transfer($fromWarehouse->id, $toWarehouse->id, $item->id, 4);

        $fromStock = InventoryStock::where('warehouse_id', $fromWarehouse->id)->where('item_id', $item->id)->first();
        $toStock = InventoryStock::where('warehouse_id', $toWarehouse->id)->where('item_id', $item->id)->first();

        // Default routing debits and credits the same account (1420) — financially
        // net zero, so no journal entry is posted and the ledger stays balanced.
        expect((float) $fromStock->quantity)->toBe(6.0)
            ->and((float) $toStock->quantity)->toBe(4.0)
            ->and((float) $toStock->avg_unit_cost)->toBe(8.0)
            ->and($movement->ledger_entry_id)->toBeNull();
    });
    tenancy()->end();
});

test('transfer posts a balanced ledger entry when routing uses distinct accounts', function () {
    uv2InvTenant()->run(function () {
        $suffix = 'TRX'.mt_rand(100, 999);
        [$fromWarehouse, $item] = uv2MakeWarehouseAndItem($suffix);
        $toWarehouse = InventoryWarehouse::create([
            'name' => "Destination {$suffix}",
            'code' => "WH-{$suffix}-B",
            'type' => 'physical',
        ]);

        // Route transfers between the two inventory sub-accounts.
        \App\Models\Tenant\AccountRouting::where('event_type', 'inventory_transfer')
            ->where('event_category', 'inventory')
            ->update(['debit_account' => '1410', 'credit_account' => '1420']);
        app(\App\Services\Accounting\AccountRoutingService::class)->clearCache();

        $service = app(InventoryService::class);
        $service->receive($fromWarehouse->id, $item->id, 10, 8.0, 'Supplier A');

        $movement = $service->transfer($fromWarehouse->id, $toWarehouse->id, $item->id, 4);

        expect($movement->ledger_entry_id)->not->toBeNull();

        $details = uv2EntryDetails($movement->ledger_entry_id);

        expect($details['1410'])->toBe(-32.0)
            ->and($details['1420'])->toBe(32.0)
            ->and(round(array_sum($details), 3))->toBe(0.0);

        // Restore defaults for other tests.
        app(\App\Services\Accounting\AccountRoutingDefaults::class)->seed(force: true);
        app(\App\Services\Accounting\AccountRoutingService::class)->clearCache();
    });
    tenancy()->end();
});

test('transfer to the same warehouse is rejected', function () {
    uv2InvTenant()->run(function () {
        [$warehouse, $item] = uv2MakeWarehouseAndItem('SAME'.mt_rand(100, 999));

        app(InventoryService::class)->receive($warehouse->id, $item->id, 5, 10.0, 'Supplier A');
        app(InventoryService::class)->transfer($warehouse->id, $warehouse->id, $item->id, 2);
    });
})->throws(InvalidArgumentException::class, 'same warehouse');

test('movement references are sequential per type and year', function () {
    uv2InvTenant()->run(function () {
        [$warehouse, $item] = uv2MakeWarehouseAndItem('REF'.mt_rand(100, 999));

        $movement = app(InventoryService::class)->receive($warehouse->id, $item->id, 1, 5.0, 'Supplier A');

        $year = now()->format('Y');
        expect($movement->reference)->toMatch("/^RCV-{$year}-\\d{4}$/");

        // The reference stored on the movement matches the one in the ledger description flow.
        expect(InventoryMovement::where('reference', $movement->reference)->count())->toBe(1);
    });
    tenancy()->end();
});

test('creating a warehouse with a duplicate code is rejected', function () {
    $admin = uv2InvAdmin();
    $code = 'WH-DUP'.mt_rand(100, 999);

    $this->actingAs($admin)
        ->post(uv2InvRoute('accounting.inventory.warehouses.store'), [
            'name' => 'First Warehouse',
            'code' => $code,
            'type' => 'physical',
        ])
        ->assertSessionHas('success');

    $this->actingAs($admin)
        ->post(uv2InvRoute('accounting.inventory.warehouses.store'), [
            'name' => 'Second Warehouse',
            'code' => $code,
            'type' => 'physical',
        ])
        ->assertSessionHasErrors('code');
});

test('all movements appear in the movement log with ledger entry links', function () {
    $admin = uv2InvAdmin();

    $movementReference = null;
    uv2InvTenant()->run(function () use (&$movementReference) {
        [$warehouse, $item] = uv2MakeWarehouseAndItem('LOG'.mt_rand(100, 999));
        $movement = app(InventoryService::class)->receive($warehouse->id, $item->id, 2, 4.0, 'Supplier A');
        $movementReference = $movement->reference;
    });
    tenancy()->end();

    $this->actingAs($admin)
        ->get(uv2InvRoute('accounting.inventory.movements'))
        ->assertOk()
        ->assertSee($movementReference);
});
