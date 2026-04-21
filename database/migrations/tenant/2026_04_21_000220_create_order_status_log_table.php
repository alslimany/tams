<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_id');
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_log');
    }
};
