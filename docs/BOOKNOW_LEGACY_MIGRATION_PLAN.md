# Booknow — Legacy Agent Migration Plan

**Feature:** Admin-triggered migration of agents from old MySQL booking system to new multi-tenant platform  
**Source DB:** Old system — MySQL (`booknow` database, single-tenant)  
**Target DB:** New system — Central DB (MySQL) + Tenant DB per agent (SQLite)  
**Scope per agent:** orders · order_items · contacts · order_item_sales  
**Trigger:** Admin UI — connect to old DB, select agents, import  
**Stack:** Laravel · Inertia · React · Shadcn/UI  

---

## Architecture Overview

```
Admin selects agent from old DB list
        ↓
System reads agent record from old MySQL DB
        ↓
System creates new tenant (central DB)
        ↓
System runs tenant migrations (new SQLite DB)
        ↓
System bootstraps: ledger CoA + wallets
        ↓
MigrationPipeline runs in sequence:
  1. Users
  2. Customers → customers table
  3. Orders
  4. Order items (with financial mapping)
  5. Sales report (order_item_sales → order_items financial fields)
        ↓
MigrationReport generated and shown to admin
```

---

## Part 1 — Database Connection Setup

### 1.1 Register the Legacy DB Connection

Add to `config/database.php`:

```php
'legacy' => [
    'driver'    => 'mysql',
    'host'      => env('LEGACY_DB_HOST', '127.0.0.1'),
    'port'      => env('LEGACY_DB_PORT', '3306'),
    'database'  => env('LEGACY_DB_DATABASE', 'booknow_legacy'),
    'username'  => env('LEGACY_DB_USERNAME', 'root'),
    'password'  => env('LEGACY_DB_PASSWORD', ''),
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
    'strict'    => false,
],
```

Add to `.env`:
```env
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=booknow_old
LEGACY_DB_USERNAME=root
LEGACY_DB_PASSWORD=
```

### 1.2 LegacyDbService

```php
// app/Services/Migration/LegacyDbService.php

class LegacyDbService
{
    private \Illuminate\Database\Connection $conn;

    public function __construct()
    {
        $this->conn = DB::connection('legacy');
    }

    public function testConnection(): bool
    {
        try {
            $this->conn->getPdo();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getAgents(): Collection
    {
        return $this->conn->table('agents')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();
    }

    public function getAgent(int $agentId): ?object
    {
        return $this->conn->table('agents')
            ->where('id', $agentId)
            ->whereNull('deleted_at')
            ->first();
    }

    public function getAgentUsers(int $agentId): Collection
    {
        return $this->conn->table('users')
            ->where('related_to_type', 'App\\Models\\Agent')
            ->where('related_to_id', $agentId)
            ->get();
    }

    public function getAgentOrders(int $agentId): Collection
    {
        return $this->conn->table('orders')
            ->where('owner_type', 'App\\Models\\Agent')
            ->where('owner_id', $agentId)
            ->whereNull('deleted_at')
            ->get();
    }

    public function getOrderItems(string $orderId): Collection
    {
        return $this->conn->table('order_items')
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->get();
    }

    public function getOrderItemSales(int $orderItemId): Collection
    {
        return $this->conn->table('order_item_sales')
            ->where('order_item_id', $orderItemId)
            ->whereNull('deleted_at')
            ->get();
    }

    public function getContacts(int $agentId): Collection
    {
        // Contacts linked to this agent's orders
        return $this->conn->table('contacts')
            ->where('owner_type', 'App\\Models\\Agent')
            ->where('owner_id', $agentId)
            ->whereNull('deleted_at')
            ->get();
    }

    public function countAgentOrders(int $agentId): int
    {
        return $this->conn->table('orders')
            ->where('owner_type', 'App\\Models\\Agent')
            ->where('owner_id', $agentId)
            ->whereNull('deleted_at')
            ->count();
    }
}
```

---

## Part 2 — Field Mapping Tables

Study these carefully before writing any transformer code.
Every column mapping is documented with its source, target, and transformation notes.

### 2.1 Agent → Tenant (Central DB)

| Old field (`agents`) | New location | Value / Transform |
|---|---|---|
| `agents.id` | `migration_meta.legacy_agent_id` | Store for reference only |
| `agents.name` | `tenants.name` (or equivalent) | Direct copy |
| `agents.email` | Tenant admin user email | Direct copy |
| `agents.phone` | Tenant profile | Direct copy |
| `agents.address` | Tenant profile | Direct copy |
| `agents.number` | `migration_meta.legacy_agent_number` | Store for reference |
| `agents.agent_type_id` | `agency_settings` | Map to direct/merchant/network |
| `agents.joined_at` | Tenant `created_at` | Direct copy |

