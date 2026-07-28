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
        DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_details_complete');
        DB::statement("ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_source_details_complete CHECK (
            (source = 'owner'
                AND linked_purchase_id IS NULL
                AND ai_classifier_version IS NULL AND ai_confidence IS NULL AND ai_outcome IS NULL AND ai_explanation IS NULL
                AND ((learned_rule_id IS NULL AND learned_rule_revision IS NULL)
                    OR (learned_rule_bulk_action_id IS NOT NULL AND learned_rule_id IS NOT NULL AND learned_rule_revision IS NOT NULL)))
            OR (source = 'linked_refund' AND category_id IS NOT NULL AND linked_purchase_id IS NOT NULL AND learned_rule_id IS NULL AND learned_rule_revision IS NULL AND learned_rule_bulk_action_id IS NULL AND ai_classifier_version IS NULL AND ai_confidence IS NULL AND ai_outcome IS NULL AND ai_explanation IS NULL)
            OR (source = 'learned_rule' AND category_id IS NOT NULL AND linked_purchase_id IS NULL AND learned_rule_id IS NOT NULL AND learned_rule_revision IS NOT NULL AND learned_rule_bulk_action_id IS NULL AND ai_classifier_version IS NULL AND ai_confidence IS NULL AND ai_outcome IS NULL AND ai_explanation IS NULL)
            OR (source = 'ai'
                AND linked_purchase_id IS NULL
                AND learned_rule_id IS NULL
                AND learned_rule_revision IS NULL
                AND learned_rule_bulk_action_id IS NULL
                AND ai_classifier_version IS NOT NULL
                AND ai_outcome IS NOT NULL
                AND ai_explanation IS NOT NULL
                AND (
                    (category_id IS NOT NULL AND ai_confidence IS NOT NULL AND ai_outcome = 'medium')
                    OR (category_id IS NULL AND ai_confidence IS NOT NULL AND ai_outcome IN ('low_confidence', 'invalid_category'))
                    OR (category_id IS NULL AND ai_confidence IS NULL AND ai_outcome IN ('timeout', 'unavailable'))
                )
            )
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_details_complete');
        DB::statement("ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_source_details_complete CHECK (
            (source = 'owner'
                AND linked_purchase_id IS NULL
                AND ai_classifier_version IS NULL AND ai_confidence IS NULL AND ai_outcome IS NULL AND ai_explanation IS NULL
                AND ((learned_rule_id IS NULL AND learned_rule_revision IS NULL)
                    OR (learned_rule_bulk_action_id IS NOT NULL AND learned_rule_id IS NOT NULL AND learned_rule_revision IS NOT NULL)))
            OR (source = 'linked_refund' AND category_id IS NOT NULL AND linked_purchase_id IS NOT NULL AND learned_rule_id IS NULL AND learned_rule_revision IS NULL AND learned_rule_bulk_action_id IS NULL AND ai_classifier_version IS NULL AND ai_confidence IS NULL AND ai_outcome IS NULL AND ai_explanation IS NULL)
            OR (source = 'learned_rule' AND category_id IS NOT NULL AND linked_purchase_id IS NULL AND learned_rule_id IS NOT NULL AND learned_rule_revision IS NOT NULL AND learned_rule_bulk_action_id IS NULL AND ai_classifier_version IS NULL AND ai_confidence IS NULL AND ai_outcome IS NULL AND ai_explanation IS NULL)
            OR (source = 'ai' AND linked_purchase_id IS NULL AND learned_rule_id IS NULL AND learned_rule_revision IS NULL AND learned_rule_bulk_action_id IS NULL AND ai_classifier_version IS NOT NULL AND ai_confidence IS NOT NULL AND ai_outcome IS NOT NULL AND ai_explanation IS NOT NULL)
        )");
    }
};
