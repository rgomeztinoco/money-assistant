<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\Models\CategoryAssignment;
use App\Models\Transaction;
use App\Models\User;

/**
 * @phpstan-type CategoryAssignmentProvenanceData array{
 *     source: string,
 *     owner: array{id: int, name: string}|null,
 *     linked_purchase: array{id: int, merchant_description: string}|null,
 *     learned_rule: array{id: int, revision: int}|null,
 *     bulk_action: array{id: int}|null,
 *     ai: array{classifier_version: string, confidence: int, outcome: string, explanation: string}|null
 * }
 */
class ReadCategoryAssignmentProvenance
{
    /** @return CategoryAssignmentProvenanceData|null */
    public function handle(Transaction $transaction, User $owner): ?array
    {
        $source = $transaction->category_assignment_provenance;

        if ($source === null) {
            return null;
        }

        $assignment = $transaction->currentCategoryAssignment;

        if (
            $assignment !== null
            && (
                $assignment->source !== $source
                || $assignment->category_id !== $transaction->category_id
            )
        ) {
            $assignment = null;
        }

        return [
            'source' => $source->value,
            'owner' => $source === CategoryAssignmentProvenance::Owner
                ? [
                    'id' => $assignment?->owner->id ?? $owner->id,
                    'name' => $assignment?->owner->name ?? $owner->name,
                ]
                : null,
            'linked_purchase' => $source === CategoryAssignmentProvenance::LinkedRefund
                ? $this->linkedPurchase($transaction, $assignment)
                : null,
            'learned_rule' => $assignment?->learned_rule_id !== null
                && $assignment->learned_rule_revision !== null
                    ? [
                        'id' => $assignment->learned_rule_id,
                        'revision' => $assignment->learned_rule_revision,
                    ]
                    : null,
            'bulk_action' => $assignment?->learned_rule_bulk_action_id === null
                ? null
                : ['id' => $assignment->learned_rule_bulk_action_id],
            'ai' => $source === CategoryAssignmentProvenance::Ai
                && $assignment?->ai_classifier_version !== null
                && $assignment->ai_confidence !== null
                && $assignment->ai_outcome !== null
                && $assignment->ai_explanation !== null
                    ? [
                        'classifier_version' => $assignment->ai_classifier_version,
                        'confidence' => $assignment->ai_confidence,
                        'outcome' => $assignment->ai_outcome->value,
                        'explanation' => $assignment->ai_explanation,
                    ]
                    : null,
        ];
    }

    /** @return array{id: int, merchant_description: string}|null */
    private function linkedPurchase(Transaction $transaction, ?CategoryAssignment $assignment): ?array
    {
        $purchase = $assignment === null
            ? $transaction->originalPurchase
            : $assignment->linkedPurchase;

        return $purchase === null
            ? null
            : [
                'id' => $purchase->id,
                'merchant_description' => $purchase->merchant_description,
            ];
    }
}
