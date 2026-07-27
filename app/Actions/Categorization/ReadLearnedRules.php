<?php

namespace App\Actions\Categorization;

use App\LearnedRuleSuggestionStatus;
use App\Models\LearnedRule;
use App\Models\LearnedRuleBulkAction;
use App\Models\LearnedRuleSuggestion;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use LogicException;

/**
 * @phpstan-type LearnedRuleData array{id: int, revision: int, category_id: int, category_name: string, merchant_pattern: string, merchant_key: string, match_mode: string, transaction_kind: string|null, currency: string|null, payment_instrument_label: string|null, payment_instrument_last_four: string|null, activated_at: string, retired_at: string|null}
 * @phpstan-type LearnedRuleSuggestionData array{id: int, category_id: int, category_name: string, merchant_pattern: string, merchant_key: string, match_mode: string, transaction_kind: string|null, currency: string|null, payment_instrument_label: string|null, payment_instrument_last_four: string|null, evidence_count: int}
 */
final class ReadLearnedRules
{
    /**
     * @return array{
     *     rules: list<LearnedRuleData>,
     *     suggestions: list<LearnedRuleSuggestionData>,
     *     bulk_actions: list<array{id: int, rule_id: int, rule_revision: int, status: string, transaction_count: int, restored_count: int, skipped_count: int}>,
     *     pagination: array{
     *         rules: array{current_page: int, last_page: int, previous_page: int|null, next_page: int|null},
     *         suggestions: array{current_page: int, last_page: int, previous_page: int|null, next_page: int|null},
     *         bulk_actions: array{current_page: int, last_page: int, previous_page: int|null, next_page: int|null}
     *     }
     * }
     */
    public function handle(User $owner): array
    {
        $suggestionsPaginator = LearnedRuleSuggestion::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('status', LearnedRuleSuggestionStatus::Pending)
            ->whereHas('category', fn ($query) => $query->whereNull('retired_at'))
            ->with('category:id,name')
            ->latest()
            ->paginate(20, pageName: 'suggestions_page');
        $suggestions = $suggestionsPaginator->getCollection()
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

        $rulesPaginator = LearnedRule::query()
            ->whereBelongsTo($owner, 'owner')
            ->with('currentRevision.category:id,name')
            ->latest()
            ->paginate(20, pageName: 'rules_page');
        $rules = $rulesPaginator->getCollection()
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
                    'retired_at' => $rule->retired_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        $bulkActionsPaginator = LearnedRuleBulkAction::query()
            ->whereBelongsTo($owner, 'owner')
            ->withCount([
                'items as transaction_count',
                'items as restored_count' => fn ($query) => $query->where('status', 'restored'),
                'items as skipped_count' => fn ($query) => $query->where('status', 'skipped'),
            ])
            ->latest()
            ->paginate(20, pageName: 'bulk_actions_page');
        $bulkActions = $bulkActionsPaginator->getCollection()
            ->map(fn (LearnedRuleBulkAction $bulkAction): array => [
                'id' => $bulkAction->id,
                'rule_id' => $bulkAction->learned_rule_id,
                'rule_revision' => $bulkAction->learned_rule_revision,
                'status' => $bulkAction->status,
                'transaction_count' => $bulkAction->transaction_count,
                'restored_count' => $bulkAction->restored_count,
                'skipped_count' => $bulkAction->skipped_count,
            ])
            ->values()
            ->all();

        return [
            'rules' => array_values($rules),
            'suggestions' => array_values($suggestions),
            'bulk_actions' => array_values($bulkActions),
            'pagination' => [
                'rules' => $this->paginationData($rulesPaginator),
                'suggestions' => $this->paginationData($suggestionsPaginator),
                'bulk_actions' => $this->paginationData($bulkActionsPaginator),
            ],
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{current_page: int, last_page: int, previous_page: int|null, next_page: int|null}
     */
    private function paginationData(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'previous_page' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
            'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
        ];
    }
}
