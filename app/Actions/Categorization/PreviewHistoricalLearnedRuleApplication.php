<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\Models\LearnedRule;
use App\Models\LearnedRuleBulkAction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PreviewHistoricalLearnedRuleApplication
{
    public function __construct(private ResolveLearnedRuleForTransaction $resolveLearnedRuleForTransaction) {}

    public function handle(User $owner, int $ruleId, int $expectedRevision): LearnedRuleBulkAction
    {
        return DB::transaction(function () use ($owner, $ruleId, $expectedRevision): LearnedRuleBulkAction {
            $rule = LearnedRule::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($ruleId)
                ->whereNull('retired_at')
                ->with('currentRevision')
                ->firstOrFail();

            if ($rule->revision !== $expectedRevision || $rule->currentRevision === null) {
                throw ValidationException::withMessages([
                    'expected_revision' => 'This Learned Rule changed after you reviewed it.',
                ]);
            }

            $activeRules = $this->resolveLearnedRuleForTransaction->activeRules($owner->id);
            $bulkAction = LearnedRuleBulkAction::create([
                'user_id' => $owner->id,
                'learned_rule_id' => $rule->id,
                'learned_rule_revision' => $rule->revision,
                'rules_fingerprint' => $this->resolveLearnedRuleForTransaction->fingerprint($activeRules),
                'status' => 'previewed',
                'preview_expires_at' => now()->addMinutes(30),
            ]);

            Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereNull('voided_at')
                ->orderBy('id')
                ->lazyById(200)
                ->each(function (Transaction $transaction) use ($bulkAction, $rule, $activeRules): void {
                    $resolution = $this->resolveLearnedRuleForTransaction->handleWithRules($transaction, $activeRules);

                    if ($resolution['winner']?->learned_rule_id !== $rule->id
                        || ($transaction->category_id === $resolution['winner']->category_id
                            && $transaction->category_assignment_provenance === CategoryAssignmentProvenance::Owner)) {
                        return;
                    }

                    $bulkAction->items()->create([
                        'transaction_id' => $transaction->id,
                        'expected_transaction_revision' => $transaction->revision,
                        'previous_category_id' => $transaction->category_id,
                        'status' => 'pending',
                    ]);
                });

            return $bulkAction;
        }, 3);
    }
}
