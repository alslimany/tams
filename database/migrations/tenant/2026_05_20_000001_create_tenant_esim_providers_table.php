<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_esim_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_type')->default('l2');
            $table->string('name')->default('L2 Travel eSIM');
            $table->json('credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('commission_esim', 5, 2)->default(0);
            $table->timestamps();

            $table->unique('provider_type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_esim_providers');
    }
};
