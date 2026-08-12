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
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_category_assignment_complete');

        Schema::table('merchant_rules', function (Blueprint $table) {
            $table->softDeletesTz();
        });
        DB::statement('ALTER TABLE merchant_rules DROP CONSTRAINT merchant_rules_scope_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX merchant_rules_scope_unique
            ON merchant_rules (user_id, merchant_key, transaction_kind, currency) NULLS NOT DISTINCT
            WHERE deleted_at IS NULL
            SQL);

        if (! Schema::hasColumn('transactions', 'merchant_rule_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->foreignId('merchant_rule_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();
            });

            DB::statement(<<<'SQL'
                UPDATE transactions
                SET merchant_rule_id = current_assignment.merchant_rule_id
                FROM (
                    SELECT DISTINCT ON (transaction_id)
                        transaction_id,
                        merchant_rule_id
                    FROM category_assignments
                    WHERE source = 'merchant_rule'
                    ORDER BY transaction_id, transaction_revision DESC
                ) AS current_assignment
                WHERE transactions.id = current_assignment.transaction_id
                    AND transactions.category_assignment_provenance = 'merchant_rule'
                SQL);
        }
        DB::table('transactions')
            ->where('category_assignment_provenance', 'linked_refund')
            ->update(['category_assignment_provenance' => 'owner']);
        DB::statement(<<<'SQL'
            ALTER TABLE transactions
            ADD CONSTRAINT transactions_category_assignment_complete CHECK (
                (category_id IS NULL AND category_assignment_provenance IS NULL AND merchant_rule_id IS NULL)
                OR (
                    category_id IS NOT NULL
                    AND (
                        (category_assignment_provenance = 'merchant_rule' AND merchant_rule_id IS NOT NULL)
                        OR (category_assignment_provenance = 'owner' AND merchant_rule_id IS NULL)
                    )
                )
            )
            SQL);

        Schema::dropIfExists('suspected_duplicate_receipt_breakdown_moves');
        Schema::dropIfExists('suspected_duplicate_source_moves');
        Schema::dropIfExists('suspected_duplicate_resolutions');
        Schema::dropIfExists('suspected_duplicates');
        Schema::dropIfExists('transaction_corrections');
        Schema::dropIfExists('transaction_state_changes');
        Schema::dropIfExists('category_assignments');

        if (Schema::hasColumn('transactions', 'revision')) {
            DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_revision_positive');

            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('revision');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Removed Transaction history and Suspected Duplicate data cannot be restored.');
    }
};
