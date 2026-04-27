<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_wallet_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('settlement_id')->nullable()->after('admin_id');
            $table->timestamp('settled_at')->nullable()->after('settlement_id');

            $table->index(['type', 'settlement_id']);
            $table->index(['tenant_id', 'default_agency_tenant_id', 'currency'], 'agency_wallet_tenant_default_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::table('agency_wallet_transactions', function (Blueprint $table) {
            $table->dropIndex(['type', 'settlement_id']);
            $table->dropIndex('agency_wallet_tenant_default_currency_idx');
            $table->dropColumn(['settlement_id', 'settled_at']);
        });
    }
};
