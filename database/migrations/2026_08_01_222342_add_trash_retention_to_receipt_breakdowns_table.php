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
        Schema::table('receipt_breakdowns', function (Blueprint $table) {
            $table->uuid('deletion_id')->nullable()->unique();
            $table->timestampTz('purge_after')->nullable()->index();
            $table->softDeletesTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipt_breakdowns', function (Blueprint $table) {
            $table->dropSoftDeletesTz();
            $table->dropColumn(['deletion_id', 'purge_after']);
        });
    }
};
