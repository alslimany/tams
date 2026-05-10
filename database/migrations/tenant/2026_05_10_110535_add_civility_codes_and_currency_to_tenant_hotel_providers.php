<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_hotel_providers', function (Blueprint $table): void {
            $table->string('currency', 10)->default('LYD')->after('commission_hotel');
            $table->json('civility_codes')->nullable()->after('currency');
        });

        DB::table('tenant_hotel_providers')
            ->where('provider_type', '3t')
            ->where(function ($query): void {
                $query->whereNull('currency')->orWhere('currency', 'USD');
            })
            ->update(['currency' => 'LYD']);
    }

    public function down(): void
    {
        Schema::table('tenant_hotel_providers', function (Blueprint $table): void {
            $table->dropColumn(['currency', 'civility_codes']);
        });
    }
};
