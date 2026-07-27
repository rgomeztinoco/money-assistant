<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('learned_rule_change_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learned_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_category_assignment_id')->nullable()->constrained('category_assignments')->restrictOnDelete();
            $table->foreignId('learned_rule_suggestion_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('expected_rule_revision')->nullable();
            $table->jsonb('definition');
            $table->jsonb('analysis');
            $table->string('resource_fingerprint', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('learned_rule_change_previews');
    }
};
