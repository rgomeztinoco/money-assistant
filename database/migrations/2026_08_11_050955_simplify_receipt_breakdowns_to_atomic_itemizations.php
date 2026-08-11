<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('receipt_breakdowns')
            ->where(function ($query): void {
                $query
                    ->where('status', '<>', 'confirmed')
                    ->orWhereNotNull('deleted_at');
            })
            ->delete();

        DB::statement('DROP INDEX receipt_breakdowns_one_draft_per_transaction');
        DB::statement('DROP INDEX receipt_breakdowns_one_confirmed_per_transaction');
        DB::statement('ALTER TABLE receipt_breakdowns DROP CONSTRAINT receipt_breakdowns_revision_positive');
        DB::statement('ALTER TABLE receipt_breakdowns DROP CONSTRAINT receipt_breakdowns_status_supported');

        Schema::table('receipt_breakdowns', function (Blueprint $table) {
            $table->dropUnique(['deletion_id']);
            $table->dropIndex(['purge_after']);
            $table->dropColumn([
                'revision',
                'status',
                'confirmed_at',
                'deletion_id',
                'purge_after',
                'deleted_at',
            ]);
            $table->unique('transaction_id');
        });

        DB::statement('ALTER TABLE line_items DROP CONSTRAINT line_items_related_line_item_id_foreign');
        DB::statement('ALTER TABLE line_items DROP CONSTRAINT line_items_related_role_consistent');
        DB::statement('ALTER TABLE line_items DROP CONSTRAINT line_items_total_valid_by_role');
        DB::statement('ALTER TABLE line_items DROP CONSTRAINT line_items_role_supported');
        DB::statement('ALTER TABLE line_items DROP CONSTRAINT line_items_unidentified_state_consistent');

        Schema::table('line_items', function (Blueprint $table) {
            $table->dropColumn(['role', 'related_line_item_id', 'requires_review']);
        });

        DB::statement('ALTER TABLE line_items ADD CONSTRAINT line_items_total_nonzero CHECK (line_total_minor <> 0)');

        DB::statement('ALTER TABLE suspected_duplicate_receipt_breakdown_moves DROP CONSTRAINT suspected_duplicate_breakdown_moves_revision_positive');
        DB::statement('ALTER TABLE suspected_duplicate_receipt_breakdown_moves DROP CONSTRAINT suspected_duplicate_breakdown_moves_status_supported');

        Schema::table('suspected_duplicate_receipt_breakdown_moves', function (Blueprint $table) {
            $table->dropColumn(['receipt_breakdown_revision', 'receipt_breakdown_status']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Removed Receipt Breakdown lifecycle data cannot be restored.');
    }
};