### 2.2 Users → Tenant `users` (New SQLite)

| Old field (`users`) | New field (`users`) | Transform |
|---|---|---|
| `first_name + ' ' + last_name` | `name` | Concatenate |
| `email` | `email` | Direct copy |
| `password` | `password` | Direct copy (already hashed — compatible) |
| `created_at` | `created_at` | Direct copy |
| Hardcode | `role` | `'manager'` for owner, `'agent'` for others |
| Hardcode | `is_active` | `1` |

### 2.3 Contacts → Tenant `customers` (New SQLite)

The new platform's tenant DB has a `customers` table, not a `contacts` table.
Old `contacts` table → new `customers` table.

| Old field (`contacts`) | New field (`customers`) | Transform |
|---|---|---|
| `first_name` | `first_name` | Direct copy |
| `last_name` | `last_name` | Direct copy |
| `email` | `email` | Direct copy |
| `phone` | `phone` | Direct copy |
| `reference` | — | Store in migration log only |
| `created_at` | `created_at` | Direct copy |
| — | `passport_number` | NULL (not in old contacts; comes from contact_documents if needed) |

> Note: Old system has a `contact_documents` table with passport data. 
> Phase 2 enhancement: pull `type = 'passport'` from `contact_documents` and populate `passport_number` / `passport_expiry`.

### 2.4 Orders → Tenant `orders` (New SQLite)

| Old field (`orders`) | New field (`orders`) | Transform |
|---|---|---|
| `id` (char 36) | `id` | Direct copy — preserve original UUID |
| `number` | `number` | Direct copy |
| `status` | `status` | Map: see status mapping below |
| `issued_at` | `issued_at` | Direct copy |
| `due_at` | `due_at` | Direct copy |
| `owner_type` | `owner_type` | Hardcode `'App\Models\User'` |
| `owner_id` | `owner_id` | Map to new tenant user ID |
| SUM of items `price` | `subtotal` | Calculate from order_items |
| SUM of items `taxes` | `tax_total` | Calculate from order_items |
| SUM of items `total` | `grand_total` | Calculate from order_items |
| Derive from payments | `amount_paid` | From old `orders` total if confirmed |
| `0` | `amount_refunded` | Default; update if voided items exist |
| `'LYD'` | `currency` | Hardcode LYD |
| `'legacy_import'` | `payment_method` | Tag all imported orders |
| NULL | `payment_reference` | NULL |
| Build from `contacts` | `contact` | JSON: `{name, email, phone}` |
| `parent_id` | `parent_id` | Direct copy |
| `created_at` | `created_at` | Direct copy |

**Status mapping:**

| Old `status` | New `status` |
|---|---|
| `confirmed` | `confirmed` |
| `cancelled` | `cancelled` |
| `pending` | `pending` |
| `voided` | `cancelled` |
| `refunded` | `cancelled` |
| anything else | `confirmed` |

### 2.5 Order Items → Tenant `order_items` (New SQLite)

This is the most complex mapping. The old system has two tables that combine into one new record:
`order_items` (operational) + `order_item_sales` (financial detail).

| Old source | Old field | New field (`order_items`) | Transform |
|---|---|---|---|
| `order_items` | `id` (bigint) | — | Do NOT copy — new auto-increment |
| `order_items` | `order_id` | `order_id` | Direct copy (same UUID) |
| `order_items` | `type` | `type` | Map: see type mapping below |
| `order_items` | `provider` | `provider` | Direct copy |
| `order_items` | `reference` | `provider_reference` | Direct copy |
| `order_items` | `price` | `price` | Direct copy |
| `order_items` | `taxes` (decimal) | `total_tax` | Direct copy |
| `order_items` | `total` | `total` | Direct copy |
| `order_items` | `currency_code` | `currency` | Direct copy |
| `order_items` | `exchange_rate` | `exchange_rate` | Direct copy |
| `order_items` | `status` | `status` | Map: see status mapping below |
| `order_items` | `net_commission` | `net_commission` | Direct copy |
| `order_items` | `agent_commission` | `agent_commission` | Direct copy |
| `order_items` | `remaning` | `remaining` | Direct copy (note typo in old DB) |
| `order_items` | `paid` | `paid` | Direct copy |
| `order_items` | `item` (JSON) | `item_details` (TEXT) | `json_encode($item)` |
| `order_item_sales` | `fare_price` | `net_fare` | Direct copy |
| `order_item_sales` | `total_tax` | `total_tax` | Override if sales record exists |
| `order_item_sales` | `total` | `total_amount` | Direct copy |
| `order_item_sales` | `percentage` | `commission_percent` | Direct copy |
| `order_item_sales` | `total_commission` | `commission_amount` | Direct copy |
| `order_item_sales` | `net_fare` | `net_after_commission` | Direct copy |
| `order_item_sales` | `taxes` (JSON) | `taxes` (TEXT) | `json_encode($taxes)` |
| `order_item_sales` | `ticket_number` | `ticket_number` | Direct copy |
| `order_item_sales` | `flight_number` | Store in `item_details.flight_number` | Merge into JSON |
| `order_item_sales` | `route` | Store in `item_details.route` | Merge into JSON |
| `order_item_sales` | `passenger_name` | Store in `item_details.passenger_name` | Merge into JSON |
| `order_item_sales` | `transaction_type` | `transaction_type` | Direct copy |
| Hardcode | | `refund_status` | `'none'` unless old status is voided/refunded |
| Hardcode | | `used_master_agency_provider` | `0` |
| Hardcode | | `product_type` | Map from `type` field |

