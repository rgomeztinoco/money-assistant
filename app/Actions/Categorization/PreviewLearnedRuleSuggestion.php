<?php

namespace App\Actions\Categorization;

use App\LearnedRuleSuggestionStatus;
use App\Models\Category;
use App\Models\LearnedRuleChangePreview;
use App\Models\LearnedRuleSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PreviewLearnedRuleSuggestion
{
    public function __construct(private CreateLearnedRuleChangePreview $createLearnedRuleChangePreview) {}

    public function handle(User $owner, int $suggestionId): LearnedRuleChangePreview
    {
        return DB::transaction(function () use ($owner, $suggestionId): LearnedRuleChangePreview {
            $suggestion = LearnedRuleSuggestion::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($suggestionId)
                ->with('evidence')
                ->lockForUpdate()
                ->firstOrFail();

            if ($suggestion->status !== LearnedRuleSuggestionStatus::Pending) {
                throw ValidationException::withMessages([
                    'suggestion' => 'Only a pending Learned Rule suggestion may be previewed.',
                ]);
            }

            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($suggestion->category_id)
                ->whereNull('retired_at')
                ->first();

            if ($category === null) {
                throw ValidationException::withMessages([
                    'suggestion' => 'The suggested Category is no longer active.',
                ]);
            }

            return $this->createLearnedRuleChangePreview->handle(
                $owner,
                [
                    'category_id' => $suggestion->category_id,
                    'merchant_pattern' => $suggestion->merchant_pattern,
                    'match_mode' => $suggestion->match_mode->value,
                    'transaction_kind' => $suggestion->transaction_kind?->value,
                    'currency' => $suggestion->currency?->value,
                    'payment_instrument_label' => $suggestion->payment_instrument_label,
                    'payment_instrument_last_four' => $suggestion->payment_instrument_last_four,
                ],
                sourceCategoryAssignmentId: $suggestion->evidence->sortBy('id')->first()?->category_assignment_id,
                learnedRuleSuggestionId: $suggestion->id,
            );
        }, 3);
    }
}
