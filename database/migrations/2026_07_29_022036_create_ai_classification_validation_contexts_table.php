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
        Schema::create('ai_classification_validation_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->string('classifier_version', 100)->nullable();
            $table->char('taxonomy_fingerprint', 64)->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE ai_classification_validation_contexts ADD CONSTRAINT ai_classification_validation_contexts_revision_positive CHECK (revision > 0)');

        Schema::table('category_assignments', function (Blueprint $table) {
            $table->unsignedInteger('ai_validation_context_revision')->nullable();
            $table->index(
                ['user_id', 'ai_validation_context_revision', 'ai_reviewed_at'],
                'category_assignments_ai_context_review_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('category_assignments', function (Blueprint $table) {
            $table->dropIndex('category_assignments_ai_context_review_index');
            $table->dropColumn('ai_validation_context_revision');
        });

        Schema::dropIfExists('ai_classification_validation_contexts');
    }
};
