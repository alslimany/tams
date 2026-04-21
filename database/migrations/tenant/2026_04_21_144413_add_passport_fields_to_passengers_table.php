<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('passengers', 'passport_issue_country')) {
            Schema::table('passengers', function (Blueprint $table): void {
                $table->string('passport_issue_country', 3)->nullable()->after('passport_expiry');
            });
        }

        if (! Schema::hasColumn('passengers', 'nationality')) {
            Schema::table('passengers', function (Blueprint $table): void {
                $table->string('nationality', 3)->nullable()->after('passport_issue_country');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('passengers', 'nationality')) {
            Schema::table('passengers', function (Blueprint $table): void {
                $table->dropColumn('nationality');
            });
        }

        if (Schema::hasColumn('passengers', 'passport_issue_country')) {
            Schema::table('passengers', function (Blueprint $table): void {
                $table->dropColumn('passport_issue_country');
            });
        }
    }
};
