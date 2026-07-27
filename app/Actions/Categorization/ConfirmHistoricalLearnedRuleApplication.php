<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\CategoryAssignment;
use App\Models\LearnedRule;
use App\Models\LearnedRuleBulkAction;
use App\Models\LearnedRuleBulkActionItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmHistoricalLearnedRuleApplication
{
    public function __construct(private ResolveLearnedRuleForTransaction $resolveLearnedRuleForTransaction) {}

    public function handle(User $owner, int $bulkActionId): LearnedRuleBulkAction
    {
        return DB::transaction(function () use ($owner, $bulkActionId): LearnedRuleBulkAction {
            User::query()->whereKey($owner->id)->lockForUpdate()->sole();

            $bulkAction = LearnedRuleBulkAction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($bulkActionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($bulkAction->status === 'applied') {
                return $bulkAction;
            }

            if ($bulkAction->status !== 'previewed' || $bulkAction->preview_expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'historical_application' => 'This historical application preview is no longer available.',
                ]);
            }

            $rule = LearnedRule::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($bulkAction->learned_rule_id)
                ->whereNull('retired_at')
                ->with('currentRevision')
                ->lockForUpdate()
                ->first();

            if ($rule === null
                || $rule->revision !== $bulkAction->learned_rule_revision
                || $rule->currentRevision === null) {
                throw ValidationException::withMessages([
                    'historical_application' => 'The Learned Rule changed after this historical application was previewed.',
                ]);
            }

            $activeRules = $this->resolveLearnedRuleForTransaction->activeRules($owner->id);

            if ($this->resolveLearnedRuleForTransaction->fingerprint($activeRules) !== $bulkAction->rules_fingerprint) {
                throw ValidationException::withMessages([
                    'historical_application' => 'Learned Rule precedence changed after this historical application was previewed.',
                ]);
            }

            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($rule->currentRevision->category_id)
                ->whereNull('retired_at')
                ->lockForUpdate()
                ->first();

            if ($category === null) {
                throw ValidationException::withMessages([
                    'historical_application' => 'The Learned Rule target Category is no longer active.',
                ]);
            }

            $itemsQuery = LearnedRuleBulkActionItem::query()
                ->whereBelongsTo($bulkAction, 'bulkAction')
                ->orderBy('id');

            (clone $itemsQuery)->chunkById(200, function ($items) use ($owner): void {
                $transactions = Transaction::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereIn('id', $items->pluck('transaction_id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($items as $item) {
                    $transaction = $transactions->get($item->transaction_id);

                    if ($transaction === null || $transaction->revision !== $item->expected_transaction_revision) {
                        throw ValidationException::withMessages([
                            'historical_application' => 'At least one previewed Transaction changed. No historical Categories were applied.',
                        ]);
                    }
                }
            });

            (clone $itemsQuery)->chunkById(200, function ($items) use ($owner, $category, $rule, $bulkAction): void {
                $transactions = Transaction::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereIn('id', $items->pluck('transaction_id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($items as $item) {
                    $transaction = $transactions->get($item->transaction_id);
                    $previousCategoryId = $transaction->category_id;
                    $transaction->category_id = $category->id;
                    $transaction->category_assignment_provenance = CategoryAssignmentProvenance::Owner;
                    $transaction->revision++;
                    $transaction->save();

                    CategoryAssignment::create([
                        'user_id' => $owner->id,
                        'transaction_id' => $transaction->id,
                        'category_id' => $category->id,
                        'previous_category_id' => $previousCategoryId,
                        'source' => CategoryAssignmentProvenance::Owner,
                        'is_correction' => true,
                        'transaction_revision' => $transaction->revision,
                        'learned_rule_id' => $rule->id,
                        'learned_rule_revision' => $rule->revision,
                        'learned_rule_bulk_action_id' => $bulkAction->id,
                    ]);

                    $item->applied_transaction_revision = $transaction->revision;
                    $item->status = 'applied';
                    $item->save();
                }
            });

            $bulkAction->status = 'applied';
            $bulkAction->applied_at = now();
            $bulkAction->save();

            return $bulkAction;
        }, 3);
    }
}
