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
        Schema::create('open_claw_audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('service_key_id', 128);
            $table->unsignedSmallInteger('schema_version')->nullable();
            $table->string('capability', 64)->nullable();
            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status');
            $table->char('nonce_digest', 64);
            $table->char('request_digest', 64);
            $table->char('interaction_digest', 64)->nullable();
            $table->string('resource_type', 32)->nullable();
            $table->unsignedSmallInteger('result_count')->default(0);

            $table->index('occurred_at');
            $table->index(['outcome', 'occurred_at']);
        });

        DB::statement("ALTER TABLE open_claw_audit_events ADD CONSTRAINT open_claw_audit_events_outcome_supported CHECK (outcome IN ('success', 'stale_signature', 'replayed_nonce', 'unsupported_schema', 'unsupported_capability', 'unbound_interaction', 'invalid_request', 'not_found', 'rate_limited', 'internal_error'))");
        DB::statement('ALTER TABLE open_claw_audit_events ADD CONSTRAINT open_claw_audit_events_http_status_valid CHECK (http_status BETWEEN 100 AND 599)');
        DB::statement('ALTER TABLE open_claw_audit_events ADD CONSTRAINT open_claw_audit_events_result_bounded CHECK (result_count BETWEEN 0 AND 1)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_open_claw_audit_event_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'OpenClaw audit events are append-only.'
                    USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER open_claw_audit_events_append_only
            BEFORE UPDATE OR DELETE ON open_claw_audit_events
            FOR EACH ROW EXECUTE FUNCTION reject_open_claw_audit_event_mutation()
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS open_claw_audit_events_append_only ON open_claw_audit_events');
        DB::statement('DROP FUNCTION IF EXISTS reject_open_claw_audit_event_mutation()');
        Schema::dropIfExists('open_claw_audit_events');
    }
};
