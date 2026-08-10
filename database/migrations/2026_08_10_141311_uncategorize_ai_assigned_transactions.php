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
        DB::transaction(function (): void {
            $aiDerivedLinkedRefundIds = DB::table('transactions as refunds')
                ->join('category_assignments as refund_assignments', function ($join): void {
                    $join->on('refund_assignments.transaction_id', '=', 'refunds.id')
                        ->on('refund_assignments.linked_purchase_id', '=', 'refunds.original_purchase_id');
                })
                ->where('refunds.category_assignment_provenance', 'linked_refund')
                ->where('refund_assignments.source', 'linked_refund')
                ->whereRaw('refund_assignments.id = (
                    SELECT current_refund_assignment.id
                    FROM category_assignments AS current_refund_assignment
                    WHERE current_refund_assignment.transaction_id = refunds.id
                    ORDER BY current_refund_assignment.transaction_revision DESC, current_refund_assignment.id DESC
                    LIMIT 1
                )')
                ->whereRaw("(
                    SELECT purchase_assignment.source
                    FROM category_assignments AS purchase_assignment
                    WHERE purchase_assignment.transaction_id = refund_assignments.linked_purchase_id
                        AND (
                            purchase_assignment.created_at < refund_assignments.created_at
                            OR (
                                purchase_assignment.created_at = refund_assignments.created_at
                                AND purchase_assignment.id < refund_assignments.id
                            )
                        )
                    ORDER BY purchase_assignment.created_at DESC, purchase_assignment.id DESC
                    LIMIT 1
                ) = 'ai'")
                ->pluck('refunds.id');

            DB::table('category_assignments')
                ->where('source', 'linked_refund')
                ->whereIn('transaction_id', $aiDerivedLinkedRefundIds)
                ->delete();

            DB::table('transactions')
                ->whereIn('id', $aiDerivedLinkedRefundIds)
                ->update([
                    'category_id' => null,
                    'category_assignment_provenance' => null,
                    'revision' => DB::raw('revision + 1'),
                    'updated_at' => now(),
                ]);

            DB::table('transactions')
                ->where('category_assignment_provenance', 'ai')
                ->update([
                    'category_id' => null,
                    'category_assignment_provenance' => null,
                    'revision' => DB::raw('revision + 1'),
                    'updated_at' => now(),
                ]);

            DB::table('integration_incidents')
                ->where(fn ($query) => $query
                    ->where('integration', 'ai')
                    ->orWhere('work_type', 'ai_classification'))
                ->delete();

            DB::table('category_assignments')
                ->where('source', 'ai')
                ->delete();

            foreach (['jobs', 'failed_jobs'] as $queueTable) {
                DB::table($queueTable)
                    ->whereRaw("payload::jsonb ->> 'displayName' = ?", [
                        'App\\Jobs\\ClassifyTransaction',
                    ])
                    ->delete();
            }
        });
    }

    /**
     * This destructive migration is intentionally irreversible.
     *
     * Restore removed data with a forward-fix migration when necessary.
     */
    public function down(): void
    {
        throw new LogicException('Removed AI categorization data cannot be restored.');
    }
};
