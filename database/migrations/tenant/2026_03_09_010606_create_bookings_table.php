<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pnr')->index();
            $table->foreignId('tenant_provider_id')->constrained();
            $table->enum('status', ['pending', 'confirmed', 'ticketed', 'cancelled', 'refunded'])->default('pending');
            $table->decimal('total_price', 10, 2);
            $table->string('currency', 3);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
