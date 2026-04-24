<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_providers', 'commission_domestic')) {
                $table->decimal('commission_domestic', total: 5, places: 2)
                    ->default(0)
                    ->after('international_commission_rate');
            }

            if (! Schema::hasColumn('tenant_providers', 'commission_international')) {
                $table->decimal('commission_international', total: 5, places: 2)
                    ->default(0)
                    ->after('commission_domestic');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_providers', function (Blueprint $table) {
            $table->dropColumn([
                'commission_domestic',
                'commission_international',
            ]);
        });
    }
};