**Type mapping (old `order_items.type` → new `order_items.type` + `product_type`):**

| Old `type` | New `type` | New `product_type` |
|---|---|---|
| `flight` | `flight_ticket` | `airline` |
| `flight_ticket` | `flight_ticket` | `airline` |
| `hotel` | `hotel` | `hotel` |
| `insurance` | `insurance` | `insurance` |
| `esim` | `esim` | `esim` |
| anything else | `other` | `other` |

**Item status mapping:**

| Old `status` | New `status` |
|---|---|
| `issued` | `issued` |
| `voided` | `voided` |
| `cancelled` | `voided` |
| `refunded` | `refunded` |
| anything else | `issued` |

---

## Part 3 — Migration Pipeline Services

### 3.1 MigrationJob (Queued)

The migration runs as a queued job so the admin UI does not time out on large agents.

```php
// app/Jobs/MigrateAgentJob.php

class MigrateAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $timeout  = 3600; // 1 hour max
    public int    $tries    = 1;    // Never retry partial migrations
    public string $queue    = 'migrations';

    public function __construct(
        public readonly int    $legacyAgentId,
        public readonly string $adminUserId,
        public readonly array  $options, // ['include_voided' => bool, 'date_from' => ?string]
    ) {}

    public function handle(AgentMigrationPipeline $pipeline): void
    {
        $pipeline->run($this->legacyAgentId, $this->adminUserId, $this->options);
    }

    public function failed(\Throwable $e): void
    {
        // Mark migration record as failed in central DB
        MigrationRecord::where('legacy_agent_id', $this->legacyAgentId)
            ->update(['status' => 'failed', 'error' => $e->getMessage()]);
    }
}
```

### 3.2 AgentMigrationPipeline

