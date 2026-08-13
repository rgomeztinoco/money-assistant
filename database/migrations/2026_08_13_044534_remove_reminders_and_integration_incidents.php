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
        foreach (['App\\Jobs\\DeliverReminder'] as $removedJob) {
            foreach (['jobs', 'failed_jobs'] as $queueTable) {
                DB::table($queueTable)
                    ->whereRaw("payload::jsonb ->> 'displayName' = ?", [$removedJob])
                    ->delete();
            }
        }

        Schema::table('gmail_message_discoveries', function (Blueprint $table): void {
            $table->timestampTz('processing_failed_at')->nullable()->index();
            $table->string('last_error_code', 64)->nullable();
            $table->uuid('failed_job_uuid')->nullable()->unique();
        });

        Schema::table('gmail_connections', function (Blueprint $table): void {
            $table->timestampTz('last_synchronization_failed_at')->nullable()->index();
            $table->string('last_synchronization_error_code', 64)->nullable();
        });

        Schema::dropIfExists('integration_incidents');
        Schema::dropIfExists('reminder_lifecycle_events');
        Schema::dropIfExists('reminder_deliveries');
        Schema::dropIfExists('reminders');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Removed Reminder and integration incident data cannot be restored.');
    }
};
