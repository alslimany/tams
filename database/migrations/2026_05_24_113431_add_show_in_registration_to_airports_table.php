<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airports', function (Blueprint $table): void {
            $table->boolean('show_in_registration')->default(false)->after('type');
        });

        // Mark all large airports that have an IATA code as visible in registration
        DB::table('airports')
            ->where('type', 'large_airport')
            ->whereNotNull('iata_code')
            ->update(['show_in_registration' => true]);
    }

    public function down(): void
    {
        Schema::table('airports', function (Blueprint $table): void {
            $table->dropColumn('show_in_registration');
        });
    }
};
