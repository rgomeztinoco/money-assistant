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
        Schema::create('ai_category_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('category_assignment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('examples')->default('[]');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('confirmed_category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'confirmed_at']);
        });

        DB::statement('ALTER TABLE ai_category_proposals ADD CONSTRAINT ai_category_proposals_revision_positive CHECK (revision > 0)');
        DB::statement('ALTER TABLE ai_category_proposals ADD CONSTRAINT ai_category_proposals_confirmation_complete CHECK ((confirmed_at IS NULL AND confirmed_category_id IS NULL) OR (confirmed_at IS NOT NULL AND confirmed_category_id IS NOT NULL))');
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
                    OR (category_id IS NULL AND ai_confidence IS NOT NULL AND ai_outcome IN ('low_confidence', 'invalid_category', 'missing_category'))
                    OR (category_id IS NULL AND ai_confidence IS NULL AND ai_outcome IN ('timeout', 'unavailable'))
                )
                AND ((ai_reviewed_at IS NULL AND ai_approved_unchanged IS NULL)
                    OR (ai_requires_review = true AND ai_reviewed_at IS NOT NULL AND ai_approved_unchanged IS NOT NULL))
            )
        )");
        DB::statement('ALTER TABLE ai_classification_requests DROP CONSTRAINT ai_classification_requests_terminal_outcome_supported');
        DB::statement("ALTER TABLE ai_classification_requests ADD CONSTRAINT ai_classification_requests_terminal_outcome_supported CHECK (terminal_outcome IS NULL OR terminal_outcome IN ('high', 'medium', 'missing_category', 'low_confidence', 'invalid_category', 'timeout', 'unavailable', 'superseded'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE ai_classification_requests DROP CONSTRAINT ai_classification_requests_terminal_outcome_supported');
        DB::statement("ALTER TABLE ai_classification_requests ADD CONSTRAINT ai_classification_requests_terminal_outcome_supported CHECK (terminal_outcome IS NULL OR terminal_outcome IN ('high', 'medium', 'low_confidence', 'invalid_category', 'timeout', 'unavailable', 'superseded'))");
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

        Schema::dropIfExists('ai_category_proposals');
    }
};
