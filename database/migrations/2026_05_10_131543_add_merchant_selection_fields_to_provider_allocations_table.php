<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('provider_allocations', function (Blueprint $table) {
            $table->string('merchant_tenant_id')->nullable()->change();
            $table->boolean('is_offered_by_agency')->default(true)->after('status');
            $table->boolean('is_enabled_by_merchant')->default(true)->after('is_offered_by_agency');
            $table->timestamp('enabled_at')->nullable()->after('is_enabled_by_merchant');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_allocations', function (Blueprint $table) {
            $table->dropColumn([
                'is_offered_by_agency',
                'is_enabled_by_merchant',
                'enabled_at',
            ]);
            $table->string('merchant_tenant_id')->nullable(false)->change();
        });
    }
};
