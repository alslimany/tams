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
        Schema::create('tenant_providers', function (Blueprint $table) {
            $table->id();
            $table->string('provider_type'); // videcom, amadeus, ndc
            $table->string('airline_code'); // YI, BM, QR, EK
            $table->string('airline_name'); // Oya Airline, Qatar Airways
            $table->string('account_name')->nullable(); // e.g. "EUR Account", "LYD Account"
            $table->text('credentials'); // Encrypted JSON
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Allow multiple accounts per airline (e.g. Medsky EUR, Medsky LYD)
            $table->unique(['provider_type', 'airline_code', 'account_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_providers');
    }
};
