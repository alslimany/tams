<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('default_agency_settings', function (Blueprint $table) {
            $table->id();
            $table->string('default_agency_tenant_id');
            $table->decimal('master_commission_percent', 5, 2)->default(0);
            $table->json('allowed_airline_codes')->nullable();
            $table->timestamps();

            $table->foreign('default_agency_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('default_agency_settings');
    }
};
