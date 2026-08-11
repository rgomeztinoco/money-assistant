<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_category_assignment_complete CHECK ((category_id IS NULL AND category_assignment_provenance IS NULL) OR (category_id IS NOT NULL AND category_assignment_provenance IN ('owner', 'linked_refund', 'merchant_rule')))");
        DB::statement("ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_source_supported CHECK (source IN ('owner', 'linked_refund', 'merchant_rule'))");
        DB::statement("ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_source_details_complete CHECK (
            (source = 'owner' AND linked_purchase_id IS NULL AND merchant_rule_id IS NULL)
            OR (source = 'linked_refund' AND category_id IS NOT NULL AND linked_purchase_id IS NOT NULL AND merchant_rule_id IS NULL)
            OR (source = 'merchant_rule' AND category_id IS NOT NULL AND linked_purchase_id IS NULL)
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_category_assignment_complete');
        DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_details_complete');
        DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_supported');
    }
};
