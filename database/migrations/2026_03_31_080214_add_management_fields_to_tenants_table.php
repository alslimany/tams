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
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('id');
            $table->string('owner_name')->nullable()->after('company_name');
            $table->string('owner_email')->nullable()->after('owner_name');
            $table->string('owner_phone')->nullable()->after('owner_email');
            $table->string('status')->default('active')->after('owner_phone');
            $table->string('subscription_status')->default('trial')->after('status');
            $table->string('subscription_plan')->nullable()->after('subscription_status');
            $table->json('settings')->nullable()->after('subscription_plan');
            $table->timestamp('last_activity_at')->nullable()->after('settings');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'company_name',
                'owner_name',
                'owner_email',
                'owner_phone',
                'status',
                'subscription_status',
                'subscription_plan',
                'settings',
                'last_activity_at',
            ]);
        });
    }
};
