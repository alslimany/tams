<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('tickets') && Schema::hasColumn('tickets', 'booking_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('booking_id');
            });
        }

        if (Schema::hasTable('passengers') && Schema::hasColumn('passengers', 'booking_id')) {
            Schema::table('passengers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('booking_id');
            });
        }

        Schema::dropIfExists('flight_segments');
        Schema::dropIfExists('bookings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('bookings')) {
            Schema::create('bookings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->string('pnr')->index();
                $table->foreignId('tenant_provider_id')->constrained();
                $table->enum('status', ['pending', 'confirmed', 'ticketed', 'cancelled', 'refunded'])->default('pending');
                $table->decimal('total_price', 10, 2);
                $table->string('currency', 3);
                $table->text('ticket_number')->nullable();
                $table->timestamp('ticketed_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->json('raw_request')->nullable();
                $table->json('raw_response')->nullable();
                $table->foreignId('created_by')->constrained('users');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('flight_segments')) {
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

        if (Schema::hasTable('passengers') && ! Schema::hasColumn('passengers', 'booking_id')) {
            Schema::table('passengers', function (Blueprint $table) {
                $table->foreignId('booking_id')->nullable()->after('id');
            });

            DB::table('passengers')->update(['booking_id' => null]);

            Schema::table('passengers', function (Blueprint $table) {
                $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
            });
        }

        if (Schema::hasTable('tickets') && ! Schema::hasColumn('tickets', 'booking_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->foreignId('booking_id')->nullable()->after('id');
            });

            DB::table('tickets')->update(['booking_id' => null]);

            Schema::table('tickets', function (Blueprint $table) {
                $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
            });
        }
    }
};
