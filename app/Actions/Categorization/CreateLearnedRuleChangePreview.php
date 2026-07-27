<?php

namespace App\Actions\Categorization;

use App\Currency;
use App\LearnedRuleMatchMode;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\LearnedRuleChangePreview;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Validation\ValidationException;

final class CreateLearnedRuleChangePreview
{
    public function __construct(private AnalyzeLearnedRuleDefinition $analyzeLearnedRuleDefinition) {}

    /**
     * @param  array{category_id: int, merchant_pattern: string, match_mode: string, transaction_kind?: string|null, currency?: string|null, payment_instrument_label?: string|null, payment_instrument_last_four?: string|null, learned_rule_id?: int|null, expected_revision?: int|null}  $input
     */
    public function handle(
        User $owner,
        array $input,
        ?int $sourceCategoryAssignmentId = null,
        ?int $learnedRuleSuggestionId = null,
    ): LearnedRuleChangePreview {
        $category = Category::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereKey($input['category_id'])
            ->whereNull('retired_at')
            ->firstOrFail();
        $revisedRule = isset($input['learned_rule_id'])
            ? LearnedRule::query()->whereBelongsTo($owner, 'owner')->with('currentRevision')->findOrFail($input['learned_rule_id'])
            : null;

        if ($revisedRule !== null
            && ($revisedRule->retired_at !== null || $revisedRule->revision !== ($input['expected_revision'] ?? null))) {
            throw ValidationException::withMessages([
                'expected_revision' => 'This Learned Rule changed after you reviewed it. Preview the current revision and try again.',
            ]);
        }

        $analysis = $this->analyzeLearnedRuleDefinition->handle(
            owner: $owner,
            category: $category,
            merchantPattern: $input['merchant_pattern'],
            matchMode: LearnedRuleMatchMode::from($input['match_mode']),
            transactionKind: isset($input['transaction_kind']) ? TransactionKind::from($input['transaction_kind']) : null,
            currency: isset($input['currency']) ? Currency::from($input['currency']) : null,
            paymentInstrumentLabel: $input['payment_instrument_label'] ?? null,
            paymentInstrumentLastFour: $input['payment_instrument_last_four'] ?? null,
            revisedRule: $revisedRule,
        );

        return LearnedRuleChangePreview::create([
            'user_id' => $owner->id,
            'learned_rule_id' => $revisedRule?->id,
            'expected_rule_revision' => $revisedRule?->revision,
            'source_category_assignment_id' => $sourceCategoryAssignmentId,
            'learned_rule_suggestion_id' => $learnedRuleSuggestionId,
            'definition' => $analysis['definition'],
            'analysis' => $analysis,
            'resource_fingerprint' => $analysis['resource_fingerprint'],
            'expires_at' => now()->addMinutes(30),
        ]);
    }
}