```php
// app/Services/Migration/AgentMigrationPipeline.php

class AgentMigrationPipeline
{
    private MigrationRecord $record;
    private array           $idMaps = []; // ['users' => [old_id => new_id], ...]
    private array           $log    = [];

    public function __construct(
        private LegacyDbService           $legacy,
        private TenantProvisioningService  $tenantProvisioner,
        private LedgerBootstrapService     $ledger,
        private WalletProvisioningService  $wallets,
    ) {}

    public function run(int $legacyAgentId, string $adminUserId, array $options): void
    {
        $agent = $this->legacy->getAgent($legacyAgentId);

        if (! $agent) {
            throw new \RuntimeException("Agent {$legacyAgentId} not found in legacy DB");
        }

        $this->record = MigrationRecord::create([
            'legacy_agent_id'     => $legacyAgentId,
            'legacy_agent_name'   => $agent->name,
            'legacy_agent_number' => $agent->number,
            'status'              => 'running',
            'started_at'          => now(),
            'initiated_by'        => $adminUserId,
            'options'             => json_encode($options),
        ]);

        try {
            // Step 1: Create tenant
            $tenant = $this->step('create_tenant', fn() =>
                $this->tenantProvisioner->createFromLegacyAgent($agent)
            );

            // Run remaining steps inside the tenant context
            tenancy()->initialize($tenant);

            // Step 2: Bootstrap ledger + wallets
            $this->step('bootstrap_ledger', fn() =>
                $this->ledger->bootstrapForTenant($tenant, 'direct')
            );
            $this->step('bootstrap_wallets', fn() =>
                $this->wallets->provisionAgencyWallets($tenant)
            );

            // Step 3: Migrate users
            $this->step('migrate_users', fn() =>
                $this->migrateUsers($legacyAgentId)
            );

            // Step 4: Migrate customers
            $this->step('migrate_customers', fn() =>
                $this->migrateCustomers($legacyAgentId)
            );

            // Step 5: Migrate orders + items
            $this->step('migrate_orders', fn() =>
                $this->migrateOrders($legacyAgentId, $options)
            );

            tenancy()->end();

            $this->record->update([
                'status'       => 'completed',
                'completed_at' => now(),
                'log'          => json_encode($this->log),
            ]);

        } catch (\Throwable $e) {
            tenancy()->end();
            $this->record->update([
                'status' => 'failed',
                'error'  => $e->getMessage(),
                'log'    => json_encode($this->log),
            ]);
            throw $e;
        }
    }

    // ── Steps ─────────────────────────────────────────────────────────────

    private function migrateUsers(int $agentId): void
    {
        $legacyUsers = $this->legacy->getAgentUsers($agentId);
        $isFirst     = true;

        foreach ($legacyUsers as $lu) {
            $newUser = \App\Models\User::create([
                'name'       => trim("{$lu->first_name} {$lu->last_name}"),
                'email'      => $lu->email,
                'password'   => $lu->password, // already hashed
                'role'       => $isFirst ? 'manager' : 'agent',
                'is_active'  => 1,
                'created_at' => $lu->created_at,
                'updated_at' => $lu->updated_at,
            ]);

            $this->idMaps['users'][$lu->id] = $newUser->id;
            $isFirst = false;
        }

        $this->addLog('users', count($legacyUsers), 0);
    }

    private function migrateCustomers(int $agentId): void
    {
        $contacts  = $this->legacy->getContacts($agentId);
        $migrated  = 0;
        $skipped   = 0;

        foreach ($contacts as $contact) {
            // Only migrate passenger-type contacts (type = 'passenger' or has a name)
            if (empty($contact->first_name) && empty($contact->last_name)) {
                $skipped++;
                continue;
            }

            $customer = \App\Models\Customer::create([
                'first_name' => $contact->first_name,
                'last_name'  => $contact->last_name,
                'email'      => $contact->email,
                'phone'      => $contact->phone,
                'created_at' => $contact->created_at,
                'updated_at' => $contact->updated_at,
            ]);

            $this->idMaps['customers'][$contact->id] = $customer->id;
            $migrated++;
        }

        $this->addLog('customers', $migrated, $skipped);
    }

    private function migrateOrders(int $agentId, array $options): void
    {
        $orders        = $this->legacy->getAgentOrders($agentId);
        $ordersMigrated = 0;
        $itemsMigrated  = 0;
        $skipped        = 0;

        foreach ($orders as $legacyOrder) {

            // Date filter if admin specified
            if (! empty($options['date_from'])) {
                if ($legacyOrder->created_at < $options['date_from']) {
                    $skipped++;
                    continue;
                }
            }

            // Skip voided if option says so
            if (empty($options['include_voided']) &&
                in_array($legacyOrder->status, ['voided', 'cancelled', 'refunded'])) {
                $skipped++;
                continue;
            }

            $items = $this->legacy->getOrderItems($legacyOrder->id);

            // Build contact JSON from order's contact fields
            $contactJson = json_encode([
                'name'    => $legacyOrder->contact_name,
                'email'   => $legacyOrder->contact_email,
                'phone'   => $legacyOrder->contact_phone,
                'address' => $legacyOrder->contact_address,
                'city'    => $legacyOrder->contact_city,
                'country' => $legacyOrder->contact_country,
            ]);

            // Calculate financial totals from items
            $subtotal  = $items->sum('price');
            $taxTotal  = $items->sum('taxes');
            $grandTotal= $items->sum('total');

            // Determine owner user — map from legacy user
            $legacyUserId = $legacyOrder->user_id;
            $newUserId    = $this->idMaps['users'][$legacyUserId]
                            ?? array_values($this->idMaps['users'])[0]; // fallback to first user

            \App\Models\Order::insert([
                'id'               => $legacyOrder->id, // preserve UUID
                'owner_type'       => 'App\Models\User',
                'owner_id'         => $newUserId,
                'number'           => $legacyOrder->number ?? $this->generateOrderNumber(),
                'status'           => $this->mapOrderStatus($legacyOrder->status),
                'issued_at'        => $legacyOrder->issued_at,
                'due_at'           => $legacyOrder->due_at,
                'subtotal'         => $subtotal,
                'tax_total'        => $taxTotal,
                'grand_total'      => $grandTotal,
                'amount_paid'      => $legacyOrder->status === 'confirmed' ? $grandTotal : 0,
                'amount_refunded'  => 0,
                'currency'         => 'LYD',
                'payment_method'   => 'legacy_import',
                'payment_reference'=> null,
                'contact'          => $contactJson,
                'parent_id'        => $legacyOrder->parent_id,
                'created_at'       => $legacyOrder->created_at,
                'updated_at'       => $legacyOrder->updated_at,
                'ledger_entry_id'  => null,
            ]);

            // Migrate items
            foreach ($items as $legacyItem) {
                $this->migrateOrderItem($legacyItem, $legacyOrder);
                $itemsMigrated++;
            }

            $ordersMigrated++;
        }

        $this->addLog('orders', $ordersMigrated, $skipped);
        $this->addLog('order_items', $itemsMigrated, 0);
    }

    private function migrateOrderItem(object $legacyItem, object $legacyOrder): void
    {
        // Pull the sales report record for this item
        $salesRecord = $this->legacy->getOrderItemSales($legacyItem->id)->first();

        // Build merged item_details JSON
        $itemDetails = $this->buildItemDetails($legacyItem, $salesRecord);

        // Map type and product_type
        [$newType, $productType] = $this->mapItemType($legacyItem->type);

        // Financial fields — prefer sales record if available
        $netFare          = $salesRecord?->fare_price   ?? $legacyItem->price;
        $totalTax         = $salesRecord?->total_tax    ?? $legacyItem->taxes;
        $totalAmount      = $salesRecord?->total        ?? $legacyItem->total;
        $commissionPct    = $salesRecord?->percentage   ?? $legacyItem->net_commission;
        $commissionAmt    = $salesRecord?->total_commission ?? $legacyItem->agent_commission;
        $netAfterComm     = $salesRecord?->net_fare     ?? null;
        $taxesJson        = $salesRecord
            ? json_encode($salesRecord->taxes ?? [])
            : json_encode([]);

        // Refund status
        $refundStatus = in_array($legacyItem->status, ['voided','cancelled','refunded'])
            ? 'refunded'
            : 'none';

        \App\Models\OrderItem::create([
            'order_id'                 => $legacyOrder->id,
            'type'                     => $newType,
            'product_type'             => $productType,
            'provider'                 => $legacyItem->provider ?? 'legacy',
            'provider_reference'       => $legacyItem->reference,
            'ticket_number'            => $salesRecord?->ticket_number,
            'item_details'             => $itemDetails,
            'price'                    => $legacyItem->price,
            'taxes'                    => $taxesJson,
            'total'                    => $legacyItem->total,
            'currency'                 => $legacyItem->currency_code ?? 'LYD',
            'exchange_rate'            => $legacyItem->exchange_rate ?? 1,
            'status'                   => $this->mapItemStatus($legacyItem->status),
            'net_commission'           => $legacyItem->net_commission,
            'agent_commission'         => $legacyItem->agent_commission,
            'paid'                     => $legacyItem->paid ?? 0,
            'remaining'                => $legacyItem->remaning ?? 0, // old typo
            'refund_parent_id'         => null,
            'refund_status'            => $refundStatus,
            'wallet_transaction_id'    => null, // not carried over
            'airline_transaction_id'   => null, // not carried over
            'net_fare'                 => $netFare,
            'total_tax'                => $totalTax,
            'total_amount'             => $totalAmount,
            'commission_percent'       => $commissionPct,
            'commission_amount'        => $commissionAmt,
            'net_after_commission'     => $netAfterComm,
            'transaction_type'         => $salesRecord?->transaction_type ?? 'purchase',
            'product_details'          => json_encode([]),
            'ledger_entry_id'          => null,
            'used_master_agency_provider' => 0,
            'master_commission_percent'   => null,
            'created_at'               => $legacyItem->created_at,
            'updated_at'               => $legacyItem->updated_at,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function buildItemDetails(object $item, ?object $sales): string
    {
        $base = is_string($item->item)
            ? (json_decode($item->item, true) ?? [])
            : [];

        if ($sales) {
            $base['passenger_name'] = $sales->passenger_name ?? null;
            $base['route']          = $sales->route          ?? null;
            $base['flight_number']  = $sales->flight_number  ?? null;
            $base['flight_date']    = $sales->flight_date     ?? null;
            $base['service_reference'] = $sales->service_reference ?? null;
            $base['coupon']         = $sales->coupon          ?? null;
            $base['class']          = $sales->class           ?? null;
            $base['airline_code']   = $this->extractAirlineCode($sales->flight_number);
            $base['legacy_import']  = true;
        }

        return json_encode($base);
    }

    private function extractAirlineCode(?string $flightNumber): ?string
    {
        if (! $flightNumber) return null;
        // Flight numbers like "YI0510" → "YI"
        preg_match('/^([A-Z]{2,3})/i', $flightNumber, $m);
        return $m[1] ?? null;
    }

    private function mapOrderStatus(string $status): string
    {
        return match (strtolower($status)) {
            'confirmed'                    => 'confirmed',
            'cancelled', 'voided'          => 'cancelled',
            'refunded'                     => 'cancelled',
            'pending'                      => 'pending',
            default                        => 'confirmed',
        };
    }

    private function mapItemStatus(string $status): string
    {
        return match (strtolower($status)) {
            'issued'                       => 'issued',
            'voided', 'cancelled'          => 'voided',
            'refunded'                     => 'refunded',
            default                        => 'issued',
        };
    }

    private function mapItemType(string $type): array
    {
        return match (strtolower($type)) {
            'flight', 'flight_ticket'      => ['flight_ticket', 'airline'],
            'hotel'                        => ['hotel', 'hotel'],
            'insurance'                    => ['insurance', 'insurance'],
            'esim'                         => ['esim', 'esim'],
            default                        => ['other', 'other'],
        };
    }

    private function generateOrderNumber(): string
    {
        return 'LEG-' . strtoupper(substr(uniqid(), -6));
    }

    private function step(string $name, callable $fn): mixed
    {
        $this->addLog($name, 'started', null);
        $result = $fn();
        $this->addLog($name, 'done', null);
        return $result;
    }

    private function addLog(string $step, mixed $migrated, mixed $skipped): void
    {
        $this->log[] = [
            'step'      => $step,
            'migrated'  => $migrated,
            'skipped'   => $skipped,
            'at'        => now()->toIso8601String(),
        ];

        // Update progress in migration record
        $this->record->update(['log' => json_encode($this->log)]);
    }
}
```

