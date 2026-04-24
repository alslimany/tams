<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'ledger_entry_id')) {
                $table->unsignedBigInteger('ledger_entry_id')->nullable()->index()->after('payment_reference');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'product_type')) {
                $table->string('product_type')->nullable()->after('type');
            }

            if (! Schema::hasColumn('order_items', 'net_fare')) {
                $table->decimal('net_fare', 15, 2)->nullable()->after('price');
            }

            if (! Schema::hasColumn('order_items', 'total_tax')) {
                $table->decimal('total_tax', 15, 2)->nullable()->after('taxes');
            }

            if (! Schema::hasColumn('order_items', 'total_amount')) {
                $table->decimal('total_amount', 15, 2)->nullable()->after('total');
            }

            if (! Schema::hasColumn('order_items', 'commission_percent')) {
                $table->decimal('commission_percent', 5, 2)->nullable()->after('agent_commission');
            }

            if (! Schema::hasColumn('order_items', 'commission_amount')) {
                $table->decimal('commission_amount', 15, 2)->nullable()->after('commission_percent');
            }

            if (! Schema::hasColumn('order_items', 'net_after_commission')) {
                $table->decimal('net_after_commission', 15, 2)->nullable()->after('commission_amount');
            }

            if (! Schema::hasColumn('order_items', 'transaction_type')) {
                $table->string('transaction_type')->nullable()->after('status');
            }

            if (! Schema::hasColumn('order_items', 'product_details')) {
                $table->json('product_details')->nullable()->after('item_details');
            }

            if (! Schema::hasColumn('order_items', 'ledger_entry_id')) {
                $table->unsignedBigInteger('ledger_entry_id')->nullable()->index()->after('airline_transaction_id');
            }
        });

        if (Schema::hasColumn('order_items', 'taxes')) {
            DB::table('order_items')
                ->whereNotNull('taxes')
                ->whereNull('total_tax')
                ->update(['total_tax' => DB::raw('taxes')]);

            Schema::table('order_items', function (Blueprint $table) {
                $table->json('taxes')->nullable()->change();
            });
        }

        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'uuid') && Schema::hasColumn('order_items', 'wallet_transaction_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->foreign('wallet_transaction_id')
                    ->references('uuid')
                    ->on('transactions')
                    ->nullOnDelete();
            });
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['provider', 'provider_reference'], 'order_items_provider_ref_lookup_index');
            $table->index('wallet_transaction_id', 'order_items_wallet_transaction_id_index');
            $table->index('product_type', 'order_items_product_type_index');
        });
    }

    public function down(): void
    {
        try {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropForeign(['wallet_transaction_id']);
            });
        } catch (\Throwable) {
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'taxes')) {
                $table->decimal('taxes', 15, 2)->default(0)->change();
            }

            $table->dropIndex('order_items_provider_ref_lookup_index');
            $table->dropIndex('order_items_wallet_transaction_id_index');
            $table->dropIndex('order_items_product_type_index');

            $columns = [
                'product_type',
                'net_fare',
                'total_tax',
                'total_amount',
                'commission_percent',
                'commission_amount',
                'net_after_commission',
                'transaction_type',
                'product_details',
                'ledger_entry_id',
            ];

            $existingColumns = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn('order_items', $column)));

            if ($existingColumns !== []) {
                $table->dropColumn($existingColumns);
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'ledger_entry_id')) {
                $table->dropColumn('ledger_entry_id');
            }
        });
    }
};
