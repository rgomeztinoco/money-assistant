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
        Schema::table('gmail_connections', function (Blueprint $table) {
            $table->string('history_id')->nullable();
            $table->timestampTz('initial_sync_completed_at')->nullable();
            $table->timestampTz('last_successful_sync_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gmail_connections', function (Blueprint $table) {
            $table->dropColumn([
                'history_id',
                'initial_sync_completed_at',
                'last_successful_sync_at',
            ]);
        });
    }
};