---

## Part 4 — Central DB: Migration Records Table

```php
// Migration: create_migration_records_table

Schema::create('migration_records', function (Blueprint $table) {
    $table->id();
    $table->integer('legacy_agent_id');
    $table->string('legacy_agent_name');
    $table->string('legacy_agent_number')->nullable();
    $table->string('tenant_id')->nullable();       // set after tenant created
    $table->string('status');                      // pending|running|completed|failed
    $table->string('initiated_by');                // admin user ID
    $table->json('options')->nullable();
    $table->json('log')->nullable();               // step-by-step log
    $table->text('error')->nullable();
    $table->integer('orders_migrated')->default(0);
    $table->integer('items_migrated')->default(0);
    $table->integer('customers_migrated')->default(0);
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
});
```

---

## Part 5 — Admin UI Pages

### 5.1 Route Group

```php
// routes/admin.php — inside admin middleware group

Route::prefix('admin/migration')
    ->name('admin.migration.')
    ->group(function () {
        Route::get('/',           [MigrationController::class, 'index'])->name('index');
        Route::get('/agents',     [MigrationController::class, 'agents'])->name('agents');
        Route::post('/run',       [MigrationController::class, 'run'])->name('run');
        Route::get('/status/{id}',[MigrationController::class, 'status'])->name('status');
        Route::get('/report/{id}',[MigrationController::class, 'report'])->name('report');
    });
```

