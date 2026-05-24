<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('office_id', 9)->nullable()->unique()->after('agency_number');
            $table->string('city_iata', 3)->nullable()->after('office_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['office_id']);
            $table->dropColumn(['office_id', 'city_iata']);
        });
    }
};
