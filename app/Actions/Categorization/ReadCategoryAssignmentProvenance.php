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
 *     merchant_rule: array{id: int}|null
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
            'merchant_rule' => $assignment?->merchant_rule_id === null
                ? null
                : ['id' => $assignment->merchant_rule_id],
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
