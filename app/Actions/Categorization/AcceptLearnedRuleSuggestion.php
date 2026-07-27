<?php

namespace App\Actions\Categorization;

use App\LearnedRuleSuggestionStatus;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\LearnedRuleSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcceptLearnedRuleSuggestion
{
    public function handle(User $owner, int $suggestionId): LearnedRule
    {
        return DB::transaction(function () use ($owner, $suggestionId): LearnedRule {
            $suggestionSnapshot = LearnedRuleSuggestion::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($suggestionId)
                ->firstOrFail();

            if ($suggestionSnapshot->status === LearnedRuleSuggestionStatus::Accepted
                && $suggestionSnapshot->accepted_rule_id !== null) {
                return LearnedRule::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->findOrFail($suggestionSnapshot->accepted_rule_id);
            }

            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($suggestionSnapshot->category_id)
                ->whereNull('retired_at')
                ->lockForUpdate()
                ->first();

            if ($category === null) {
                throw ValidationException::withMessages([
                    'suggestion' => 'The suggested Category is no longer active.',
                ]);
            }

            $suggestion = LearnedRuleSuggestion::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($suggestionId)
                ->with('evidence')
                ->lockForUpdate()
                ->firstOrFail();

            if ($suggestion->status === LearnedRuleSuggestionStatus::Accepted
                && $suggestion->accepted_rule_id !== null) {
                return LearnedRule::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->findOrFail($suggestion->accepted_rule_id);
            }

            if ($suggestion->status !== LearnedRuleSuggestionStatus::Pending) {
                throw ValidationException::withMessages([
                    'suggestion' => 'Only a pending Learned Rule suggestion may be accepted.',
                ]);
            }

            $existingRule = LearnedRule::query()
                ->matchingCurrentDefinition(
                    $owner->id,
                    $suggestion->category_id,
                    $suggestion->merchant_key,
                    $suggestion->match_mode,
                    $suggestion->transaction_kind,
                    $suggestion->currency,
                    $suggestion->payment_instrument_label,
                    $suggestion->payment_instrument_last_four,
                )
                ->first();

            $rule = $existingRule === null
                ? LearnedRule::create([
                    'user_id' => $owner->getKey(),
                    'activated_at' => now(),
                ])
                : $existingRule;

            if ($existingRule === null) {
                $firstEvidence = $suggestion->evidence->sortBy('id')->first();

                $rule->revisions()->create([
                    'revision' => 1,
                    'category_id' => $suggestion->category_id,
                    'merchant_pattern' => $suggestion->merchant_pattern,
                    'merchant_key' => $suggestion->merchant_key,
                    'match_mode' => $suggestion->match_mode,
                    'transaction_kind' => $suggestion->transaction_kind,
                    'currency' => $suggestion->currency,
                    'payment_instrument_label' => $suggestion->payment_instrument_label,
                    'payment_instrument_last_four' => $suggestion->payment_instrument_last_four,
                    'source_category_assignment_id' => $firstEvidence?->category_assignment_id,
                ]);
            }

            $suggestion->status = LearnedRuleSuggestionStatus::Accepted;
            $suggestion->accepted_rule_id = $rule->id;
            $suggestion->save();

            return $rule;
        }, 3);
    }
}
