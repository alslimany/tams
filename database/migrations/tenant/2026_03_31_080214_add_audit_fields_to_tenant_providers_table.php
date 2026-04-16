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
            $table->timestamp('last_tested_at')->nullable()->after('is_active');
            $table->string('last_test_status')->nullable()->after('last_tested_at');
            $table->text('last_test_message')->nullable()->after('last_test_status');
            $table->timestamp('last_used_at')->nullable()->after('last_test_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_providers', function (Blueprint $table) {
            $table->dropColumn([
                'last_tested_at',
                'last_test_status',
                'last_test_message',
                'last_used_at',
            ]);
        });
    }
};
