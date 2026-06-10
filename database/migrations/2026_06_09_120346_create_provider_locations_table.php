<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_locations', function (Blueprint $table): void {
            $table->id();

            // Which API this record comes from (e.g. '3t', 'amadeus', 'juniper')
            $table->string('provider_type', 30)->index();

            // 'city', 'hotel', 'region', 'airport', etc.
            $table->string('location_type', 30)->index();

            // The provider's own code for this record (e.g. 3T cityId or hotelCode)
            $table->string('code', 100);

            // For hotels: the parent city code; for cities: null
            $table->string('parent_code', 100)->nullable()->index();

            // Translated names — name_en is always required; ar/fr are filled by the sync command
            $table->string('name_en', 255);
            $table->string('name_ar', 255)->nullable();
            $table->string('name_fr', 255)->nullable();

            // ISO alpha2 link back to the countries table
            $table->char('country_code', 2)->nullable()->index();
            $table->unsignedSmallInteger('country_id')->nullable()->index();

            // Extra provider-specific fields (stars, address, category, …)
            $table->json('metadata')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_type', 'location_type', 'code'], 'provider_locations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_locations');
    }
};
