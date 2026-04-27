<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'used_master_agency_provider')) {
                $table->boolean('used_master_agency_provider')->default(false)->after('ledger_entry_id');
            }

            if (! Schema::hasColumn('order_items', 'master_commission_percent')) {
                $table->decimal('master_commission_percent', 5, 2)->nullable()->after('used_master_agency_provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $columns = [
                'used_master_agency_provider',
                'master_commission_percent',
            ];

            $existingColumns = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn('order_items', $column)));

            if ($existingColumns !== []) {
                $table->dropColumn($existingColumns);
            }
        });
    }
};
