<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'is_default_agency')) {
                $table->boolean('is_default_agency')->default(false)->after('last_activity_at');
            }

            if (! Schema::hasColumn('tenants', 'master_commission_rate')) {
                $table->decimal('master_commission_rate', 5, 2)->default(0)->after('is_default_agency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['is_default_agency', 'master_commission_rate']);
        });
    }
};
