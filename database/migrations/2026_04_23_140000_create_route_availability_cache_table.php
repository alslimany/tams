<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_availability_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('airline_code', 10);
            $table->string('origin', 3);
            $table->string('destination', 3);
            $table->boolean('has_flights')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('consecutive_empty')->default(0);
            $table->timestamps();

            $table->unique(['airline_code', 'origin', 'destination'], 'route_availability_cache_unique');
            $table->index(['origin', 'destination'], 'route_availability_cache_route_idx');
            $table->index(['airline_code', 'origin', 'destination'], 'route_availability_cache_airline_route_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_availability_cache');
    }
};
