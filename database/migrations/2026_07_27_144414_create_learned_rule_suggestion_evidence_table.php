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
        Schema::create('learned_rule_suggestion_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learned_rule_suggestion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique('category_assignment_id');
            $table->unique(['learned_rule_suggestion_id', 'transaction_id']);
            $table->index('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('learned_rule_suggestion_evidence');
    }
};
