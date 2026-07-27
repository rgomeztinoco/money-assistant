<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\LearnedRuleMatchMode;
use App\MerchantNormalizer;
use App\Models\LearnedRule;
use App\Models\Transaction;
use InvalidArgumentException;

final class ReadLearnedRuleCandidateFromCorrection
{
    public function __construct(private MerchantNormalizer $merchantNormalizer) {}

    /**
     * @return array{
     *     transaction_id: int,
     *     transaction_revision: int,
     *     category_id: int,
     *     category_name: string,
     *     merchant_pattern: string,
     *     merchant_key: string,
     *     match_mode: string,
     *     transaction_kind: string,
     *     currency: string,
     *     payment_instrument_label: null,
     *     payment_instrument_last_four: null
     * }|null
     */
    public function handle(Transaction $transaction): ?array
    {
        if ($transaction->category === null
            || $transaction->currentCategoryAssignment === null
            || $transaction->currentCategoryAssignment->source !== CategoryAssignmentProvenance::Owner
            || ! $transaction->currentCategoryAssignment->is_correction) {
            return null;
        }

        try {
            $merchantKey = $this->merchantNormalizer->normalize($transaction->merchant_description);
        } catch (InvalidArgumentException) {
            return null;
        }
        $alreadyActive = LearnedRule::query()
            ->matchingCurrentDefinition(
                $transaction->user_id,
                $transaction->category->id,
                $merchantKey,
                LearnedRuleMatchMode::Exact,
                $transaction->kind,
                $transaction->currency,
                null,
                null,
            )
            ->exists();

        if ($alreadyActive) {
            return null;
        }

        return [
            'transaction_id' => $transaction->id,
            'transaction_revision' => $transaction->revision,
            'category_id' => $transaction->category->id,
            'category_name' => $transaction->category->name,
            'merchant_pattern' => $transaction->merchant_description,
            'merchant_key' => $merchantKey,
            'match_mode' => LearnedRuleMatchMode::Exact->value,
            'transaction_kind' => $transaction->kind->value,
            'currency' => $transaction->currency->value,
            'payment_instrument_label' => null,
            'payment_instrument_last_four' => null,
        ];
    }
}
