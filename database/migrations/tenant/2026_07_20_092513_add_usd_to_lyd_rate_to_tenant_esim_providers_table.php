<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_esim_providers', function (Blueprint $table): void {
            $table->decimal('usd_to_lyd_rate', 12, 4)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_esim_providers', function (Blueprint $table): void {
            $table->dropColumn('usd_to_lyd_rate');
        });
    }
};
