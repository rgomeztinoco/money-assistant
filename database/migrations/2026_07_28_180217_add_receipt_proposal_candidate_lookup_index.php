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
        DB::statement(<<<'SQL'
            CREATE INDEX receipt_proposals_candidate_lookup_index
            ON receipt_proposals (
                user_id,
                (proposed_transaction->>'currency'),
                (proposed_transaction->>'kind'),
                processed_at DESC
            )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX receipt_proposals_candidate_lookup_index');
    }
};
