<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('type')->default('physical');
            $table->string('address')->nullable();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category')->default('physical_good');
            $table->string('unit')->default('piece');
            $table->decimal('unit_cost', 12, 3)->default(0);
            $table->string('inventory_account')->default('1420');
            $table->string('cogs_account')->default('5000');
            $table->string('purchase_account')->default('6050');
            $table->boolean('track_quantity')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('inventory_warehouses')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('avg_unit_cost', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'item_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('reference')->unique();
            $table->foreignId('item_id')->constrained('inventory_items');
            $table->foreignId('from_warehouse_id')->nullable()->constrained('inventory_warehouses');
            $table->foreignId('to_warehouse_id')->nullable()->constrained('inventory_warehouses');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 12, 3);
            $table->decimal('total_cost', 12, 3);
            $table->string('supplier')->nullable();
            $table->string('order_id')->nullable();
            $table->string('notes')->nullable();
            $table->integer('ledger_entry_id')->nullable();
            $table->string('status')->default('posted');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('movement_date');
            $table->timestamps();

            $table->index(['type', 'movement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_stock');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventory_warehouses');
    }
};
