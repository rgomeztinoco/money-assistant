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
        DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_details_complete');

        Schema::table('category_assignments', function (Blueprint $table) {
            $table->foreignId('learned_rule_bulk_action_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
        });

        $this->addSourceDetailsConstraint();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_details_complete');

        Schema::table('category_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('learned_rule_bulk_action_id');
        });

        DB::statement("ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_source_details_complete CHECK (
            (source = 'owner' AND linked_purchase_id IS NULL AND learned_rule_id IS NULL AND learned_rule_revision IS NULL AND ai_classifier_version IS NULL AND ai_confidence IS NULL AND ai_outcome IS NULL AND ai_explanation IS NULL)
            OR (source = 'linked_refund' AND category_id IS NOT NULL AND linked_purchase_id IS NOT NULL AND learned_rule_id IS NULL AND learned_rule_revision IS NULL AND ai_classifier_version IS NULL AND ai_confidence IS NULL AND ai_outcome IS NULL AND ai_explanation IS NULL)
            OR (source = 'learned_rule' AND category_id IS NOT NULL AND linked_purchase_id IS NULL AND learned_rule_id IS NOT NULL AND learned_rule_revision IS NOT NULL AND ai_classifier_version IS NULL AND ai_confidence IS NULL AND ai_outcome IS NULL AND ai_explanation IS NULL)
            OR (source = 'ai' AND linked_purchase_id IS NULL AND learned_rule_id IS NULL AND learned_rule_revision IS NULL AND ai_classifier_version IS NOT NULL AND ai_confidence IS NOT NULL AND ai_outcome IS NOT NULL AND ai_explanation IS NOT NULL)
        )");
    }

    private function addSourceDetailsConstraint(): void
    {
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
