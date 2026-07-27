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
        Schema::create('category_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('previous_category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('source', 32);
            $table->unsignedInteger('transaction_revision');
            $table->foreignId('linked_purchase_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->unsignedBigInteger('learned_rule_id')->nullable();
            $table->unsignedInteger('learned_rule_revision')->nullable();
            $table->string('ai_classifier_version', 100)->nullable();
            $table->unsignedSmallInteger('ai_confidence')->nullable();
            $table->string('ai_outcome', 32)->nullable();
            $table->string('ai_explanation', 500)->nullable();
            $table->boolean('is_backfilled')->default(false);
            $table->timestamps();

            $table->unique(['transaction_id', 'transaction_revision']);
            $table->index(['user_id', 'source']);
            $table->index('category_id');
        });

        DB::statement("ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_source_supported CHECK (source IN ('owner', 'linked_refund', 'learned_rule', 'ai'))");
        DB::statement('ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_revision_positive CHECK (transaction_revision > 0)');
        DB::statement('ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_ai_confidence_valid CHECK (ai_confidence IS NULL OR ai_confidence BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_source_details_complete CHECK (
            (source = 'owner'
                AND linked_purchase_id IS NULL
                AND learned_rule_id IS NULL
                AND learned_rule_revision IS NULL
                AND ai_classifier_version IS NULL
                AND ai_confidence IS NULL
                AND ai_outcome IS NULL
                AND ai_explanation IS NULL)
            OR (source = 'linked_refund'
                AND category_id IS NOT NULL
                AND linked_purchase_id IS NOT NULL
                AND learned_rule_id IS NULL
                AND learned_rule_revision IS NULL
                AND ai_classifier_version IS NULL
                AND ai_confidence IS NULL
                AND ai_outcome IS NULL
                AND ai_explanation IS NULL)
            OR (source = 'learned_rule'
                AND category_id IS NOT NULL
                AND linked_purchase_id IS NULL
                AND learned_rule_id IS NOT NULL
                AND learned_rule_revision IS NOT NULL
                AND ai_classifier_version IS NULL
                AND ai_confidence IS NULL
                AND ai_outcome IS NULL
                AND ai_explanation IS NULL)
            OR (source = 'ai'
                AND linked_purchase_id IS NULL
                AND learned_rule_id IS NULL
                AND learned_rule_revision IS NULL
                AND ai_classifier_version IS NOT NULL
                AND ai_confidence IS NOT NULL
                AND ai_outcome IS NOT NULL
                AND ai_explanation IS NOT NULL)
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_assignments');
    }
};
