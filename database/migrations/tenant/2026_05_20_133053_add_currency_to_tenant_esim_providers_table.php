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
        Schema::table('tenant_esim_providers', function (Blueprint $table): void {
            $table->string('currency', 10)->default('USD')->after('commission_esim');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_esim_providers', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
