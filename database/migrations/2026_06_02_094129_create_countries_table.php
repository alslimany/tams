<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('alpha2', 2)->unique();
            $table->string('alpha3', 3)->unique();
            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->string('name_fr')->nullable();
            $table->timestamps();

            $table->index('name_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
