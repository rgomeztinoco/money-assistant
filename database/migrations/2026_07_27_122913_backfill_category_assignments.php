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
        if (DB::table('transactions')->whereIn('category_assignment_provenance', ['learned_rule', 'ai'])->exists()) {
            throw new RuntimeException('Existing automated Category assignments cannot be backfilled without their exact provenance.');
        }

        DB::statement("INSERT INTO category_assignments (
            user_id,
            transaction_id,
            category_id,
            previous_category_id,
            source,
            transaction_revision,
            linked_purchase_id,
            is_backfilled,
            created_at,
            updated_at
        )
        SELECT
            user_id,
            id,
            category_id,
            NULL,
            category_assignment_provenance,
            revision,
            CASE
                WHEN category_assignment_provenance = 'linked_refund' THEN original_purchase_id
                ELSE NULL
            END,
            TRUE,
            created_at,
            updated_at
        FROM transactions
        WHERE category_id IS NOT NULL
            AND (
                category_assignment_provenance = 'owner'
                OR (
                    category_assignment_provenance = 'linked_refund'
                    AND original_purchase_id IS NOT NULL
                )
            )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('category_assignments')->where('is_backfilled', true)->delete();
    }
};
