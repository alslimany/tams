<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_membership_id')->constrained()->cascadeOnDelete();
            $table->string('agency_tenant_id', 64);
            $table->string('merchant_tenant_id', 64);
            $table->string('provider_type', 32);
            $table->string('provider_driver', 64);
            $table->string('provider_identity', 128);
            $table->string('source_provider_model', 191);
            $table->unsignedBigInteger('source_provider_id');
            $table->string('status', 32)->default('active');
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->decimal('markup_rate', 5, 2)->nullable();
            $table->json('limits')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('removal_requested_at')->nullable();
            $table->timestamp('removal_approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('agency_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('merchant_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['network_membership_id', 'status']);
            $table->index(['agency_tenant_id', 'source_provider_model', 'source_provider_id'], 'provider_allocations_source_idx');
            $table->index(['merchant_tenant_id', 'provider_type', 'provider_driver', 'provider_identity', 'status'], 'provider_allocations_logical_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_allocations');
    }
};
