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
        Schema::create('learned_rule_bulk_action_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learned_rule_bulk_action_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('expected_transaction_revision');
            $table->foreignId('previous_category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->unsignedInteger('applied_transaction_revision')->nullable();
            $table->unsignedInteger('undo_transaction_revision')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamps();

            $table->unique(['learned_rule_bulk_action_id', 'transaction_id'], 'learned_rule_bulk_action_items_unique_transaction');
            $table->index(['learned_rule_bulk_action_id', 'status'], 'learned_rule_bulk_action_items_status_index');
        });

        DB::statement("ALTER TABLE learned_rule_bulk_action_items ADD CONSTRAINT learned_rule_bulk_action_items_status_supported CHECK (status IN ('pending', 'applied', 'restored', 'skipped'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('learned_rule_bulk_action_items');
    }
};
