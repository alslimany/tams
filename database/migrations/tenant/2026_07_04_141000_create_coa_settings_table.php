<?php

use App\Services\Accounting\CoaSettingsSyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coa_settings', function (Blueprint $table) {
            $table->id();
            $table->string('ledger_uuid');
            $table->string('code')->unique();
            $table->string('display_name');
            $table->string('account_type');
            $table->string('parent_code')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        app(CoaSettingsSyncService::class)->syncFromLedger(markSystem: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('coa_settings');
    }
};
