<?php

namespace App\Services\Migration;

use App\Models\MigrationRecord;
use App\Models\NetworkMembership;
use App\Models\ProviderAllocation;
use App\Models\Tenant;
use App\Services\Accounting\LedgerBootstrapService;
use App\Services\OfficeIdGenerator;
use App\Services\Wallet\WalletProvisioningService;
use Illuminate\Support\Facades\Log;

class AgentMigrationPipeline
{
    /** @var array<string, array<int, int>> */
    private array $idMaps = [];

    /** @var array<int, array<string, mixed>> */
    private array $log = [];

    private MigrationRecord $record;

    /**
     * Provider cache keyed by "IATA:CURRENCY" (e.g. "BM:LYD", "BM:EUR") and plain "IATA" as fallback.
     * Values are TenantProvider IDs.
     *
     * @var array<string, int>
     */
    private array $providerCache = [];

    /** @var string|null Agency tenant ID selected for network linking */
    private ?string $agencyTenantId = null;

    /** @var array<int> TenantProvider IDs actually used in this migration */
    private array $usedProviderIds = [];

    public function __construct(
        private readonly LegacyDbService $legacy,
        private readonly LedgerBootstrapService $ledger,
        private readonly WalletProvisioningService $wallets,
    ) {}

    public function run(int $legacyAgentId, string $adminUserId, array $options, int $recordId): void
    {
        $agent = $this->legacy->getAgent($legacyAgentId);

        if (! $agent) {
            throw new \RuntimeException("Agent {$legacyAgentId} not found in legacy DB");
        }

        $this->record = MigrationRecord::findOrFail($recordId);

        $this->record->update([
            'legacy_agent_number' => $agent->number ?? null,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $existingTenantId = $options['existing_tenant_id'] ?? null;

        try {
            if ($existingTenantId) {
                $tenant = $this->resolveExistingTenant($existingTenantId);
            } else {
                // Step 1: Create tenant
                $tenant = $this->step('create_tenant', fn () => $this->createTenant($agent));
            }

            $this->record->update(['tenant_id' => $tenant->id]);

            // Run remaining steps inside the tenant context
            tenancy()->initialize($tenant);

            if (! $existingTenantId) {
                // Step 2: Bootstrap ledger + wallets (only for new tenants)
                $this->step('bootstrap_ledger', fn () => $this->ledger->bootstrapForTenant($tenant));
                $this->step('bootstrap_wallets', fn () => $this->wallets->provisionAgencyWallets($tenant));
            }

            // Step 3: Migrate users
            // For existing tenants we still build the idMap from existing users so orders can be assigned.
            $this->step('migrate_users', fn () => $existingTenantId
                ? $this->loadExistingUsers($legacyAgentId)
                : $this->migrateUsers($legacyAgentId)
            );

            // Step 4: Migrate customers
            $this->step('migrate_customers', fn () => $this->migrateCustomers($legacyAgentId));

            // Load provider cache:
            // - For existing tenants: load from the tenant's own providers first, then optionally from agency tenant.
            // - For new tenants: load from agency tenant only.
            $this->agencyTenantId = $options['agency_network_tenant_id'] ?? null;

            if ($existingTenantId) {
                $this->loadProviderCacheFromTenant($tenant);
            }

            if ($this->agencyTenantId) {
                $this->loadProviderCache($this->agencyTenantId, $tenant);
            }

            // Step 5: Migrate orders + items
            $this->step('migrate_orders', fn () => $this->migrateOrders($legacyAgentId, $options));

            tenancy()->end();

            // Step 6: Link agency network (central DB — runs outside tenancy)
            if ($this->agencyTenantId) {
                $this->step('link_agency_network', fn () => $this->linkAgencyNetwork($tenant));
            }

            $this->record->update([
                'status' => 'completed',
                'completed_at' => now(),
                'log' => $this->log,
            ]);

        } catch (\Throwable $e) {
            tenancy()->end();
            $this->record->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'log' => $this->log,
            ]);
            throw $e;
        }
    }

