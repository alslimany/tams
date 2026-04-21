<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->morphs('owner');
            $table->string('number', 20)->unique();
            $table->string('status')->default('pending');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->decimal('subtotal', 15, 2);
            $table->decimal('tax_total', 15, 2);
            $table->decimal('grand_total', 15, 2);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('amount_refunded', 15, 2)->default(0);
            $table->string('currency', 3);
            $table->string('payment_method');
            $table->string('payment_reference')->nullable();
            $table->json('contact')->nullable();
            $table->uuid('parent_id')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
