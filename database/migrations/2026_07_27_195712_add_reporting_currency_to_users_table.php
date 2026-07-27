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
        Schema::table('users', function (Blueprint $table) {
            $table->string('reporting_currency', 3)->nullable();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_reporting_currency_supported CHECK (reporting_currency IS NULL OR reporting_currency IN ('USD', 'PEN'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_reporting_currency_supported');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reporting_currency');
        });
    }
};
