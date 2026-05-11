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
        Schema::table('network_memberships', function (Blueprint $table) {
            $table->string('merchant_tenant_id')->nullable()->change();
            $table->string('merchant_email')->nullable()->after('merchant_tenant_id');
            $table->string('merchant_contact_name')->nullable()->after('merchant_email');
            $table->timestamp('invited_at')->nullable()->after('expires_at');
            $table->timestamp('suspended_at')->nullable()->after('accepted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('network_memberships', function (Blueprint $table) {
            $table->dropColumn([
                'merchant_email',
                'merchant_contact_name',
                'invited_at',
                'suspended_at',
            ]);
            $table->string('merchant_tenant_id')->nullable(false)->change();
        });
    }
};
