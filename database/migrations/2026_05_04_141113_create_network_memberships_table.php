<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_memberships', function (Blueprint $table) {
            $table->id();
            $table->string('agency_tenant_id');
            $table->string('merchant_tenant_id');
            $table->string('invitation_token')->unique();
            $table->string('invitation_code', 32)->unique();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('removal_requested_at')->nullable();
            $table->timestamp('removal_approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('landlord_users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('agency_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('merchant_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['agency_tenant_id', 'status']);
            $table->index(['merchant_tenant_id', 'status']);
            $table->index(['agency_tenant_id', 'merchant_tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_memberships');
    }
};
