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
        Schema::table('open_claw_audit_events', function (Blueprint $table) {
            $table->string('event_kind', 16)->default('request');
            $table->uuid('idempotency_key')->nullable();
            $table->char('operation_digest', 64)->nullable();
            $table->uuid('confirmation_grant_id')->nullable();
            $table->string('domain_action', 64)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedInteger('resource_revision')->nullable();

            $table->index(['resource_type', 'resource_id']);
        });

        DB::statement('ALTER TABLE open_claw_audit_events DROP CONSTRAINT open_claw_audit_events_outcome_supported');
        DB::statement("ALTER TABLE open_claw_audit_events ADD CONSTRAINT open_claw_audit_events_outcome_supported CHECK (outcome IN ('success', 'idempotent_replay', 'idempotency_conflict', 'stale_signature', 'replayed_nonce', 'unsupported_schema', 'unsupported_capability', 'unbound_interaction', 'invalid_request', 'not_found', 'rate_limited', 'approval_message_required', 'confirmation_invalid', 'confirmation_expired', 'confirmation_consumed', 'stale_revision', 'internal_error'))");
        DB::statement(<<<'SQL'
            ALTER TABLE open_claw_audit_events
            ADD CONSTRAINT open_claw_audit_events_mutation_metadata_consistent CHECK (
                (
                    event_kind = 'request'
                    AND idempotency_key IS NULL
                    AND operation_digest IS NULL
                    AND confirmation_grant_id IS NULL
                    AND domain_action IS NULL
                    AND resource_id IS NULL
                    AND resource_revision IS NULL
                ) OR (
                    event_kind = 'mutation'
                    AND outcome = 'success'
                    AND idempotency_key IS NOT NULL
                    AND operation_digest IS NOT NULL
                    AND confirmation_grant_id IS NOT NULL
                    AND domain_action IS NOT NULL
                    AND resource_type IS NOT NULL
                    AND resource_id IS NOT NULL
                    AND resource_revision IS NOT NULL
                    AND result_count = 1
                )
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX open_claw_audit_events_mutation_idempotency_unique
            ON open_claw_audit_events (service_key_id, schema_version, capability, idempotency_key)
            WHERE event_kind = 'mutation'
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS open_claw_audit_events_mutation_idempotency_unique');
        DB::statement('ALTER TABLE open_claw_audit_events DROP CONSTRAINT open_claw_audit_events_mutation_metadata_consistent');
        DB::statement('ALTER TABLE open_claw_audit_events DROP CONSTRAINT open_claw_audit_events_outcome_supported');
        DB::statement("ALTER TABLE open_claw_audit_events ADD CONSTRAINT open_claw_audit_events_outcome_supported CHECK (outcome IN ('success', 'stale_signature', 'replayed_nonce', 'unsupported_schema', 'unsupported_capability', 'unbound_interaction', 'invalid_request', 'not_found', 'rate_limited', 'internal_error'))");

        Schema::table('open_claw_audit_events', function (Blueprint $table) {
            $table->dropIndex(['resource_type', 'resource_id']);
            $table->dropColumn([
                'event_kind',
                'idempotency_key',
                'operation_digest',
                'confirmation_grant_id',
                'domain_action',
                'resource_id',
                'resource_revision',
            ]);
        });
    }
};
