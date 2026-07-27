<?php

namespace App\Actions\Categorization;

use App\Currency;
use App\LearnedRuleDefinitionFingerprint;
use App\LearnedRuleMatchMode;
use App\LearnedRuleSuggestionStatus;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\LearnedRuleSuggestion;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateLearnedRuleFromCorrection
{
    public function __construct(
        private ReadLearnedRuleCandidateFromCorrection $readLearnedRuleCandidateFromCorrection,
        private LearnedRuleDefinitionFingerprint $learnedRuleDefinitionFingerprint,
    ) {}

    public function handle(User $owner, int $transactionId, int $expectedRevision): LearnedRule
    {
        return DB::transaction(function () use ($owner, $transactionId, $expectedRevision): LearnedRule {
            $transaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transactionId)
                ->with(['category', 'currentCategoryAssignment'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->revision !== $expectedRevision) {
                throw ValidationException::withMessages([
                    'expected_revision' => 'This Transaction changed after you reviewed the Learned Rule. Review its current conditions and try again.',
                ]);
            }

            $candidate = $this->readLearnedRuleCandidateFromCorrection->handle($transaction);

            if ($candidate === null || $transaction->category?->retired_at !== null) {
                throw ValidationException::withMessages([
                    'transaction_id' => 'Choose a Transaction with a current owner Category Correction.',
                ]);
            }

            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($candidate['category_id'])
                ->whereNull('retired_at')
                ->lockForUpdate()
                ->first();

            if ($category === null) {
                throw ValidationException::withMessages([
                    'transaction_id' => 'The prepared Learned Rule Category is no longer active.',
                ]);
            }

            $existingRule = LearnedRule::query()
                ->matchingCurrentDefinition(
                    $owner->id,
                    $candidate['category_id'],
                    $candidate['merchant_key'],
                    LearnedRuleMatchMode::from($candidate['match_mode']),
                    TransactionKind::from($candidate['transaction_kind']),
                    Currency::from($candidate['currency']),
                    null,
                    null,
                )
                ->first();

            $rule = $existingRule ?? LearnedRule::create([
                'user_id' => $owner->getKey(),
                'activated_at' => now(),
            ]);

            if ($existingRule === null) {
                $rule->revisions()->create([
                    'revision' => 1,
                    'category_id' => $candidate['category_id'],
                    'merchant_pattern' => $candidate['merchant_pattern'],
                    'merchant_key' => $candidate['merchant_key'],
                    'match_mode' => $candidate['match_mode'],
                    'transaction_kind' => $candidate['transaction_kind'],
                    'currency' => $candidate['currency'],
                    'source_category_assignment_id' => $transaction->currentCategoryAssignment->id,
                ]);
            }

            $definitionHash = $this->learnedRuleDefinitionFingerprint->make(
                $candidate['category_id'],
                $candidate['merchant_key'],
                LearnedRuleMatchMode::from($candidate['match_mode']),
                TransactionKind::from($candidate['transaction_kind']),
                Currency::from($candidate['currency']),
                null,
                null,
            );
            $suggestion = LearnedRuleSuggestion::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('definition_hash', $definitionHash)
                ->lockForUpdate()
                ->first();

            if ($suggestion !== null) {
                $suggestion->status = LearnedRuleSuggestionStatus::Accepted;
                $suggestion->accepted_rule_id = $rule->id;
                $suggestion->save();
            }

            return $rule;
        }, 3);
    }
}
