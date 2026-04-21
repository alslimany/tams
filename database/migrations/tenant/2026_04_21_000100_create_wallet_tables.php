<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('payable');
            $table->unsignedBigInteger('wallet_id');
            $table->enum('type', ['deposit', 'withdraw'])->index();
            $table->decimal('amount', 64, 0);
            $table->boolean('confirmed');
            $table->json('meta')->nullable();
            $table->uuid('uuid')->unique();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id'], 'payable_type_payable_id_ind');
            $table->index(['payable_type', 'payable_id', 'type'], 'payable_type_ind');
            $table->index(['payable_type', 'payable_id', 'confirmed'], 'payable_confirmed_ind');
            $table->index(['payable_type', 'payable_id', 'type', 'confirmed'], 'payable_type_confirmed_ind');
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('holder');
            $table->string('name');
            $table->string('slug')->index();
            $table->uuid('uuid')->unique();
            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->decimal('balance', 64, 0)->default(0);
            $table->unsignedSmallInteger('decimal_places')->default(2);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['holder_type', 'holder_id', 'slug']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('from');
            $table->morphs('to');
            $table->enum('status', ['exchange', 'transfer', 'paid', 'refund', 'gift'])->default('transfer');
            $table->enum('status_last', ['exchange', 'transfer', 'paid', 'refund', 'gift'])->nullable();
            $table->unsignedBigInteger('deposit_id');
            $table->unsignedBigInteger('withdraw_id');
            $table->decimal('discount', 64, 0)->default(0);
            $table->decimal('fee', 64, 0)->default(0);
            $table->uuid('uuid')->unique();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('deposit_id')->references('id')->on('transactions')->cascadeOnDelete();
            $table->foreign('withdraw_id')->references('id')->on('transactions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('transactions');
    }
};
