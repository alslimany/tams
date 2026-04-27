<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('buyer_tenant_id');
            $table->string('default_agency_tenant_id');
            $table->string('currency', 3);
            $table->decimal('total_commission', 15, 2);
            $table->unsignedInteger('transaction_count');
            $table->timestamp('period_started_at')->nullable();
            $table->timestamp('period_ended_at')->nullable();
            $table->string('status')->default('recorded');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('buyer_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('default_agency_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['buyer_tenant_id', 'default_agency_tenant_id']);
            $table->index(['status', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_settlements');
    }
};
