<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('flight_number');
            $table->string('origin_airport', 3);
            $table->string('destination_airport', 3);
            $table->dateTime('departure_time');
            $table->dateTime('arrival_time');
            $table->enum('status', ['confirmed', 'waitlisted', 'cancelled'])->default('confirmed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_segments');
    }
};
