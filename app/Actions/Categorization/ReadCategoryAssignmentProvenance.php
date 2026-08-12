<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\Models\Transaction;
use App\Models\User;

/**
 * @phpstan-type CategoryAssignmentProvenanceData array{
 *     source: string,
 *     owner: array{id: int, name: string}|null,
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

        return [
            'source' => $source->value,
            'owner' => $source === CategoryAssignmentProvenance::Owner
                ? ['id' => $owner->id, 'name' => $owner->name]
                : null,
            'merchant_rule' => $source === CategoryAssignmentProvenance::MerchantRule
                && $transaction->merchant_rule_id !== null
                    ? ['id' => $transaction->merchant_rule_id]
                    : null,
        ];
    }
}
