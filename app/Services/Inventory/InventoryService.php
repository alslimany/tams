<?php

namespace App\Services\Inventory;

use App\Models\Tenant\InventoryItem;
use App\Models\Tenant\InventoryMovement;
use App\Models\Tenant\InventoryStock;
use App\Services\Accounting\AccountRoutingService;
use App\Services\Accounting\LedgerPostingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Perpetual inventory operations. Every movement immediately updates stock
 * levels (weighted moving-average cost) and posts a balanced ledger entry
 * via account routing (inventory_receive / inventory_deliver / inventory_transfer).
 */
class InventoryService
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * Receive goods into a warehouse. Ledger: Dr Inventory / Cr Accounts Payable.
     */
    public function receive(
        int $warehouseId,
        int $itemId,
        float $quantity,
        float $unitCost,
        string $supplier,
        ?string $notes = null,
    ): InventoryMovement {
        $this->assertPositiveQuantity($quantity);

        if ($unitCost < 0) {
            throw new InvalidArgumentException('Unit cost cannot be negative.');
        }

        $item = InventoryItem::findOrFail($itemId);
        $totalCost = round($quantity * $unitCost, 3);

        return DB::transaction(function () use ($warehouseId, $item, $quantity, $unitCost, $totalCost, $supplier, $notes) {
            $reference = $this->generateReference('receive');

            $entry = $this->ledger->postInventoryEntry(
                eventType: 'inventory_receive',
                category: 'inventory',
                amount: $totalCost,
                description: "Receive {$quantity} × {$item->name} from {$supplier}",
                reference: $reference,
            );

            $movement = InventoryMovement::create([
                'type' => 'receive',
                'reference' => $reference,
                'item_id' => $item->id,
                'to_warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'supplier' => $supplier,
                'notes' => $notes,
                'ledger_entry_id' => $entry->journalEntryId,
                'movement_date' => now(),
                'created_by' => Auth::id(),
            ]);

            $this->updateStock($warehouseId, $item->id, $quantity, $unitCost, 'add');

            return $movement;
        });
    }

    /**
     * Deliver goods from a warehouse at moving-average cost.
     * Ledger: Dr COGS / Cr Inventory.
     */
    public function deliver(
        int $warehouseId,
        int $itemId,
        float $quantity,
        ?string $orderId = null,
        ?string $notes = null,
    ): InventoryMovement {
        $this->assertPositiveQuantity($quantity);

        $item = InventoryItem::findOrFail($itemId);
        $stock = $this->getStock($warehouseId, $itemId);

        if ((float) $stock->quantity < $quantity) {
            throw new RuntimeException(
                "Insufficient stock. Available: {$stock->quantity}, requested: {$quantity}"
            );
        }

        $unitCost = (float) $stock->avg_unit_cost;
        $totalCost = round($quantity * $unitCost, 3);

        return DB::transaction(function () use ($warehouseId, $item, $quantity, $unitCost, $totalCost, $orderId, $notes) {
            $reference = $this->generateReference('deliver');

            $entry = $this->ledger->postInventoryEntry(
                eventType: 'inventory_deliver',
                category: 'inventory',
                amount: $totalCost,
                description: "Deliver {$quantity} × {$item->name}",
                reference: $reference,
            );

            $movement = InventoryMovement::create([
                'type' => 'deliver',
                'reference' => $reference,
                'item_id' => $item->id,
                'from_warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'order_id' => $orderId,
                'notes' => $notes,
                'ledger_entry_id' => $entry->journalEntryId,
                'movement_date' => now(),
                'created_by' => Auth::id(),
            ]);

            $this->updateStock($warehouseId, $item->id, $quantity, $unitCost, 'subtract');

            return $movement;
        });
    }

    /**
     * Transfer goods between warehouses at moving-average cost.
     * Ledger: Dr Inventory / Cr Inventory (net zero at account level).
     */
    public function transfer(
        int $fromWarehouseId,
        int $toWarehouseId,
        int $itemId,
        float $quantity,
        ?string $notes = null,
    ): InventoryMovement {
        $this->assertPositiveQuantity($quantity);

        if ($fromWarehouseId === $toWarehouseId) {
            throw new InvalidArgumentException('Cannot transfer to the same warehouse.');
        }

        $item = InventoryItem::findOrFail($itemId);
        $stock = $this->getStock($fromWarehouseId, $itemId);

        if ((float) $stock->quantity < $quantity) {
            throw new RuntimeException(
                "Insufficient stock for transfer. Available: {$stock->quantity}, requested: {$quantity}"
            );
        }

        $unitCost = (float) $stock->avg_unit_cost;
        $totalCost = round($quantity * $unitCost, 3);

        return DB::transaction(function () use ($fromWarehouseId, $toWarehouseId, $item, $quantity, $unitCost, $totalCost, $notes) {
            $reference = $this->generateReference('transfer');

            // A transfer routed to the same account on both sides is financially
            // net zero, and abivia rejects entries debiting and crediting one
            // account — only post when the routing uses distinct accounts.
            $routing = app(AccountRoutingService::class)->resolve('inventory_transfer', 'inventory');
            $entry = null;

            if ($routing['debit'] !== $routing['credit']) {
                $entry = $this->ledger->postInventoryEntry(
                    eventType: 'inventory_transfer',
                    category: 'inventory',
                    amount: $totalCost,
                    description: "Transfer {$quantity} × {$item->name}",
                    reference: $reference,
                );
            }

            $movement = InventoryMovement::create([
                'type' => 'transfer',
                'reference' => $reference,
                'item_id' => $item->id,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'notes' => $notes,
                'ledger_entry_id' => $entry?->journalEntryId,
                'movement_date' => now(),
                'created_by' => Auth::id(),
            ]);

            $this->updateStock($fromWarehouseId, $item->id, $quantity, $unitCost, 'subtract');
            $this->updateStock($toWarehouseId, $item->id, $quantity, $unitCost, 'add');

            return $movement;
        });
    }

    private function assertPositiveQuantity(float $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }
    }

    private function updateStock(int $warehouseId, int $itemId, float $quantity, float $cost, string $operation): void
    {
        $stock = InventoryStock::firstOrCreate(
            ['warehouse_id' => $warehouseId, 'item_id' => $itemId],
            ['quantity' => 0, 'avg_unit_cost' => 0],
        );

        if ($operation === 'add') {
            $currentQuantity = (float) $stock->quantity;
            $newQuantity = $currentQuantity + $quantity;
            $newCost = $newQuantity > 0
                ? (($currentQuantity * (float) $stock->avg_unit_cost) + ($quantity * $cost)) / $newQuantity
                : $cost;

            $stock->update([
                'quantity' => $newQuantity,
                'avg_unit_cost' => round($newCost, 3),
            ]);
        } else {
            $stock->update([
                'quantity' => max(0, (float) $stock->quantity - $quantity),
            ]);
        }
    }

    private function getStock(int $warehouseId, int $itemId): InventoryStock
    {
        $stock = InventoryStock::where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->first();

        if ($stock === null) {
            throw new RuntimeException('No stock record exists for this item in the selected warehouse.');
        }

        return $stock;
    }

    private function generateReference(string $type): string
    {
        $prefix = match ($type) {
            'receive' => 'RCV',
            'deliver' => 'DLV',
            'transfer' => 'TRF',
        };

        $year = now()->format('Y');
        $count = InventoryMovement::where('type', $type)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return "{$prefix}-{$year}-".str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }
}