    /**
     * Resolve an existing tenant by ID, throwing if not found.
     */
    private function resolveExistingTenant(string $tenantId): Tenant
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            throw new \RuntimeException("Existing tenant '{$tenantId}' not found");
        }

        $this->addLog('use_existing_tenant', 1, 0);

        return $tenant;
    }

    /**
     * For existing tenants: build the users idMap by matching legacy users to existing tenant users by email.
     * Falls back to the first tenant user for any unmatched legacy user.
     */
    private function loadExistingUsers(int $agentId): void
    {
        $legacyUsers = $this->legacy->getAgentUsers($agentId);
        $matched = 0;
        $unmatched = 0;

        // Index existing tenant users by email for fast lookup (tenant DB, not central)
        $existingByEmail = collect(DB::table('users')->get(['id', 'email']))
            ->keyBy(fn ($u) => strtolower($u->email));

        $fallbackUserId = DB::table('users')->orderBy('id')->value('id');

        foreach ($legacyUsers as $lu) {
            $email = strtolower((string) ($lu->email ?? ''));
            $existing = $email ? $existingByEmail->get($email) : null;

            if ($existing) {
                $this->idMaps['users'][$lu->id] = $existing->id;
                $matched++;
            } elseif ($fallbackUserId) {
                $this->idMaps['users'][$lu->id] = $fallbackUserId;
                $this->addWarning("Legacy user {$lu->id} ({$lu->email}) not found in existing tenant — mapped to first user");
                $unmatched++;
            }
        }

        $this->addLog('load_existing_users', $matched, $unmatched);
    }

    /**
     * Load IATA → TenantProvider ID map directly from the target tenant's own providers.
     * Must be called while tenancy is already initialized to the target tenant.
     */
    private function loadProviderCacheFromTenant(Tenant $tenant): void
    {
        $providers = \DB::table('tenant_providers')
            ->whereNotNull('airline_code')
            ->where('is_active', true)
            ->get(['id', 'airline_code', 'account_name']);

        foreach ($providers as $provider) {
            $iata = strtoupper($provider->airline_code);
            $currency = $this->currencyFromAccountName($provider->account_name ?? '');

            // Keyed by IATA:CURRENCY for precise matching
            $currencyKey = "{$iata}:{$currency}";
            if (! isset($this->providerCache[$currencyKey])) {
                $this->providerCache[$currencyKey] = $provider->id;
            }

            // Plain IATA fallback — first provider wins (lowest ID)
            if (! isset($this->providerCache[$iata])) {
                $this->providerCache[$iata] = $provider->id;
            }
        }

        $this->addLog('load_tenant_provider_cache', count($providers), 0);
    }

    // ── Tenant Creation ────────────────────────────────────────────────────

    private function createTenant(object $agent): Tenant
    {
        $generator = new OfficeIdGenerator;
        $cityIata = OfficeIdGenerator::DEFAULT_CITY_IATA;
        $officeId = $generator->generate($cityIata, $agent->name);

        $tenant = Tenant::create([
            'id' => $officeId,
            'path' => strtolower($officeId),
            'office_id' => $officeId,
            'city_iata' => $cityIata,
            'company_name' => $agent->name,
            'owner_name' => $agent->name,
            'owner_email' => $agent->email ?? "admin@{$officeId}.local",
            'owner_phone' => $agent->phone ?? null,
            'status' => 'active',
            'subscription_status' => 'active',
            'subscription_plan' => 'startup',
            'type' => 'direct',
            'settings' => ['search_display_mode' => 'per_offer'],
        ]);

        // Create domain record
        $tenantBaseDomain = (string) config('tenancy.tenant_base_domain');
        $tenant->domains()->create([
            'domain' => strtolower($officeId).'.'.$tenantBaseDomain,
        ]);

        return $tenant;
    }

    // ── Users ──────────────────────────────────────────────────────────────

    private function migrateUsers(int $agentId): void
    {
        $legacyUsers = $this->legacy->getAgentUsers($agentId);
        $isFirst = true;

        foreach ($legacyUsers as $lu) {
            $newUser = \App\Models\User::create([
                'name' => trim(($lu->first_name ?? '').' '.($lu->last_name ?? '')) ?: ($lu->name ?? 'User'),
                'email' => $lu->email,
                'password' => $lu->password, // already hashed — compatible bcrypt
                'role' => $isFirst ? 'manager' : 'agent',
                'is_active' => 1,
                'created_at' => $lu->created_at,
                'updated_at' => $lu->updated_at,
            ]);

            $this->idMaps['users'][$lu->id] = $newUser->id;
            $isFirst = false;
        }

        $this->addLog('users', count($legacyUsers), 0);
    }

    // ── Customers ──────────────────────────────────────────────────────────

    private function migrateCustomers(int $agentId): void
    {
        $contacts = $this->legacy->getContacts($agentId);
        $migrated = 0;
        $skipped = 0;

        foreach ($contacts as $contact) {
            if (empty($contact->first_name) && empty($contact->last_name)) {
                $skipped++;

                continue;
            }

            $customer = \App\Models\Tenant\Customer::create([
                'first_name' => $contact->first_name ?? '',
                'last_name' => $contact->last_name ?? '',
                'email' => $contact->email ?? null,
                'phone' => $contact->phone ?? null,
                'created_at' => $contact->created_at,
                'updated_at' => $contact->updated_at,
            ]);

            $this->idMaps['customers'][$contact->id] = $customer->id;
            $migrated++;
        }

        $this->record->update(['customers_migrated' => $migrated]);
        $this->addLog('customers', $migrated, $skipped);
    }

    // ── Orders ─────────────────────────────────────────────────────────────

    private function migrateOrders(int $agentId, array $options): void
    {
        $orders = $this->legacy->getAgentOrders($agentId);
        $ordersMigrated = 0;
        $itemsMigrated = 0;
        $skipped = 0;

        // Collect all order IDs we will migrate so parent_id references are safe
        $migratedOrderIds = [];

        foreach ($orders as $legacyOrder) {
            // Date filter
            if (! empty($options['date_from']) && $legacyOrder->created_at < $options['date_from']) {
                $skipped++;

                continue;
            }

            // Skip voided/cancelled unless option says include them
            if (empty($options['include_voided']) &&
                in_array($legacyOrder->status, ['voided', 'cancelled', 'refunded'])) {
                $skipped++;

                continue;
            }

            // Skip if UUID already exists (idempotency)
            if (\App\Models\Tenant\Order::where('id', $legacyOrder->id)->exists()) {
                $this->addWarning("Order {$legacyOrder->id} already exists — skipped");
                $skipped++;

                continue;
            }

            $items = $this->legacy->getOrderItems($legacyOrder->id);

            // Financial totals from items
            $subtotal = $items->sum('price');
            $taxTotal = $items->sum('taxes');
            $grandTotal = $items->sum('total');

            // Warn only when the difference is material (>1 unit) and the order has items
            $legacyTotal = (float) ($legacyOrder->total ?? 0);
            if ($items->count() > 0 && abs($legacyTotal - $grandTotal) > 1) {
                $this->addWarning("Order {$legacyOrder->id} total mismatch (legacy: {$legacyTotal}, items sum: {$grandTotal}) — using sum of items");
            }

            // Map owner user
            $legacyUserId = $legacyOrder->user_id ?? null;
            $newUserId = ($legacyUserId && isset($this->idMaps['users'][$legacyUserId]))
                ? $this->idMaps['users'][$legacyUserId]
                : (array_values($this->idMaps['users'] ?? [])[0] ?? null);

            if (! $newUserId) {
                $this->addWarning("Order {$legacyOrder->id} has no user mapping — skipped");
                $skipped++;

                continue;
            }

            if ($newUserId !== ($this->idMaps['users'][$legacyUserId] ?? null)) {
                $this->addWarning("Order {$legacyOrder->id} user {$legacyUserId} not found — assigned to first user");
            }

            $contactJson = json_encode([
                'name' => $legacyOrder->contact_name ?? null,
                'email' => $legacyOrder->contact_email ?? null,
                'phone' => $legacyOrder->contact_phone ?? null,
                'address' => $legacyOrder->contact_address ?? null,
                'city' => $legacyOrder->contact_city ?? null,
                'country' => $legacyOrder->contact_country ?? null,
            ]);

            \App\Models\Tenant\Order::insert([
                'id' => $legacyOrder->id,
                'owner_type' => 'App\Models\User',
                'owner_id' => $newUserId,
                'number' => $legacyOrder->number ?? $this->generateOrderNumber(),
                'status' => $this->mapOrderStatus($legacyOrder->status),
                'issued_at' => $legacyOrder->issued_at ?? $legacyOrder->created_at,
                'due_at' => $legacyOrder->due_at ?? null,
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
                'amount_paid' => $this->mapOrderStatus($legacyOrder->status) === 'confirmed' ? $grandTotal : 0,
                'amount_refunded' => 0,
                'currency' => 'LYD',
                'payment_method' => 'legacy_import',
                'payment_reference' => null,
                'contact' => $contactJson,
                'parent_id' => ! empty($legacyOrder->parent_id) && in_array($legacyOrder->parent_id, $migratedOrderIds)
                    ? $legacyOrder->parent_id
                    : null,
                'ledger_entry_id' => null,
                'created_at' => $legacyOrder->created_at,
                'updated_at' => $legacyOrder->updated_at,
            ]);

            foreach ($items as $legacyItem) {
                $this->migrateOrderItem($legacyItem, $legacyOrder);
                $itemsMigrated++;
            }

            $migratedOrderIds[] = $legacyOrder->id;
            $ordersMigrated++;
        }

        $this->record->update([
            'orders_migrated' => $ordersMigrated,
            'items_migrated' => $itemsMigrated,
        ]);

        $this->addLog('orders', $ordersMigrated, $skipped);
        $this->addLog('order_items', $itemsMigrated, 0);
    }

    private function migrateOrderItem(object $legacyItem, object $legacyOrder): void
    {
        $salesRecord = $this->legacy->getOrderItemSales($legacyItem->id)->first();

        $itemDetails = $this->buildItemDetails($legacyItem, $salesRecord);

        [$newType, $productType] = $this->mapItemType($legacyItem->type ?? 'other');

        $netFare = $salesRecord?->fare_price ?? $legacyItem->price;
        $totalTax = $salesRecord?->total_tax ?? $legacyItem->taxes;
        $totalAmount = $salesRecord?->total ?? $legacyItem->total;
        $commissionPct = $salesRecord?->percentage ?? $legacyItem->net_commission ?? 0;
        $commissionAmt = $salesRecord?->total_commission ?? $legacyItem->agent_commission ?? 0;
        $netAfterComm = $salesRecord?->net_fare ?? null;

        // Validate taxes JSON
        $taxesRaw = $salesRecord?->taxes ?? null;
        if ($taxesRaw && is_string($taxesRaw)) {
            $decoded = json_decode($taxesRaw, true);
            $taxesJson = json_last_error() === JSON_ERROR_NONE
                ? $taxesRaw
                : json_encode([]);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->addWarning("Item {$legacyItem->id} has invalid taxes JSON — set to []");
            }
        } else {
            $taxesJson = json_encode([]);
        }

        if (! $salesRecord) {
            $this->addWarning("Item {$legacyItem->id} has no order_item_sales record — financial fields set to 0");
        }

        $refundStatus = in_array($legacyItem->status ?? '', ['voided', 'cancelled', 'refunded'])
            ? 'refunded'
            : 'none';

        // Resolve provider using IATA + currency from item_details, falling back to IATA only
        $iataCode = strtoupper((string) ($itemDetails['iata'] ?? ''));
        $itemCurrency = strtoupper((string) ($itemDetails['currency'] ?? ''));
        $resolvedProviderId = null;

        if ($iataCode) {
            $currencyKey = $itemCurrency ? "{$iataCode}:{$itemCurrency}" : null;
            $resolvedProviderId = ($currencyKey && isset($this->providerCache[$currencyKey]))
                ? $this->providerCache[$currencyKey]
                : ($this->providerCache[$iataCode] ?? null);
        }

        if ($iataCode && ! $resolvedProviderId) {
            $this->addWarning("Item {$legacyItem->id} IATA '{$iataCode}' not found in provider cache — provider set to legacy");
        }

        if ($resolvedProviderId) {
            $this->usedProviderIds[$resolvedProviderId] = $resolvedProviderId;
        }

        \App\Models\Tenant\OrderItem::create([
            'order_id' => $legacyOrder->id,
            'type' => $newType,
            'product_type' => $productType,
            'provider' => $resolvedProviderId ?? 'legacy',
            'provider_reference' => $legacyItem->reference ?? null,
            'ticket_number' => $salesRecord?->ticket_number ?? null,
            'item_details' => $itemDetails,
            'price' => $legacyItem->price ?? 0,
            'taxes' => $taxesJson,
            'total' => $legacyItem->total ?? 0,
            'currency' => $legacyItem->currency_code ?? 'LYD',
            'exchange_rate' => $legacyItem->exchange_rate ?? 1,
            'status' => $this->mapItemStatus($legacyItem->status ?? ''),
            'net_commission' => $legacyItem->net_commission ?? 0,
            'agent_commission' => $legacyItem->agent_commission ?? 0,
            'paid' => $legacyItem->paid ?? 0,
            'remaining' => $legacyItem->remaning ?? 0, // old DB typo
            'refund_parent_id' => null,
            'refund_status' => $refundStatus,
            'wallet_transaction_id' => null,
            'airline_transaction_id' => null,
            'net_fare' => $netFare,
            'total_tax' => $totalTax,
            'total_amount' => $totalAmount,
            'commission_percent' => $commissionPct,
            'commission_amount' => $commissionAmt,
            'net_after_commission' => $netAfterComm,
            'transaction_type' => $salesRecord?->transaction_type ?? 'purchase',
            'product_details' => [],
            'ledger_entry_id' => null,
            'used_master_agency_provider' => 0,
            'master_commission_percent' => null,
            'created_at' => $legacyItem->created_at,
            'updated_at' => $legacyItem->updated_at,
        ]);
    }

    // ── Provider Cache ─────────────────────────────────────────────────────

    /**
     * Load IATA → TenantProvider ID map from the agency tenant's DB.
     * We temporarily switch tenancy to the agency tenant, read providers,
     * then switch back to the migrated tenant.
     */
    private function loadProviderCache(string $agencyTenantId, Tenant $migratedTenant): void
    {
        $agencyTenant = Tenant::find($agencyTenantId);

        if (! $agencyTenant) {
            $this->addWarning("Agency tenant '{$agencyTenantId}' not found — provider linking disabled");

            return;
        }

        tenancy()->end();
        tenancy()->initialize($agencyTenant);

        $providers = \DB::table('tenant_providers')
            ->whereNotNull('airline_code')
            ->where('is_active', true)
            ->get(['id', 'airline_code', 'account_name']);

        foreach ($providers as $provider) {
            $iata = strtoupper($provider->airline_code);
            $currency = $this->currencyFromAccountName($provider->account_name ?? '');

            // Agency takes precedence — always overwrite
            $this->providerCache["{$iata}:{$currency}"] = $provider->id;
            $this->providerCache[$iata] = $provider->id;
        }

        tenancy()->end();
        tenancy()->initialize($migratedTenant);

        $this->addLog('load_provider_cache', count($this->providerCache), 0);
    }

    // ── Agency Network Linking ─────────────────────────────────────────────

    /**
     * Create a NetworkMembership + ProviderAllocation records in the central DB.
     * Bypasses invitation token flow since this is a landlord-initiated migration.
     */
    private function linkAgencyNetwork(Tenant $merchantTenant): void
    {
        if (! $this->agencyTenantId || empty($this->usedProviderIds)) {
            $this->addWarning('No providers used — agency network linking skipped');

            return;
        }

        // Avoid duplicate memberships
        $membership = NetworkMembership::firstOrCreate(
            [
                'agency_tenant_id' => $this->agencyTenantId,
                'merchant_tenant_id' => $merchantTenant->id,
            ],
            [
                'status' => NetworkMembership::StatusActive,
                'accepted_at' => now(),
                'invited_at' => now(),
                'created_by' => $this->record->initiated_by,
                'metadata' => ['source' => 'legacy_migration', 'migration_record_id' => $this->record->id],
            ]
        );

        if (! $membership->wasRecentlyCreated) {
            // Already exists — ensure it's active
            if ($membership->status !== NetworkMembership::StatusActive) {
                $membership->activate();
            }
        }

        $allocated = 0;

        foreach ($this->usedProviderIds as $providerId) {
            // Skip if allocation already exists (idempotent re-run)
            $exists = ProviderAllocation::where('network_membership_id', $membership->id)
                ->where('source_provider_id', $providerId)
                ->exists();

            if ($exists) {
                continue;
            }

            ProviderAllocation::create([
                'network_membership_id' => $membership->id,
                'agency_tenant_id' => $this->agencyTenantId,
                'merchant_tenant_id' => $merchantTenant->id,
                'provider_type' => 'airline',
                'provider_driver' => 'videcom',
                'provider_identity' => array_search($providerId, $this->providerCache) ?: (string) $providerId,
                'source_provider_model' => \App\Models\TenantProvider::class,
                'source_provider_id' => $providerId,
                'status' => ProviderAllocation::StatusActive,
                'is_offered_by_agency' => true,
                'is_enabled_by_merchant' => true,
                'enabled_at' => now(),
                'metadata' => ['source' => 'legacy_migration', 'migration_record_id' => $this->record->id],
            ]);

            $allocated++;
        }

        $this->addLog('link_agency_network', $allocated, 0);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildItemDetails(object $item, ?object $sales): array
    {
        $base = [];

        if (! empty($item->item)) {
            $decoded = is_string($item->item)
                ? json_decode($item->item, true)
                : (array) $item->item;
            $base = is_array($decoded) ? $decoded : [];
        }

        if ($sales) {
            $base['passenger_name'] = $sales->passenger_name ?? null;
            $base['route'] = $sales->route ?? null;
            $base['flight_number'] = $sales->flight_number ?? null;
            $base['flight_date'] = $sales->flight_date ?? null;
            $base['service_reference'] = $sales->service_reference ?? null;
            $base['coupon'] = $sales->coupon ?? null;
            $base['class'] = $sales->class ?? null;
            $base['airline_code'] = $this->extractAirlineCode($sales->flight_number ?? null);
        }

        $base['legacy_import'] = true;
        $base['legacy_type'] = strtolower($item->type ?? 'other');

        return $base;
    }

    private function extractAirlineCode(?string $flightNumber): ?string
    {
        if (! $flightNumber) {
            return null;
        }

        preg_match('/^([A-Z]{2,3})/i', $flightNumber, $m);

        return $m[1] ?? null;
    }

    private function mapOrderStatus(string $status): string
    {
        return match (strtolower($status)) {
            'confirmed' => 'confirmed',
            'cancelled', 'voided' => 'cancelled',
            'refunded' => 'cancelled',
            'pending' => 'pending',
            default => 'confirmed',
        };
    }

    private function mapItemStatus(string $status): string
    {
        return match (strtolower($status)) {
            'issued' => 'issued',
            'voided', 'cancelled' => 'voided',
            'refunded' => 'refunded',
            default => 'issued',
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function mapItemType(string $type): array
    {
        return match (strtolower($type)) {
            'flight', 'flight_ticket', 'ticket' => ['flight_ticket', 'airline'],
            'hotel' => ['hotel', 'hotel'],
            'insurance', 'travel_insurance', 'vehicle_insurance', 'orange_insurance' => ['insurance', 'insurance'],
            'esim' => ['esim', 'esim'],
            default => ['other', 'other'],
        };
    }

    /**
     * Derive a currency code from a provider account name.
     * "EUR Account" → "EUR", anything else → "LYD" (default).
     */
    private function currencyFromAccountName(string $accountName): string
    {
        // Match a 3-letter currency code at the start of the account name (e.g. "EUR Account")
        if (preg_match('/^([A-Z]{3})\s/i', trim($accountName), $m)) {
            return strtoupper($m[1]);
        }

        return 'LYD';
    }

    private function generateOrderNumber(): string
    {
        return 'LEG-'.strtoupper(substr(uniqid(), -6));
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
            'step' => $step,
            'migrated' => $migrated,
            'skipped' => $skipped,
            'at' => now()->toIso8601String(),
        ];

        $this->record->update(['log' => $this->log]);
    }

    private function addWarning(string $message): void
    {
        Log::warning('[Migration] '.$message, ['record_id' => $this->record->id]);
        $this->log[] = [
            'step' => 'warning',
            'migrated' => $message,
            'skipped' => null,
            'at' => now()->toIso8601String(),
        ];
        $this->record->update(['log' => $this->log]);
    }
}
