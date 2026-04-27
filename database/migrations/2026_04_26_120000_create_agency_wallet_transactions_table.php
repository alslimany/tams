<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('default_agency_tenant_id')->nullable();
            $table->string('type'); // topup_from_admin, ticket_cost_deduction, commission_payment, settlement
            $table->string('currency', 3);
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('reference_type')->nullable(); // order_id, manual_topup
            $table->string('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('default_agency_tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->index(['tenant_id', 'currency']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_wallet_transactions');
    }
};
