<?php

namespace App\Actions\Categorization;

use App\Currency;
use App\LearnedRuleDefinitionFingerprint;
use App\LearnedRuleMatchMode;
use App\LearnedRuleSuggestionStatus;
use App\Models\Category;
use App\Models\LearnedRuleChangePreview;
use App\Models\LearnedRuleSuggestion;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PreviewLearnedRuleFromCorrection
{
    public function __construct(
        private ReadLearnedRuleCandidateFromCorrection $readLearnedRuleCandidateFromCorrection,
        private CreateLearnedRuleChangePreview $createLearnedRuleChangePreview,
        private LearnedRuleDefinitionFingerprint $learnedRuleDefinitionFingerprint,
    ) {}

    public function handle(User $owner, int $transactionId, int $expectedRevision): LearnedRuleChangePreview
    {
        return DB::transaction(function () use ($owner, $transactionId, $expectedRevision): LearnedRuleChangePreview {
            $transaction = Transaction::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($transactionId)
                ->with(['category', 'currentCategoryAssignment'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->revision !== $expectedRevision) {
                throw ValidationException::withMessages([
                    'expected_revision' => 'This Transaction changed after you reviewed the Learned Rule.',
                ]);
            }

            $candidate = $this->readLearnedRuleCandidateFromCorrection->handle($transaction);
            $category = $candidate === null
                ? null
                : Category::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereKey($candidate['category_id'])
                    ->whereNull('retired_at')
                    ->first();

            if ($candidate === null || $category === null || $transaction->currentCategoryAssignment === null) {
                throw ValidationException::withMessages([
                    'transaction_id' => 'Choose a Transaction with a current owner Category Correction.',
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
            $suggestionId = LearnedRuleSuggestion::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('definition_hash', $definitionHash)
                ->where('status', LearnedRuleSuggestionStatus::Pending)
                ->value('id');

            return $this->createLearnedRuleChangePreview->handle(
                $owner,
                [
                    'category_id' => $candidate['category_id'],
                    'merchant_pattern' => $candidate['merchant_pattern'],
                    'match_mode' => $candidate['match_mode'],
                    'transaction_kind' => $candidate['transaction_kind'],
                    'currency' => $candidate['currency'],
                ],
                sourceCategoryAssignmentId: $transaction->currentCategoryAssignment->id,
                learnedRuleSuggestionId: is_int($suggestionId) ? $suggestionId : null,
            );
        }, 3);
    }
}
