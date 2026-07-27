<?php

namespace App\Actions\Categorization;

use App\LearnedRuleSuggestionStatus;
use App\Models\LearnedRuleSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DismissLearnedRuleSuggestion
{
    public function handle(User $owner, int $suggestionId): void
    {
        DB::transaction(function () use ($owner, $suggestionId): void {
            $suggestion = LearnedRuleSuggestion::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($suggestionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($suggestion->status === LearnedRuleSuggestionStatus::Dismissed) {
                return;
            }

            if ($suggestion->status !== LearnedRuleSuggestionStatus::Pending) {
                throw ValidationException::withMessages([
                    'suggestion' => 'Only a pending Learned Rule suggestion may be dismissed.',
                ]);
            }

            $suggestion->status = LearnedRuleSuggestionStatus::Dismissed;
            $suggestion->dismissed_at = now();
            $suggestion->save();
        }, 3);
    }
}
