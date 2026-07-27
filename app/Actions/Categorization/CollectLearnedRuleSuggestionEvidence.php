<?php

namespace App\Actions\Categorization;

use App\LearnedRuleDefinitionFingerprint;
use App\LearnedRuleMatchMode;
use App\LearnedRuleSuggestionStatus;
use App\MerchantNormalizer;
use App\Models\CategoryAssignment;
use App\Models\LearnedRuleSuggestion;
use App\Models\LearnedRuleSuggestionEvidence;
use App\Models\Transaction;
use InvalidArgumentException;

final class CollectLearnedRuleSuggestionEvidence
{
    public function __construct(
        private MerchantNormalizer $merchantNormalizer,
        private LearnedRuleDefinitionFingerprint $learnedRuleDefinitionFingerprint,
    ) {}

    public function handle(Transaction $transaction, ?CategoryAssignment $assignment): void
    {
        $previousSuggestions = LearnedRuleSuggestion::query()
            ->whereHas('evidence', fn ($query) => $query->where('transaction_id', $transaction->id))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        LearnedRuleSuggestionEvidence::query()
            ->where('transaction_id', $transaction->id)
            ->delete();

        foreach ($previousSuggestions as $previousSuggestion) {
            $this->refreshEvidenceState($previousSuggestion);
        }

        if ($assignment?->category_id === null) {
            return;
        }

        try {
            $merchantKey = $this->merchantNormalizer->normalize($transaction->merchant_description);
        } catch (InvalidArgumentException) {
            return;
        }

        $definitionHash = $this->learnedRuleDefinitionFingerprint->make(
            $assignment->category_id,
            $merchantKey,
            LearnedRuleMatchMode::Exact,
            $transaction->kind,
            $transaction->currency,
            null,
            null,
        );

        $suggestion = LearnedRuleSuggestion::query()
            ->where('user_id', $transaction->user_id)
            ->where('definition_hash', $definitionHash)
            ->lockForUpdate()
            ->first();

        if ($suggestion === null) {
            $suggestion = LearnedRuleSuggestion::create([
                'user_id' => $transaction->user_id,
                'definition_hash' => $definitionHash,
                'category_id' => $assignment->category_id,
                'merchant_pattern' => $transaction->merchant_description,
                'merchant_key' => $merchantKey,
                'match_mode' => LearnedRuleMatchMode::Exact,
                'transaction_kind' => $transaction->kind,
                'currency' => $transaction->currency,
            ]);
        }

        $suggestion->evidence()->firstOrCreate(
            ['transaction_id' => $transaction->id],
            ['category_assignment_id' => $assignment->id],
        );

        $this->refreshEvidenceState($suggestion);
    }

    private function refreshEvidenceState(LearnedRuleSuggestion $suggestion): void
    {
        $evidenceCount = $suggestion->evidence()->count();
        $suggestion->evidence_count = $evidenceCount;

        if ($evidenceCount >= 2 && $suggestion->status === LearnedRuleSuggestionStatus::Collecting) {
            $suggestion->status = LearnedRuleSuggestionStatus::Pending;
        }

        if ($evidenceCount < 2 && $suggestion->status === LearnedRuleSuggestionStatus::Pending) {
            $suggestion->status = LearnedRuleSuggestionStatus::Collecting;
        }

        $suggestion->save();
    }
}
