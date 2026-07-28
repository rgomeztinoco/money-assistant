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
        Schema::table('line_items', function (Blueprint $table) {
            $table->string('quantity', 64)->nullable();
            $table->bigInteger('unit_price_minor')->nullable();
            $table->uuid('related_line_item_id')->nullable();
            $table->foreign('related_line_item_id')
                ->references('line_item_id')
                ->on('line_items')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE line_items DROP CONSTRAINT line_items_role_supported');
        DB::statement('ALTER TABLE line_items DROP CONSTRAINT line_items_purchased_total_positive');
        DB::statement("ALTER TABLE line_items ADD CONSTRAINT line_items_role_supported CHECK (role IN ('purchased_item', 'tax', 'discount', 'tip', 'fee', 'rounding', 'other_adjustment', 'unidentified'))");
        DB::statement("ALTER TABLE line_items ADD CONSTRAINT line_items_total_valid_by_role CHECK ((role = 'purchased_item' AND line_total_minor > 0) OR (role <> 'purchased_item' AND line_total_minor <> 0))");
        DB::statement("ALTER TABLE line_items ADD CONSTRAINT line_items_unidentified_state_consistent CHECK (role <> 'unidentified' OR (category_id IS NULL AND requires_review))");
        DB::statement("ALTER TABLE line_items ADD CONSTRAINT line_items_related_role_consistent CHECK (related_line_item_id IS NULL OR role NOT IN ('purchased_item', 'unidentified'))");

        DB::statement('ALTER TABLE receipt_proposals DROP CONSTRAINT receipt_proposals_contract_version_supported');
        DB::statement('ALTER TABLE receipt_proposals ADD CONSTRAINT receipt_proposals_contract_version_supported CHECK (contract_version IN (1, 2))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasExtendedReceiptData = DB::table('receipt_proposals')
            ->where('contract_version', 2)
            ->exists()
            || DB::table('line_items')
                ->where(function ($query): void {
                    $query
                        ->where('role', '<>', 'purchased_item')
                        ->orWhereNotNull('quantity')
                        ->orWhereNotNull('unit_price_minor')
                        ->orWhereNotNull('related_line_item_id');
                })
                ->exists();

        if ($hasExtendedReceiptData) {
            throw new LogicException(
                'Cannot roll back signed receipt adjustments while version 2 proposals or extended Line Items exist. Preserve the data with a forward migration.',
            );
        }

        DB::statement('ALTER TABLE receipt_proposals DROP CONSTRAINT receipt_proposals_contract_version_supported');
        DB::statement('ALTER TABLE receipt_proposals ADD CONSTRAINT receipt_proposals_contract_version_supported CHECK (contract_version = 1)');

        DB::statement('ALTER TABLE line_items DROP CONSTRAINT line_items_unidentified_state_consistent');
        DB::statement('ALTER TABLE line_items DROP CONSTRAINT line_items_related_role_consistent');
        DB::statement('ALTER TABLE line_items DROP CONSTRAINT line_items_total_valid_by_role');
        DB::statement('ALTER TABLE line_items DROP CONSTRAINT line_items_role_supported');
        DB::statement("ALTER TABLE line_items ADD CONSTRAINT line_items_role_supported CHECK (role = 'purchased_item')");
        DB::statement('ALTER TABLE line_items ADD CONSTRAINT line_items_purchased_total_positive CHECK (line_total_minor > 0)');

        Schema::table('line_items', function (Blueprint $table) {
            $table->dropForeign(['related_line_item_id']);
            $table->dropColumn(['quantity', 'unit_price_minor', 'related_line_item_id']);
        });
    }
};
