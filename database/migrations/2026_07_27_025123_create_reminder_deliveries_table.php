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
        Schema::create('reminder_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->timestampTz('scheduled_for');
            $table->timestampTz('occurred_at');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('terminal_at')->nullable();
            $table->string('terminal_reason', 32)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['reminder_id', 'scheduled_for']);
            $table->index(['accepted_at', 'terminal_at', 'next_attempt_at']);
            $table->index('queued_at');
            $table->index('claimed_at');
        });

        DB::statement("ALTER TABLE reminder_deliveries ADD CONSTRAINT reminder_deliveries_event_type_supported CHECK (event_type = 'reminder.due')");
        DB::statement("ALTER TABLE reminder_deliveries ADD CONSTRAINT reminder_deliveries_terminal_reason_supported CHECK (terminal_reason IS NULL OR terminal_reason IN ('retry_exhausted', 'authorization_rejected', 'validation_rejected', 'deterministic_failure'))");
        DB::statement('ALTER TABLE reminder_deliveries ADD CONSTRAINT reminder_deliveries_terminal_consistent CHECK ((terminal_at IS NULL AND terminal_reason IS NULL) OR (terminal_at IS NOT NULL AND terminal_reason IS NOT NULL))');
        DB::statement('ALTER TABLE reminder_deliveries ADD CONSTRAINT reminder_deliveries_outcome_exclusive CHECK (accepted_at IS NULL OR terminal_at IS NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_deliveries');
    }
};
