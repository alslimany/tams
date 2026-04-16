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
        Schema::table('bookings', function (Blueprint $table) {
            $table->text('ticket_number')->nullable()->after('currency');
            $table->timestamp('ticketed_at')->nullable()->after('ticket_number');
            $table->timestamp('refunded_at')->nullable()->after('ticketed_at');
            $table->json('raw_request')->nullable()->after('refunded_at');
            $table->json('raw_response')->nullable()->after('raw_request');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'ticket_number',
                'ticketed_at',
                'refunded_at',
                'raw_request',
                'raw_response',
            ]);
        });
    }
};
