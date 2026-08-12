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
        Schema::dropIfExists('ai_category_proposals');
        Schema::dropIfExists('ai_classification_requests');
        Schema::dropIfExists('ai_classification_validation_contexts');

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_category_assignment_complete');
        DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_details_complete');
        DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_supported');
        DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_ai_confidence_valid');
        DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_examples_array');
        DB::statement('ALTER TABLE integration_incidents DROP CONSTRAINT integration_incidents_integration_supported');
        DB::statement('ALTER TABLE integration_incidents DROP CONSTRAINT integration_incidents_work_type_supported');

        Schema::table('category_assignments', function (Blueprint $table) {
            $table->dropIndex('category_assignments_ai_context_review_index');
            $table->dropIndex('category_assignments_ai_validation_context_index');
            $table->dropColumn([
                'ai_classifier_version',
                'ai_confidence',
                'ai_outcome',
                'ai_explanation',
                'ai_taxonomy_fingerprint',
                'ai_requires_review',
                'ai_reviewed_at',
                'ai_approved_unchanged',
                'ai_validation_context_revision',
            ]);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['description', 'examples']);
        });

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_category_assignment_complete CHECK ((category_id IS NULL AND category_assignment_provenance IS NULL) OR (category_id IS NOT NULL AND category_assignment_provenance IN ('owner', 'linked_refund', 'learned_rule')))");
        DB::statement("ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_source_supported CHECK (source IN ('owner', 'linked_refund', 'learned_rule'))");
        DB::statement("ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_source_details_complete CHECK (
            (source = 'owner' AND linked_purchase_id IS NULL AND ((learned_rule_id IS NULL AND learned_rule_revision IS NULL) OR (learned_rule_bulk_action_id IS NOT NULL AND learned_rule_id IS NOT NULL AND learned_rule_revision IS NOT NULL)))
            OR (source = 'linked_refund' AND category_id IS NOT NULL AND linked_purchase_id IS NOT NULL AND learned_rule_id IS NULL AND learned_rule_revision IS NULL AND learned_rule_bulk_action_id IS NULL)
            OR (source = 'learned_rule' AND category_id IS NOT NULL AND linked_purchase_id IS NULL AND learned_rule_id IS NOT NULL AND learned_rule_revision IS NOT NULL AND learned_rule_bulk_action_id IS NULL)
        )");
        DB::statement("ALTER TABLE integration_incidents ADD CONSTRAINT integration_incidents_integration_supported CHECK (integration IN ('gmail', 'openclaw'))");
        DB::statement("ALTER TABLE integration_incidents ADD CONSTRAINT integration_incidents_work_type_supported CHECK (work_type IN ('gmail_synchronization', 'gmail_message', 'reminder_delivery'))");
    }

    /**
     * This destructive migration is intentionally irreversible.
     *
     * Restore removed persistence with a forward-fix migration when necessary.
     */
    public function down(): void
    {
        throw new LogicException('Removed AI categorization persistence cannot be restored.');
    }
};
