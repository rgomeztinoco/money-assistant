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
        Schema::create('ai_classification_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('expected_transaction_revision');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('terminal_outcome', 32)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'completed_at']);
            $table->index(
                ['completed_at', 'next_attempt_at', 'queued_at', 'claimed_at'],
                'ai_classification_requests_dispatch_index',
            );
        });

        DB::statement('ALTER TABLE ai_classification_requests ADD CONSTRAINT ai_classification_requests_revision_positive CHECK (expected_transaction_revision > 0)');
        DB::statement("ALTER TABLE ai_classification_requests ADD CONSTRAINT ai_classification_requests_terminal_outcome_supported CHECK (terminal_outcome IS NULL OR terminal_outcome IN ('medium', 'low_confidence', 'invalid_category', 'timeout', 'unavailable', 'superseded'))");
        DB::statement("ALTER TABLE ai_classification_requests ADD CONSTRAINT ai_classification_requests_error_supported CHECK (last_error_code IS NULL OR last_error_code IN ('authoritative_assignment', 'classifier_timeout', 'classifier_unavailable'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_classification_requests');
    }
};
