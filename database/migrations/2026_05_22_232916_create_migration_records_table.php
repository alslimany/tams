<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_records', function (Blueprint $table) {
            $table->id();
            $table->integer('legacy_agent_id');
            $table->string('legacy_agent_name');
            $table->string('legacy_agent_number')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('status')->default('pending'); // pending|running|completed|failed
            $table->string('initiated_by');
            $table->json('options')->nullable();
            $table->json('log')->nullable();
            $table->text('error')->nullable();
            $table->integer('orders_migrated')->default(0);
            $table->integer('items_migrated')->default(0);
            $table->integer('customers_migrated')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_records');
    }
};
