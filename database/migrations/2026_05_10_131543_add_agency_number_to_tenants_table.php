<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'agency_number')) {
                $table->string('agency_number')->nullable()->after('id');
            }
        });

        $nextNumber = 100001;

        DB::table('tenants')
            ->whereNull('agency_number')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (object $tenant) use (&$nextNumber): void {
                DB::table('tenants')
                    ->where('id', $tenant->id)
                    ->update(['agency_number' => sprintf('AG-%06d', $nextNumber)]);

                $nextNumber++;
            });

        Schema::table('tenants', function (Blueprint $table) {
            $table->unique('agency_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'agency_number')) {
                $table->dropUnique(['agency_number']);
                $table->dropColumn('agency_number');
            }
        });
    }
};
