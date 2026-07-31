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
        Schema::table('parser_profiles', function (Blueprint $table) {
            $table->foreignId('security_alert_reminder_id')
                ->nullable()
                ->constrained('reminders')
                ->nullOnDelete();
            $table->uuid('security_alert_resolution_idempotency_key')->nullable();
            $table->foreignId('drift_alert_reminder_id')
                ->nullable()
                ->constrained('reminders')
                ->nullOnDelete();
            $table->uuid('drift_alert_resolution_idempotency_key')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parser_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('security_alert_reminder_id');
            $table->dropColumn('security_alert_resolution_idempotency_key');
            $table->dropConstrainedForeignId('drift_alert_reminder_id');
            $table->dropColumn('drift_alert_resolution_idempotency_key');
        });
    }
};
