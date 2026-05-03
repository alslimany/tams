<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_insurance_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_type')->default('albaraka');
            $table->string('name')->default('Al Baraka Insurance');
            $table->json('credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('commission_compulsory', 5, 2)->default(0);
            $table->decimal('commission_travel', 5, 2)->default(0);
            $table->decimal('commission_orange', 5, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_insurance_providers');
    }
};
