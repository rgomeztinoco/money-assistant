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
        Schema::create('merchant_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('merchant');
            $table->string('merchant_key');
            $table->string('transaction_kind', 16)->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique([
                'user_id',
                'merchant_key',
                'transaction_kind',
                'currency',
            ], 'merchant_rules_scope_unique')->nullsNotDistinct();
            $table->index(['user_id', 'merchant_key', 'enabled']);
        });

        DB::statement("ALTER TABLE merchant_rules ADD CONSTRAINT merchant_rules_kind_supported CHECK (transaction_kind IS NULL OR transaction_kind IN ('purchase', 'refund'))");
        DB::statement("ALTER TABLE merchant_rules ADD CONSTRAINT merchant_rules_currency_supported CHECK (currency IS NULL OR currency IN ('USD', 'PEN'))");

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_category_assignment_complete');
        DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_details_complete');
        DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_supported');

        Schema::table('category_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('learned_rule_bulk_action_id');
            $table->dropColumn(['learned_rule_id', 'learned_rule_revision']);
            $table->foreignId('merchant_rule_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
        });

        Schema::dropIfExists('learned_rule_suggestion_evidence');
        Schema::dropIfExists('learned_rule_change_previews');
        Schema::dropIfExists('learned_rule_bulk_action_items');
        Schema::dropIfExists('learned_rule_suggestions');
        Schema::dropIfExists('learned_rule_bulk_actions');
        Schema::dropIfExists('learned_rule_revisions');
        Schema::dropIfExists('learned_rules');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore this subsystem with a forward-fix migration because its revision history cannot be reconstructed.
        throw new LogicException('The revisioned Learned Rule subsystem cannot be restored.');
    }
};