### 5.2 Page 1 — Migration Hub (`/admin/migration`)

**Purpose:** Entry point. Shows legacy DB connection status and all past migration records.

**Layout:**
```
┌────────────────────────────────────────────────────────────┐
│  Legacy DB Connection                                       │
│  Host: 127.0.0.1  DB: booknow_legacy  [Test Connection ✓] │
├────────────────────────────────────────────────────────────┤
│  [+ Import New Agent]                                       │
├────────────────────────────────────────────────────────────┤
│  Past Migrations                                            │
│  Agent Name | Status | Orders | Items | Date | [Report]   │
└────────────────────────────────────────────────────────────┘
```

**Components:**
- Connection status `Badge` (green=connected, red=failed) with "Test Connection" button
- `Table` of past `migration_records`
- Status badges: `pending`=gray, `running`=amber (with spinner), `completed`=green, `failed`=red
- "Import New Agent" button navigates to Page 2

### 5.3 Page 2 — Select Agent (`/admin/migration/agents`)

**Purpose:** Browse all agents from the legacy DB and select one to import.

**Inertia props:**
```ts
interface AgentsPageProps {
  agents: {
    id: number;
    name: string;
    number: string;
    email: string;
    phone: string;
    agent_type: string;
    order_count: number;
    joined_at: string;
    already_migrated: boolean; // check migration_records
  }[];
  connectionOk: boolean;
}
```

**Layout:**
```
┌────────────────────────────────────────────────────────────┐
│  Select Agent to Import from Legacy System                 │
│  [Search by name or number...]                             │
├─────────┬────────────────┬──────────┬────────────┬────────┤
│ Number  │ Name           │ Orders   │ Type       │ Action │
│ AG001   │ Median Tours   │ 1,234    │ Direct     │[Import]│
│ AG002   │ Sky Travel     │ 89       │ Direct     │[✓ Done]│
└─────────┴────────────────┴──────────┴────────────┴────────┘
```

