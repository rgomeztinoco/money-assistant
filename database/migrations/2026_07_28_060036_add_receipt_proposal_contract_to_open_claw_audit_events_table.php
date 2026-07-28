<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE open_claw_audit_events DROP CONSTRAINT open_claw_audit_events_mutation_metadata_consistent');
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
                ) OR (
                    event_kind = 'proposal'
                    AND outcome = 'success'
                    AND idempotency_key IS NOT NULL
                    AND operation_digest IS NOT NULL
                    AND confirmation_grant_id IS NULL
                    AND domain_action = 'receipt_proposal.submit'
                    AND resource_type = 'receipt_proposal'
                    AND resource_id IS NOT NULL
                    AND resource_revision = 1
                    AND result_count = 1
                )
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX open_claw_audit_events_proposal_idempotency_unique
            ON open_claw_audit_events (service_key_id, schema_version, capability, idempotency_key)
            WHERE event_kind = 'proposal'
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS open_claw_audit_events_proposal_idempotency_unique');
        DB::statement('ALTER TABLE open_claw_audit_events DROP CONSTRAINT open_claw_audit_events_mutation_metadata_consistent');
        DB::statement('DROP TRIGGER IF EXISTS open_claw_audit_events_append_only ON open_claw_audit_events');
        DB::table('open_claw_audit_events')->where('event_kind', 'proposal')->delete();
        DB::statement(<<<'SQL'
            CREATE TRIGGER open_claw_audit_events_append_only
            BEFORE UPDATE OR DELETE ON open_claw_audit_events
            FOR EACH ROW EXECUTE FUNCTION reject_open_claw_audit_event_mutation()
            SQL);
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
    }
};
