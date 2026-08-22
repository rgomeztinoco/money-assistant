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
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('direction', 8)->default('debit')->after('kind');
            $table->string('income_source', 32)->nullable()->after('direction');
            $table->string('transfer_purpose', 32)->nullable()->after('income_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['direction', 'income_source', 'transfer_purpose']);
        });
    }
};
