<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airline_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('airline_account_id');
            $table->string('type');
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('external_reference')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('airline_account_id')->references('id')->on('airline_accounts')->cascadeOnDelete();
            $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airline_transactions');
    }
};