**Components:**
- `Input` search filter (client-side)
- `Table` with one row per legacy agent
- `Badge` "Already Migrated" (green) for agents in `migration_records` with status=completed
- "Import" `Button` — opens configuration dialog (Page 3)
- Disabled "Import" button for already-migrated agents

### 5.4 Page 3 — Migration Configuration (Dialog)

**Purpose:** Admin configures migration options before running.

**Dialog fields:**

| Field | Type | Default | Description |
|---|---|---|---|
| Include voided/cancelled orders | `Switch` | OFF | Whether to carry over old cancelled orders |
| Date from | `DatePicker` | (blank = all) | Only import orders on or after this date |
| Confirm agent name | `Input` readonly | agent.name | Display only |
| Estimated records | Text | Calculated | "~1,234 orders, ~1,456 items" |

**Action:** "Start Migration" → POST to `/admin/migration/run` → dispatches `MigrateAgentJob` → redirects to status page.

### 5.5 Page 4 — Migration Status (`/admin/migration/status/{id}`)

**Purpose:** Real-time progress view while migration is running. Polls every 3 seconds via Inertia `router.reload`.

**Layout:**
```
┌────────────────────────────────────────────────────────────┐
│  Migration: Median Tours  [RUNNING...]                      │
├────────────────────────────────────────────────────────────┤
│  Steps:                                                     │
│  [✓] Create Tenant                                          │
│  [✓] Bootstrap Ledger                                       │
│  [✓] Bootstrap Wallets                                      │
│  [✓] Migrate Users             3 users                      │
│  [✓] Migrate Customers         248 contacts                 │
│  [⟳] Migrate Orders            892 / 1,234 orders...       │
│  [ ] Generate Report                                        │
├────────────────────────────────────────────────────────────┤
│  Elapsed: 00:01:34                                          │
└────────────────────────────────────────────────────────────┘
```

**Components:**
- Step list with `CheckCircle` / `Loader` / `Circle` icons per step
- `Progress` bar overall
- Auto-poll: `useEffect` with `setInterval` calling `router.reload({ only: ['record'] })` every 3 seconds
- Stop polling when `status === 'completed'` or `status === 'failed'`
- On completion: show "View Report" button

### 5.6 Page 5 — Migration Report (`/admin/migration/report/{id}`)

**Purpose:** Summary of what was migrated, what was skipped, and any warnings.

**Sections:**

```
┌──────────────────────────────────────────────────────────────┐
│  Migration Report — Median Tours                             │
│  Completed: May 23, 2026 at 14:35  Duration: 2m 14s         │
├──────────────────────┬───────────────────────────────────────┤
│  Summary             │                                        │
│  Tenant created      │ ✓  demo2                              │
│  Users migrated      │ ✓  3                                  │
│  Customers migrated  │ ✓  248                                │
│  Orders migrated     │ ✓  1,189  (45 skipped — voided)      │
│  Order items         │ ✓  1,302                              │
│  Items with sales    │ ✓  1,198  (104 no sales record)       │
├──────────────────────┴───────────────────────────────────────┤
│  Warnings                                                    │
│  ⚠ 104 order items had no order_item_sales record           │
│    Financial fields set to 0 — review manually              │
│  ⚠ 12 orders had no user mapping — assigned to admin user   │
├──────────────────────────────────────────────────────────────┤
│  [Open Tenant]  [Download Report CSV]  [Migrate Another]    │
└──────────────────────────────────────────────────────────────┘
```

---

## Part 6 — Data Integrity Rules

The pipeline must enforce these rules during migration. Any violation is logged as a warning, never a hard stop (except for tenant creation failure which is fatal).

| Rule | What to do on violation |
|---|---|
| Order UUID already exists in tenant DB | Skip order, log warning with old ID |
| Order item has no `order_item_sales` record | Migrate item with financial fields = 0, log warning |
| Order references a `user_id` not in `idMaps['users']` | Assign to first migrated user, log warning |
| Order `total` does not match sum of items | Use sum of items, log discrepancy |
| `order_item_sales.fare_price` + taxes ≠ `total` | Use `total` as source of truth, log discrepancy |
| Contact has no first_name or last_name | Skip, log count |
| `order_item_sales.taxes` is not valid JSON | Set to `[]`, log warning |

---

## Part 7 — Post-Migration Steps (Manual — Admin Must Do)

After migration completes, the admin must do the following in the new tenant:

1. **Set provider credentials** — The old system stored credentials in the `meta` table. These are not migrated because they contain sensitive API keys. The agency must re-enter their Videcom/airline API credentials in the tenant provider settings.

2. **Fund provider wallets** — Migrated orders show historical balances but the airline provider wallet starts at 0. The admin must deposit the current real balance with the provider.

