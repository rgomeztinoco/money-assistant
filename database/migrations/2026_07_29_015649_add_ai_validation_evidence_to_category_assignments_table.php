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
        Schema::table('category_assignments', function (Blueprint $table) {
            $table->char('ai_taxonomy_fingerprint', 64)->nullable();
            $table->boolean('ai_requires_review')->nullable();
            $table->timestampTz('ai_reviewed_at')->nullable();
            $table->boolean('ai_approved_unchanged')->nullable();

            $table->index(
                [
                    'user_id',
                    'ai_classifier_version',
                    'ai_taxonomy_fingerprint',
                    'ai_reviewed_at',
                ],
                'category_assignments_ai_validation_context_index',
            );
        });

        DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_details_complete');
        DB::statement("ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_source_details_complete CHECK (
            (source = 'owner'
                AND linked_purchase_id IS NULL
                AND ai_classifier_version IS NULL AND ai_confidence IS NULL AND ai_outcome IS NULL AND ai_explanation IS NULL
                AND ai_taxonomy_fingerprint IS NULL AND ai_requires_review IS NULL AND ai_reviewed_at IS NULL AND ai_approved_unchanged IS NULL
                AND ((learned_rule_id IS NULL AND learned_rule_revision IS NULL)
                    OR (learned_rule_bulk_action_id IS NOT NULL AND learned_rule_id IS NOT NULL AND learned_rule_revision IS NOT NULL)))
            OR (source = 'linked_refund' AND category_id IS NOT NULL AND linked_purchase_id IS NOT NULL AND learned_rule_id IS NULL AND learned_rule_revision IS NULL AND learned_rule_bulk_action_id IS NULL AND ai_classifier_version IS NULL AND ai_confidence IS NULL AND ai_outcome IS NULL AND ai_explanation IS NULL AND ai_taxonomy_fingerprint IS NULL AND ai_requires_review IS NULL AND ai_reviewed_at IS NULL AND ai_approved_unchanged IS NULL)
            OR (source = 'learned_rule' AND category_id IS NOT NULL AND linked_purchase_id IS NULL AND learned_rule_id IS NOT NULL AND learned_rule_revision IS NOT NULL AND learned_rule_bulk_action_id IS NULL AND ai_classifier_version IS NULL AND ai_confidence IS NULL AND ai_outcome IS NULL AND ai_explanation IS NULL AND ai_taxonomy_fingerprint IS NULL AND ai_requires_review IS NULL AND ai_reviewed_at IS NULL AND ai_approved_unchanged IS NULL)
            OR (source = 'ai'
                AND linked_purchase_id IS NULL
                AND learned_rule_id IS NULL
                AND learned_rule_revision IS NULL
                AND learned_rule_bulk_action_id IS NULL
                AND ai_classifier_version IS NOT NULL
                AND ai_outcome IS NOT NULL
                AND ai_explanation IS NOT NULL
                AND (
                    (category_id IS NOT NULL AND ai_confidence IS NOT NULL AND ai_outcome IN ('medium', 'high'))
                    OR (category_id IS NULL AND ai_confidence IS NOT NULL AND ai_outcome IN ('low_confidence', 'invalid_category'))
                    OR (category_id IS NULL AND ai_confidence IS NULL AND ai_outcome IN ('timeout', 'unavailable'))
                )
                AND ((ai_reviewed_at IS NULL AND ai_approved_unchanged IS NULL)
                    OR (ai_requires_review = true AND ai_reviewed_at IS NOT NULL AND ai_approved_unchanged IS NOT NULL))
            )
        )");

        DB::statement('ALTER TABLE ai_classification_requests DROP CONSTRAINT ai_classification_requests_terminal_outcome_supported');
        DB::statement("ALTER TABLE ai_classification_requests ADD CONSTRAINT ai_classification_requests_terminal_outcome_supported CHECK (terminal_outcome IS NULL OR terminal_outcome IN ('high', 'medium', 'low_confidence', 'invalid_category', 'timeout', 'unavailable', 'superseded'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE ai_classification_requests DROP CONSTRAINT ai_classification_requests_terminal_outcome_supported');
        DB::statement("ALTER TABLE ai_classification_requests ADD CONSTRAINT ai_classification_requests_terminal_outcome_supported CHECK (terminal_outcome IS NULL OR terminal_outcome IN ('medium', 'low_confidence', 'invalid_category', 'timeout', 'unavailable', 'superseded'))");
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

        Schema::table('category_assignments', function (Blueprint $table) {
            $table->dropIndex('category_assignments_ai_validation_context_index');
            $table->dropColumn([
                'ai_taxonomy_fingerprint',
                'ai_requires_review',
                'ai_reviewed_at',
                'ai_approved_unchanged',
            ]);
        });
    }
};
