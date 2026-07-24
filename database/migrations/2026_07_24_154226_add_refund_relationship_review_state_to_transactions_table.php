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
        Schema::table('transactions', function (Blueprint $table) {
            $table->jsonb('refund_relationship_review_reasons')->default('[]');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE transactions
            ADD CONSTRAINT transactions_refund_relationship_review_reasons_supported
            CHECK (
                jsonb_typeof(refund_relationship_review_reasons) = 'array'
                AND refund_relationship_review_reasons <@ '[
                    "cumulative_refunds_exceed_purchase",
                    "receipt_breakdown_allocation_requires_review"
                ]'::jsonb
            )
            SQL);
        DB::statement('CREATE INDEX transactions_refund_relationship_review_index ON transactions (user_id, occurred_on DESC, id DESC) WHERE jsonb_array_length(refund_relationship_review_reasons) > 0 AND voided_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX transactions_refund_relationship_review_index');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_refund_relationship_review_reasons_supported');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('refund_relationship_review_reasons');
        });
    }
};
