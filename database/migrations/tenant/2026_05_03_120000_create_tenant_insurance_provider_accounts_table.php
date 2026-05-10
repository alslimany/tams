<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_insurance_provider_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_insurance_provider_id');
            $table->string('currency', 3)->default('LYD');
            $table->decimal('balance', 15, 2)->default(0);
            $table->timestamps();

            $table->foreign('tenant_insurance_provider_id', 'tip_accounts_provider_fk')
                ->references('id')
                ->on('tenant_insurance_providers')
                ->cascadeOnDelete();
            $table->unique(['tenant_insurance_provider_id', 'currency'], 'tip_accounts_provider_currency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_insurance_provider_accounts');
    }
};
