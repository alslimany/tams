<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('channel'); // whatsapp, mail
            $table->string('event');   // order_issued, order_cancelled, order_voided, hotel_booked, policy_issued
            $table->string('recipient');
            $table->text('message')->nullable();
            $table->string('status'); // sent, failed, skipped
            $table->string('error')->nullable();
            $table->nullableUlidMorphs('notifiable'); // polymorphic: Order, OrderItem, etc.
            $table->timestamps();

            $table->index('channel');
            $table->index('event');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
