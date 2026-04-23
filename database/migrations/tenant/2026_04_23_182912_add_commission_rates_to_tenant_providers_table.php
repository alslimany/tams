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
        Schema::table('tenant_providers', function (Blueprint $table) {
            $table->decimal('domestic_commission_rate', total: 5, places: 2)->nullable()->after('last_used_at');
            $table->decimal('international_commission_rate', total: 5, places: 2)->nullable()->after('domestic_commission_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_providers', function (Blueprint $table) {
            $table->dropColumn([
                'domestic_commission_rate',
                'international_commission_rate',
            ]);
        });
    }
};
