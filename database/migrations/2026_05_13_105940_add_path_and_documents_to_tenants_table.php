<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('path')->nullable()->unique()->after('id')
                ->comment('URL-safe identifier for path-based tenancy, e.g. "atlas-travel"');
            $table->string('commercial_register_path')->nullable()->after('owner_phone')
                ->comment('Uploaded commercial register document path');
            $table->string('passport_path')->nullable()->after('commercial_register_path')
                ->comment('Uploaded admin passport document path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['path', 'commercial_register_path', 'passport_path']);
        });
    }
};
