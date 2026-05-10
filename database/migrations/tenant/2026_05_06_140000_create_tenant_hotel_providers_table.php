<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_hotel_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_type')->default('3t');
            $table->string('name')->default('3T Hotels');
            $table->json('credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('commission_hotel', 5, 2)->default(0);
            $table->timestamps();

            $table->unique('provider_type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_hotel_providers');
    }
};
