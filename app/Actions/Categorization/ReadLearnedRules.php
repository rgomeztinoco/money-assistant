<?php

namespace App\Actions\Categorization;

use App\LearnedRuleSuggestionStatus;
use App\Models\LearnedRule;
use App\Models\LearnedRuleSuggestion;
use App\Models\User;
use LogicException;

/**
 * @phpstan-type LearnedRuleData array{id: int, revision: int, category_id: int, category_name: string, merchant_pattern: string, merchant_key: string, match_mode: string, transaction_kind: string|null, currency: string|null, payment_instrument_label: string|null, payment_instrument_last_four: string|null, activated_at: string}
 * @phpstan-type LearnedRuleSuggestionData array{id: int, category_id: int, category_name: string, merchant_pattern: string, merchant_key: string, match_mode: string, transaction_kind: string|null, currency: string|null, payment_instrument_label: string|null, payment_instrument_last_four: string|null, evidence_count: int}
 */
final class ReadLearnedRules
{
    /**
     * @return array{
     *     rules: list<LearnedRuleData>,
     *     suggestions: list<LearnedRuleSuggestionData>
     * }
     */
    public function handle(User $owner): array
    {
        $suggestions = LearnedRuleSuggestion::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('status', LearnedRuleSuggestionStatus::Pending)
            ->whereHas('category', fn ($query) => $query->whereNull('retired_at'))
            ->with('category:id,name')
            ->latest()
            ->get()
            ->map(fn (LearnedRuleSuggestion $suggestion): array => [
                'id' => $suggestion->id,
                'category_id' => $suggestion->category_id,
                'category_name' => $suggestion->category->name,
                'merchant_pattern' => $suggestion->merchant_pattern,
                'merchant_key' => $suggestion->merchant_key,
                'match_mode' => $suggestion->match_mode->value,
                'transaction_kind' => $suggestion->transaction_kind?->value,
                'currency' => $suggestion->currency?->value,
                'payment_instrument_label' => $suggestion->payment_instrument_label,
                'payment_instrument_last_four' => $suggestion->payment_instrument_last_four,
                'evidence_count' => $suggestion->evidence_count,
            ])
            ->values()
            ->all();

        $rules = LearnedRule::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('retired_at')
            ->with('currentRevision.category:id,name')
            ->latest()
            ->get()
            ->map(function (LearnedRule $rule): array {
                $revision = $rule->currentRevision;

                if ($revision === null) {
                    throw new LogicException('An active Learned Rule must have a current revision.');
                }

                return [
                    'id' => $rule->id,
                    'revision' => $rule->revision,
                    'category_id' => $revision->category_id,
                    'category_name' => $revision->category->name,
                    'merchant_pattern' => $revision->merchant_pattern,
                    'merchant_key' => $revision->merchant_key,
                    'match_mode' => $revision->match_mode->value,
                    'transaction_kind' => $revision->transaction_kind?->value,
                    'currency' => $revision->currency?->value,
                    'payment_instrument_label' => $revision->payment_instrument_label,
                    'payment_instrument_last_four' => $revision->payment_instrument_last_four,
                    'activated_at' => $rule->activated_at->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'rules' => array_values($rules),
            'suggestions' => array_values($suggestions),
        ];
    }
}
