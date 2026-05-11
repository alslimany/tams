<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_insurance_provider_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_insurance_provider_account_id');
            $table->string('type');
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->uuid('order_id')->nullable();
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('external_reference')->nullable();
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('tenant_insurance_provider_account_id', 'tip_tx_account_fk')
                ->references('id')
                ->on('tenant_insurance_provider_accounts')
                ->cascadeOnDelete();
            $table->foreign('order_id', 'tip_tx_order_fk')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('order_item_id', 'tip_tx_order_item_fk')->references('id')->on('order_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_insurance_provider_transactions');
    }
};
