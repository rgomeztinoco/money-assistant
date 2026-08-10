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
        Schema::table('receipt_breakdowns', function (Blueprint $table) {
            $table->dropUnique(['receipt_proposal_id']);
            $table->dropConstrainedForeignId('receipt_proposal_id');
        });

        DB::statement('ALTER TABLE financial_data_tombstones DROP CONSTRAINT financial_data_tombstones_source_reference_supported');

        Schema::table('financial_data_tombstones', function (Blueprint $table) {
            $table->dropUnique(['source_reference_type', 'source_reference_id']);
            $table->dropColumn(['source_reference_type', 'source_reference_id']);
        });

        Schema::dropIfExists('receipt_proposals');
        Schema::dropIfExists('open_claw_confirmation_grants');
        Schema::dropIfExists('open_claw_pending_operations');
        Schema::dropIfExists('open_claw_request_nonces');

        DB::statement('DROP TRIGGER IF EXISTS open_claw_audit_events_append_only ON open_claw_audit_events');
        Schema::dropIfExists('open_claw_audit_events');
        DB::statement('DROP FUNCTION IF EXISTS reject_open_claw_audit_event_mutation()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Removed OpenClaw and Receipt Proposal data cannot be restored.');
    }
};
