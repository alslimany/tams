<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airline_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_provider_id');
            $table->string('currency', 3);
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('external_reference')->nullable();
            $table->timestamps();

            $table->unique(['tenant_provider_id', 'currency']);
            $table->foreign('tenant_provider_id')->references('id')->on('tenant_providers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airline_accounts');
    }
};
