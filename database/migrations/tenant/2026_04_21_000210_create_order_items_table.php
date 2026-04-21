<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_id');
            $table->string('type');
            $table->string('product_subtype')->nullable();
            $table->string('provider');
            $table->string('provider_reference')->nullable();
            $table->string('ticket_number')->nullable();
            $table->json('item_details');
            $table->decimal('price', 15, 2);
            $table->decimal('taxes', 15, 2);
            $table->decimal('total', 15, 2);
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 10, 6)->default(1);
            $table->string('status')->default('issued');
            $table->decimal('net_commission', 15, 2)->nullable();
            $table->decimal('agent_commission', 15, 2)->nullable();
            $table->decimal('paid', 15, 2)->default(0);
            $table->decimal('remaining', 15, 2)->default(0);
            $table->uuid('refund_parent_id')->nullable();
            $table->string('refund_status')->default('none');
            $table->uuid('wallet_transaction_id')->nullable();
            $table->unsignedBigInteger('airline_transaction_id')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->index('provider_reference');
            $table->index('ticket_number');
            $table->index('airline_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
