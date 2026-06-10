<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_reference_items', function (Blueprint $table): void {
            $table->id();

            // Which API this record comes from (e.g. '3t', 'amadeus')
            $table->string('provider_type', 30)->index();

            // 'board_type', 'room_type', 'facility', 'rating', etc.
            $table->string('item_type', 30)->index();

            // The provider's own code (e.g. 'BB', 'HB', 'AI')
            $table->string('code', 100);

            // Translated labels
            $table->string('name_en', 255);
            $table->string('name_ar', 255)->nullable();
            $table->string('name_fr', 255)->nullable();

            // Extra fields for this item type
            $table->json('metadata')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_type', 'item_type', 'code'], 'provider_ref_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_reference_items');
    }
};
