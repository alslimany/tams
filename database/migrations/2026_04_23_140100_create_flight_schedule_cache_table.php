<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_schedule_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('airline_code', 10);
            $table->string('origin', 3);
            $table->string('destination', 3);
            $table->date('flight_date');
            $table->string('booking_class', 10)->nullable();
            $table->decimal('lowest_price', 12, 2);
            $table->string('currency', 3);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['airline_code', 'origin', 'destination', 'flight_date', 'booking_class'], 'flight_schedule_cache_unique');
            $table->index(['origin', 'destination', 'flight_date'], 'flight_schedule_cache_route_date_idx');
            $table->index(['airline_code', 'origin', 'destination'], 'flight_schedule_cache_airline_route_idx');
            $table->index('expires_at', 'flight_schedule_cache_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_schedule_cache');
    }
};
