<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillMigratedOrderItems extends Command
{
    protected $signature = 'migration:backfill-order-items
                            {tenant? : Tenant ID to backfill (omit for all tenants)}
                            {--dry-run : Show what would change without writing}';

    protected $description = 'Re-map type/product_type and provider on legacy-imported order items';

    public function handle(): int
    {
        $tenantId = $this->argument('tenant');
        $dryRun = $this->option('dry-run');

        $tenants = $tenantId
            ? Tenant::where('id', $tenantId)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->error('No tenants found.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $this->info("Processing tenant: {$tenant->id} ({$tenant->company_name})");
            tenancy()->initialize($tenant);

            $this->backfillTenant($dryRun);

            tenancy()->end();
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function backfillTenant(bool $dryRun): void
    {
        // Build provider cache: "IATA:CURRENCY" and plain "IATA" fallback
        $providerCache = [];
        $providers = DB::table('tenant_providers')
            ->whereNotNull('airline_code')
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'airline_code', 'account_name']);

        foreach ($providers as $provider) {
            $iata = strtoupper($provider->airline_code);
            $currency = $this->currencyFromAccountName($provider->account_name ?? '');
            $currencyKey = "{$iata}:{$currency}";

            if (! isset($providerCache[$currencyKey])) {
                $providerCache[$currencyKey] = $provider->id;
            }
            if (! isset($providerCache[$iata])) {
                $providerCache[$iata] = $provider->id;
            }
        }

        // Touch items that were legacy-imported (flag present) OR items that look like
        // legacy imports but were created before the legacy_import flag was added
        // (type = 'other' with an IATA code present in item_details).
        $items = DB::table('order_items')
            ->where(function ($q) {
                $q->whereRaw("json_extract(item_details, '$.legacy_import') = 1")
                    ->orWhereRaw("type = 'other' AND json_extract(item_details, '$.iata') IS NOT NULL");
            })
            ->get(['id', 'type', 'product_type', 'provider', 'item_details']);

        $updated = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $details = json_decode($item->item_details, true) ?? [];
            $iata = strtoupper((string) ($details['iata'] ?? ''));
            $currency = strtoupper((string) ($details['currency'] ?? ''));

            // Resolve correct type.
            // If legacy_type was stored (new migrations), use it directly.
            // If absent (old migrations), fall back: IATA present = ticket, otherwise skip re-mapping.
            $legacyType = strtolower((string) ($details['legacy_type'] ?? ''));
            if ($legacyType === '') {
                if ($iata !== '') {
                    $legacyType = 'ticket';
                } else {
                    // No legacy_type and no IATA — can't safely remap type; leave as-is
                    [$newType, $newProductType] = [$item->type, $item->product_type];
                }
            }

            if ($legacyType !== '') {
                [$newType, $newProductType] = $this->mapItemType($legacyType);
            }

            // Resolve correct provider
            $resolvedProvider = 'legacy';
            if ($iata) {
                $currencyKey = $currency ? "{$iata}:{$currency}" : null;
                $resolvedProvider = ($currencyKey && isset($providerCache[$currencyKey]))
                    ? $providerCache[$currencyKey]
                    : ($providerCache[$iata] ?? 'legacy');
            }

            $typeChanged = $item->type !== $newType || $item->product_type !== $newProductType;
            $providerChanged = (string) $item->provider !== (string) $resolvedProvider;

            if (! $typeChanged && ! $providerChanged) {
                $skipped++;

                continue;
            }

            $this->line(sprintf(
                '  Item %d: type %s→%s, product_type %s→%s, provider %s→%s%s',
                $item->id,
                $item->type, $newType,
                $item->product_type, $newProductType,
                $item->provider, $resolvedProvider,
                $dryRun ? ' [dry-run]' : '',
            ));

            if (! $dryRun) {
                DB::table('order_items')->where('id', $item->id)->update([
                    'type' => $newType,
                    'product_type' => $newProductType,
                    'provider' => $resolvedProvider,
                ]);
            }

            $updated++;
        }

        $this->line("  → {$updated} updated, {$skipped} already correct");
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function mapItemType(string $type): array
    {
        return match ($type) {
            'flight', 'flight_ticket', 'ticket' => ['flight_ticket', 'airline'],
            'hotel' => ['hotel', 'hotel'],
            'insurance', 'travel_insurance', 'vehicle_insurance', 'orange_insurance' => ['insurance', 'insurance'],
            'esim' => ['esim', 'esim'],
            default => ['other', 'other'],
        };
    }

    private function currencyFromAccountName(string $accountName): string
    {
        if (preg_match('/^([A-Z]{3})\s/i', trim($accountName), $m)) {
            return strtoupper($m[1]);
        }

        return 'LYD';
    }
}
