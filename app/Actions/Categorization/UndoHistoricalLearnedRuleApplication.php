<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\Models\CategoryAssignment;
use App\Models\LearnedRuleBulkAction;
use App\Models\LearnedRuleBulkActionItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UndoHistoricalLearnedRuleApplication
{
    public function handle(User $owner, int $bulkActionId): LearnedRuleBulkAction
    {
        return DB::transaction(function () use ($owner, $bulkActionId): LearnedRuleBulkAction {
            $bulkAction = LearnedRuleBulkAction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($bulkActionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($bulkAction->status === 'undone') {
                return $bulkAction;
            }

            if ($bulkAction->status !== 'applied') {
                throw ValidationException::withMessages([
                    'historical_application' => 'Only an applied historical action may be undone.',
                ]);
            }

            LearnedRuleBulkActionItem::query()
                ->whereBelongsTo($bulkAction, 'bulkAction')
                ->where('status', 'applied')
                ->orderBy('id')
                ->chunkById(200, function ($items) use ($owner, $bulkAction): void {
                    $transactions = Transaction::query()
                        ->whereBelongsTo($owner, 'owner')
                        ->whereIn('id', $items->pluck('transaction_id'))
                        ->with('currentCategoryAssignment')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    foreach ($items as $item) {
                        $transaction = $transactions->get($item->transaction_id);
                        $isUnchanged = $transaction !== null
                            && $transaction->revision === $item->applied_transaction_revision
                            && $transaction->currentCategoryAssignment?->learned_rule_bulk_action_id === $bulkAction->id;

                        if (! $isUnchanged) {
                            $item->status = 'skipped';
                            $item->save();

                            continue;
                        }

                        $currentCategoryId = $transaction->category_id;
                        $transaction->category_id = $item->previous_category_id;
                        $transaction->category_assignment_provenance = $item->previous_category_id === null
                            ? null
                            : CategoryAssignmentProvenance::Owner;
                        $transaction->revision++;
                        $transaction->save();

                        CategoryAssignment::create([
                            'user_id' => $owner->id,
                            'transaction_id' => $transaction->id,
                            'category_id' => $item->previous_category_id,
                            'previous_category_id' => $currentCategoryId,
                            'source' => CategoryAssignmentProvenance::Owner,
                            'is_correction' => true,
                            'transaction_revision' => $transaction->revision,
                            'learned_rule_bulk_action_id' => $bulkAction->id,
                        ]);

                        $item->undo_transaction_revision = $transaction->revision;
                        $item->status = 'restored';
                        $item->save();
                    }
                });

            $bulkAction->status = 'undone';
            $bulkAction->undone_at = now();
            $bulkAction->save();

            return $bulkAction;
        }, 3);
    }
}
