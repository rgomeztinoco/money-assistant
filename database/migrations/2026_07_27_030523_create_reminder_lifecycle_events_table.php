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
        Schema::create('reminder_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            $table->string('service_key_id', 128);
            $table->unsignedSmallInteger('schema_version');
            $table->uuid('idempotency_key');
            $table->char('payload_digest', 64);
            $table->char('interaction_digest', 64)->nullable();
            $table->string('action', 32);
            $table->string('domain_action', 64)->nullable();
            $table->unsignedInteger('reminder_revision');
            $table->timestampTz('occurred_at');
            $table->timestampTz('snoozed_until')->nullable();
            $table->timestamps();

            $table->unique(
                ['service_key_id', 'schema_version', 'idempotency_key'],
                'reminder_lifecycle_events_idempotency_unique',
            );
            $table->index(['reminder_id', 'occurred_at']);
        });

        DB::statement("ALTER TABLE reminder_lifecycle_events ADD CONSTRAINT reminder_lifecycle_events_action_supported CHECK (action IN ('acknowledged', 'snoozed', 'dismissed', 'resolved'))");
        DB::statement('ALTER TABLE reminder_lifecycle_events ADD CONSTRAINT reminder_lifecycle_events_revision_valid CHECK (reminder_revision > 0)');
        DB::statement("ALTER TABLE reminder_lifecycle_events ADD CONSTRAINT reminder_lifecycle_events_snooze_consistent CHECK ((action = 'snoozed' AND snoozed_until IS NOT NULL) OR (action <> 'snoozed' AND snoozed_until IS NULL))");
        DB::statement("ALTER TABLE reminder_lifecycle_events ADD CONSTRAINT reminder_lifecycle_events_resolution_consistent CHECK ((action = 'resolved' AND domain_action IS NOT NULL) OR (action <> 'resolved' AND domain_action IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_lifecycle_events');
    }
};