3. **Review historical ledger** — Historical orders do NOT get ledger entries posted during migration (the accounting plan applies to new issuances only). The admin can optionally run a backfill command (see below).

4. **Verify order count** — Compare `migration_records.orders_migrated` against the old system's order count for the agent.

### 7.1 Optional: Backfill Ledger Entries for Migrated Orders

This is a separate artisan command the admin can run after migration if they want historical orders reflected in the ledger:

```php
// php artisan migration:backfill-ledger {tenant_id}

class BackfillMigratedLedgerCommand extends Command
{
    protected $signature = 'migration:backfill-ledger {tenant_id}';

    public function handle(): void
    {
        $tenant = Tenant::find($this->argument('tenant_id'));
        tenancy()->initialize($tenant);

        $items = \App\Models\OrderItem::whereNotNull('commission_amount')
            ->whereNull('ledger_entry_id')
            ->where('status', 'issued')
            ->get();

        $bar = $this->output->createProgressBar($items->count());

        foreach ($items as $item) {
            try {
                // Build pricing DTO from stored financial fields
                $pricing = PricingBreakdownDTO::fromOrderItem($item);
                app(LedgerPostingService::class)->postIssuanceEntry(
                    orderId:           $item->order_id,
                    providerReference: $item->provider_reference ?? 'legacy',
                    pricing:           $pricing,
                );
                $item->update(['ledger_entry_id' => 'backfilled']);
            } catch (\Throwable $e) {
                $this->warn("Failed item {$item->id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        tenancy()->end();
        $this->info("Backfill complete.");
    }
}
```

---

## Part 8 — Build Order for the AI Agent

| Step | Task | Depends On |
|---|---|---|
| 1 | Add `legacy` DB connection to `config/database.php` + `.env` | — |
| 2 | Create `migration_records` table in central DB | — |
| 3 | Build `LegacyDbService` | Step 1 |
| 4 | Build `AgentMigrationPipeline` + all transformer helpers | Steps 2 + 3 |
| 5 | Build `MigrateAgentJob` | Step 4 |
| 6 | Build `MigrationController` with all 5 routes | Steps 3 + 4 |
| 7 | Build Admin UI — Migration Hub page | Step 6 |
| 8 | Build Admin UI — Agent Selection page | Step 6 |
| 9 | Build Admin UI — Config dialog + status page | Steps 7 + 8 |
| 10 | Build Admin UI — Report page | Step 9 |
| 11 | Build `BackfillMigratedLedgerCommand` | Step 4 |
| 12 | Write tests (see below) | Steps 1–11 |

---

## Part 9 — Test Checklist

### Infrastructure tests
- [ ] `legacy` DB connection resolves without error
- [ ] `LegacyDbService::testConnection()` returns `true`
- [ ] `LegacyDbService::getAgents()` returns at least one agent
- [ ] `migration_records` table exists in central DB

### Pipeline tests (use a single known agent as fixture)
- [ ] `MigrateAgentJob` dispatches without exception
- [ ] Tenant is created in central DB after job runs
- [ ] Tenant SQLite DB exists and has all tables migrated
- [ ] `users` count in new tenant = count of users with `related_to_id = agentId` in old DB
- [ ] `customers` count in new tenant ≥ count of contacts with `owner_id = agentId` in old DB
- [ ] `orders` count in new tenant = count of non-voided orders for agent (with default options)
- [ ] `orders` count includes voided when `include_voided = true`
- [ ] Every migrated order has at least one `order_items` record
- [ ] `order_items.commission_amount` is non-null for items that had a sales record
- [ ] `order_items.item_details` contains `airline_code` for flight items
- [ ] `migration_records.status = 'completed'` after successful run
- [ ] `migration_records.status = 'failed'` and `error` is populated on exception

### UI tests
- [ ] Migration Hub page renders without error
- [ ] Connection status badge shows green when legacy DB is reachable
- [ ] Agent list shows all legacy agents
- [ ] Agents already migrated show "Already Migrated" badge
- [ ] Clicking Import opens configuration dialog
- [ ] "Start Migration" button dispatches job and redirects to status page
- [ ] Status page polls and updates step indicators
- [ ] Status page stops polling when `status = completed`
- [ ] Report page shows correct counts matching `migration_records`
- [ ] "Open Tenant" button navigates to the correct new tenant

### Data integrity tests (verify on a known agent)
- [ ] All order UUIDs in new tenant match old system
- [ ] No duplicate order UUIDs created
- [ ] Items with no `order_item_sales` have `commission_amount = 0` and a warning in the log
- [ ] Items with `order_item_sales` have `commission_amount > 0` (where original had commission)
- [ ] `flight_number` and `route` appear in `item_details` for migrated flight items