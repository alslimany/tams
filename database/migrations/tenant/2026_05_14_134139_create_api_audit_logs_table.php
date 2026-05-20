<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('abilities')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_audit_logs');
    }
};
