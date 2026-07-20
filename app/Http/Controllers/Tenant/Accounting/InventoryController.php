<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Tenant\InventoryItem;
use App\Models\Tenant\InventoryMovement;
use App\Models\Tenant\InventoryStock;
use App\Models\Tenant\InventoryWarehouse;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function warehouses(): Response
    {
        $warehouses = InventoryWarehouse::withCount('stock')
            ->orderBy('code')
            ->get()
            ->map(function (InventoryWarehouse $warehouse) {
                $stock = InventoryStock::where('warehouse_id', $warehouse->id)->get();

                return [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'code' => $warehouse->code,
                    'type' => $warehouse->type,
                    'address' => $warehouse->address,
                    'isActive' => $warehouse->is_active,
                    'itemCount' => $stock->where('quantity', '>', 0)->count(),
                    'totalQuantity' => round((float) $stock->sum('quantity'), 3),
                    'totalValue' => round($stock->sum(fn ($s) => (float) $s->quantity * (float) $s->avg_unit_cost), 3),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Accounting/Inventory/Warehouses', [
            'warehouses' => $warehouses,
        ]);
    }

    public function storeWarehouse(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:inventory_warehouses,code'],
            'type' => ['required', 'in:physical,virtual'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        InventoryWarehouse::create($validated);

        return back()->with('success', 'Warehouse created.');
    }

    public function warehouseShow(int $id): Response
    {
        $warehouse = InventoryWarehouse::findOrFail($id);

        $stock = InventoryStock::with('item')
            ->where('warehouse_id', $warehouse->id)
            ->get()
            ->map(fn (InventoryStock $row) => [
                'itemId' => $row->item_id,
                'itemCode' => $row->item?->code,
                'itemName' => $row->item?->name,
                'unit' => $row->item?->unit,
                'quantity' => (float) $row->quantity,
                'avgUnitCost' => (float) $row->avg_unit_cost,
                'totalValue' => round((float) $row->quantity * (float) $row->avg_unit_cost, 3),
            ])
            ->values()
            ->all();

        return Inertia::render('Accounting/Inventory/WarehouseDetail', [
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'type' => $warehouse->type,
                'address' => $warehouse->address,
                'isActive' => $warehouse->is_active,
                'notes' => $warehouse->notes,
            ],
            'stock' => $stock,
        ]);
    }

    public function items(): Response
    {
        $items = InventoryItem::withSum('stock as total_quantity', 'quantity')
            ->orderBy('code')
            ->get()
            ->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'category' => $item->category,
                'unit' => $item->unit,
                'unitCost' => (float) $item->unit_cost,
                'inventoryAccount' => $item->inventory_account,
                'cogsAccount' => $item->cogs_account,
                'purchaseAccount' => $item->purchase_account,
                'isActive' => $item->is_active,
                'totalQuantity' => round((float) ($item->total_quantity ?? 0), 3),
            ])
            ->values()
            ->all();

        return Inertia::render('Accounting/Inventory/Items', [
            'items' => $items,
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:inventory_items,code'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:travel_product,physical_good'],
            'unit' => ['required', 'string', 'max:50'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
        ]);

        InventoryItem::create($validated);

        return back()->with('success', 'Item created.');
    }

    public function itemShow(int $id): Response
    {
        $item = InventoryItem::findOrFail($id);

        $stock = InventoryStock::with('warehouse')
            ->where('item_id', $item->id)
            ->get()
            ->map(fn (InventoryStock $row) => [
                'warehouseId' => $row->warehouse_id,
                'warehouseCode' => $row->warehouse?->code,
                'warehouseName' => $row->warehouse?->name,
                'quantity' => (float) $row->quantity,
                'avgUnitCost' => (float) $row->avg_unit_cost,
                'totalValue' => round((float) $row->quantity * (float) $row->avg_unit_cost, 3),
            ])
            ->values()
            ->all();

        $movements = InventoryMovement::with(['fromWarehouse', 'toWarehouse'])
            ->where('item_id', $item->id)
            ->orderByDesc('movement_date')
            ->limit(50)
            ->get()
            ->map(fn (InventoryMovement $movement) => $this->movementRow($movement))
            ->values()
            ->all();

        return Inertia::render('Accounting/Inventory/ItemDetail', [
            'item' => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'category' => $item->category,
                'unit' => $item->unit,
                'unitCost' => (float) $item->unit_cost,
                'inventoryAccount' => $item->inventory_account,
                'cogsAccount' => $item->cogs_account,
                'purchaseAccount' => $item->purchase_account,
                'isActive' => $item->is_active,
                'description' => $item->description,
            ],
            'stock' => $stock,
            'movements' => $movements,
        ]);
    }

    public function movements(Request $request): Response
    {
        $query = InventoryMovement::with(['item', 'fromWarehouse', 'toWarehouse'])
            ->orderByDesc('movement_date');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        if ($request->filled('item')) {
            $query->where('item_id', $request->integer('item'));
        }
        if ($request->filled('warehouse')) {
            $warehouseId = $request->integer('warehouse');
            $query->where(function ($q) use ($warehouseId) {
                $q->where('from_warehouse_id', $warehouseId)
                    ->orWhere('to_warehouse_id', $warehouseId);
            });
        }

        $paginated = $query->paginate(25)->withQueryString();

        return Inertia::render('Accounting/Inventory/Movements', [
            'movements' => [
                'data' => collect($paginated->items())
                    ->map(fn (InventoryMovement $movement) => $this->movementRow($movement))
                    ->values()
                    ->all(),
                'links' => $paginated->linkCollection(),
                'total' => $paginated->total(),
            ],
            'filters' => [
                'type' => $request->string('type')->toString() ?: null,
                'item' => $request->filled('item') ? $request->integer('item') : null,
                'warehouse' => $request->filled('warehouse') ? $request->integer('warehouse') : null,
            ],
            'items' => $this->itemOptions(),
            'warehouses' => $this->warehouseOptions(activeOnly: false),
        ]);
    }

    public function receiveForm(): Response
    {
        return Inertia::render('Accounting/Inventory/Receive', [
            'warehouses' => $this->warehouseOptions(),
            'items' => $this->itemOptions(),
        ]);
    }

    public function receiveStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id'],
            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'supplier' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = $this->guarded(fn () => $this->inventory->receive(
            warehouseId: (int) $validated['warehouse_id'],
            itemId: (int) $validated['item_id'],
            quantity: (float) $validated['quantity'],
            unitCost: (float) $validated['unit_cost'],
            supplier: $validated['supplier'],
            notes: $validated['notes'] ?? null,
        ));

        return redirect()
            ->route('accounting.inventory.movements')
            ->with('success', "Goods received — {$movement->reference}.");
    }

    public function deliverForm(): Response
    {
        return Inertia::render('Accounting/Inventory/Deliver', [
            'warehouses' => $this->warehouseOptions(),
            'items' => $this->itemOptions(),
            'stockLevels' => $this->stockLevels(),
        ]);
    }

    public function deliverStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id'],
            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'order_id' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = $this->guarded(fn () => $this->inventory->deliver(
            warehouseId: (int) $validated['warehouse_id'],
            itemId: (int) $validated['item_id'],
            quantity: (float) $validated['quantity'],
            orderId: $validated['order_id'] ?? null,
            notes: $validated['notes'] ?? null,
        ));

        return redirect()
            ->route('accounting.inventory.movements')
            ->with('success', "Goods delivered — {$movement->reference}.");
    }

    public function transferForm(): Response
    {
        return Inertia::render('Accounting/Inventory/Transfer', [
            'warehouses' => $this->warehouseOptions(),
            'items' => $this->itemOptions(),
            'stockLevels' => $this->stockLevels(),
        ]);
    }

    public function transferStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from_warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id', 'different:from_warehouse_id'],
            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = $this->guarded(fn () => $this->inventory->transfer(
            fromWarehouseId: (int) $validated['from_warehouse_id'],
            toWarehouseId: (int) $validated['to_warehouse_id'],
            itemId: (int) $validated['item_id'],
            quantity: (float) $validated['quantity'],
            notes: $validated['notes'] ?? null,
        ));

        return redirect()
            ->route('accounting.inventory.movements')
            ->with('success', "Goods transferred — {$movement->reference}.");
    }

    /**
     * Run an inventory operation, converting domain errors to validation errors.
     */
    private function guarded(callable $operation): InventoryMovement
    {
        try {
            return $operation();
        } catch (RuntimeException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function movementRow(InventoryMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'type' => $movement->type,
            'reference' => $movement->reference,
            'itemCode' => $movement->item?->code,
            'itemName' => $movement->item?->name,
            'fromWarehouse' => $movement->fromWarehouse?->code,
            'toWarehouse' => $movement->toWarehouse?->code,
            'quantity' => (float) $movement->quantity,
            'unitCost' => (float) $movement->unit_cost,
            'totalCost' => (float) $movement->total_cost,
            'supplier' => $movement->supplier,
            'orderId' => $movement->order_id,
            'notes' => $movement->notes,
            'ledgerEntryId' => $movement->ledger_entry_id,
            'status' => $movement->status,
            'movementDate' => $movement->movement_date?->toDateTimeString(),
        ];
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    private function warehouseOptions(bool $activeOnly = true): array
    {
        return InventoryWarehouse::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get()
            ->map(fn (InventoryWarehouse $warehouse) => [
                'id' => $warehouse->id,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, code: string, name: string, unit: string}>
     */
    private function itemOptions(): array
    {
        return InventoryItem::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->unit,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{warehouseId: int, itemId: int, quantity: float, avgUnitCost: float}>
     */
    private function stockLevels(): array
    {
        return InventoryStock::query()
            ->get()
            ->map(fn (InventoryStock $stock) => [
                'warehouseId' => $stock->warehouse_id,
                'itemId' => $stock->item_id,
                'quantity' => (float) $stock->quantity,
                'avgUnitCost' => (float) $stock->avg_unit_cost,
            ])
            ->values()
            ->all();
    }
}
