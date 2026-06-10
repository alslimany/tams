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
        Schema::table('countries', function (Blueprint $table) {
            $table->boolean('esim_featured')->default(false)->after('name_fr');
        });

        // Seed the initial four featured countries
        DB::table('countries')
            ->whereIn('alpha2', ['TR', 'TN', 'EG', 'IT'])
            ->update(['esim_featured' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn('esim_featured');
        });
    }
};
