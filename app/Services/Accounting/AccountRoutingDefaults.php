<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountRoutingDefaults
{
    /**
     * The default account routing rows seeded for every tenant.
     *
     * @return list<array{event_type: string, event_category: string, debit_account: ?string, credit_account: ?string, description: string}>
     */
    public static function rows(): array
    {
        $rows = [];

        $productDefaults = [
            'airline' => ['revenue' => '4100', 'tax' => '2410', 'cost' => '5100', 'wallet' => '1210'],
            'hotel' => ['revenue' => '4200', 'tax' => '2420', 'cost' => '5200', 'wallet' => '1220'],
            'insurance' => ['revenue' => '4300', 'tax' => '2410', 'cost' => '5300', 'wallet' => '1230'],
            'esim' => ['revenue' => '4400', 'tax' => '2410', 'cost' => '5400', 'wallet' => '1240'],
            'general' => ['revenue' => '4500', 'tax' => '2410', 'cost' => '5500', 'wallet' => '1200'],
        ];

        foreach ($productDefaults as $category => $accounts) {
            $rows[] = ['event_type' => 'sale_customer_receivable', 'event_category' => $category, 'debit_account' => '1310', 'credit_account' => null, 'description' => 'Customer owes full selling price'];
            $rows[] = ['event_type' => 'sale_revenue', 'event_category' => $category, 'debit_account' => null, 'credit_account' => $accounts['revenue'], 'description' => 'Sales revenue'];
            $rows[] = ['event_type' => 'sale_margin', 'event_category' => $category, 'debit_account' => null, 'credit_account' => '4500', 'description' => 'Commission / markup earned'];
            $rows[] = ['event_type' => 'sale_tax_payable', 'event_category' => $category, 'debit_account' => null, 'credit_account' => $accounts['tax'], 'description' => 'Taxes collected on sale'];
            $rows[] = ['event_type' => 'cost_provider', 'event_category' => $category, 'debit_account' => $accounts['cost'], 'credit_account' => null, 'description' => 'Provider cost (COGS)'];
            $rows[] = ['event_type' => 'cost_provider_wallet', 'event_category' => $category, 'debit_account' => null, 'credit_account' => $accounts['wallet'], 'description' => 'Provider wallet deducted'];

            $rows[] = ['event_type' => 'void_customer_receivable', 'event_category' => $category, 'debit_account' => null, 'credit_account' => '1310', 'description' => 'Reverse receivable on void'];
            $rows[] = ['event_type' => 'void_revenue', 'event_category' => $category, 'debit_account' => $accounts['revenue'], 'credit_account' => null, 'description' => 'Reverse revenue on void'];
            $rows[] = ['event_type' => 'void_margin', 'event_category' => $category, 'debit_account' => '4500', 'credit_account' => null, 'description' => 'Reverse margin on void'];
            $rows[] = ['event_type' => 'void_tax_payable', 'event_category' => $category, 'debit_account' => $accounts['tax'], 'credit_account' => null, 'description' => 'Reverse tax payable on void'];
            $rows[] = ['event_type' => 'void_cost_provider', 'event_category' => $category, 'debit_account' => null, 'credit_account' => $accounts['cost'], 'description' => 'Reverse cost on void'];
            $rows[] = ['event_type' => 'void_provider_wallet', 'event_category' => $category, 'debit_account' => $accounts['wallet'], 'credit_account' => null, 'description' => 'Restore provider wallet on void'];
            $rows[] = ['event_type' => 'cancellation_fee', 'event_category' => $category, 'debit_account' => '1310', 'credit_account' => '4700', 'description' => 'Retain cancellation fee'];
        }

        // Issuance / merchant / network / settlement events (wallet-driven flows).
        $rows[] = ['event_type' => 'issuance_operating_wallet', 'event_category' => 'general', 'debit_account' => '1110', 'credit_account' => null, 'description' => 'Customer payment received into operating wallet'];
        $rows[] = ['event_type' => 'issuance_vat_payable', 'event_category' => 'general', 'debit_account' => null, 'credit_account' => '2400', 'description' => 'VAT collected on issuance'];
        $rows[] = ['event_type' => 'merchant_wholesale_cost', 'event_category' => 'general', 'debit_account' => '5500', 'credit_account' => null, 'description' => 'Merchant wholesale cost (COGS)'];
        $rows[] = ['event_type' => 'network_agency_payable', 'event_category' => 'general', 'debit_account' => null, 'credit_account' => '2200', 'description' => 'Wholesale price owed to network agency'];
        $rows[] = ['event_type' => 'merchant_receivable', 'event_category' => 'general', 'debit_account' => '1320', 'credit_account' => null, 'description' => 'Wholesale price due from merchant'];
        $rows[] = ['event_type' => 'network_commission_income', 'event_category' => 'general', 'debit_account' => null, 'credit_account' => '4600', 'description' => 'Network commission income'];
        $rows[] = ['event_type' => 'settlement_merchant', 'event_category' => 'general', 'debit_account' => '2200', 'credit_account' => '1120', 'description' => 'Merchant settles payable to network agency'];
        $rows[] = ['event_type' => 'settlement_agency', 'event_category' => 'general', 'debit_account' => '1110', 'credit_account' => '1320', 'description' => 'Agency clears merchant receivable'];

        // Inventory + purchases.
        $rows[] = ['event_type' => 'inventory_receive', 'event_category' => 'inventory', 'debit_account' => '1420', 'credit_account' => '2510', 'description' => 'Receive goods: debit stock, credit payable'];
        $rows[] = ['event_type' => 'inventory_deliver', 'event_category' => 'inventory', 'debit_account' => '5000', 'credit_account' => '1420', 'description' => 'Deliver goods: debit COGS, credit stock'];
        $rows[] = ['event_type' => 'inventory_transfer', 'event_category' => 'inventory', 'debit_account' => '1420', 'credit_account' => '1420', 'description' => 'Transfer between warehouses'];
        $rows[] = ['event_type' => 'purchase', 'event_category' => 'general', 'debit_account' => '6060', 'credit_account' => '2510', 'description' => 'General purchase'];

        return $rows;
    }

    /**
     * Seed the default routing rows for the current tenant connection.
     * With $force = true, existing rows are overwritten (Reset to Defaults).
     *
     * @return int Number of rows created or reset.
     */
    public function seed(bool $force = false): int
    {
        if (! Schema::hasTable('account_routing')) {
            return 0;
        }

        $affected = 0;

        foreach (self::rows() as $row) {
            $exists = DB::table('account_routing')
                ->where('event_type', $row['event_type'])
                ->where('event_category', $row['event_category'])
                ->exists();

            if ($exists && ! $force) {
                continue;
            }

            if ($exists) {
                DB::table('account_routing')
                    ->where('event_type', $row['event_type'])
                    ->where('event_category', $row['event_category'])
                    ->update([
                        'debit_account' => $row['debit_account'],
                        'credit_account' => $row['credit_account'],
                        'description' => $row['description'],
                        'is_system' => true,
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('account_routing')->insert($row + [
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $affected++;
        }

        return $affected;
    }
}
