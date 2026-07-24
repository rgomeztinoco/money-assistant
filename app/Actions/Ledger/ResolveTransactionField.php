<?php

namespace App\Actions\Ledger;

use App\Exceptions\StaleTransactionRevision;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use App\TransactionFieldResolution;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ResolveTransactionField
{
    public function handle(
        User $owner,
        Transaction $transaction,
        ReviewableTransactionField $field,
        int $expectedRevision,
        TransactionFieldResolution $resolution,
        mixed $correctedValue = null,
    ): Transaction {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('The expected Transaction revision must be positive.');
        }

        return DB::transaction(function () use (
            $owner,
            $transaction,
            $field,
            $expectedRevision,
            $resolution,
            $correctedValue,
        ): Transaction {
            $currentTransaction = Transaction::query()
                ->whereKey($transaction->getKey())
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->firstOrFail();

            if ($currentTransaction->revision !== $expectedRevision) {
                throw StaleTransactionRevision::fromTransaction($currentTransaction);
            }

            if (! in_array($field->value, $currentTransaction->provisional_fields, true)) {
                throw new InvalidArgumentException('The Transaction field is not awaiting review.');
            }

            $nextRevision = $currentTransaction->revision + 1;

            if ($resolution === TransactionFieldResolution::Correct) {
                $previousValue = $field->valueFor($currentTransaction);
                $normalizedValue = $field->normalizeCorrection($correctedValue);
                $currentTransaction->setAttribute($field->value, $normalizedValue);

                $currentTransaction->corrections()->create([
                    'field' => $field,
                    'previous_value' => $previousValue,
                    'corrected_value' => $field->valueFor($currentTransaction),
                    'transaction_revision' => $nextRevision,
                ]);
            }

            $remainingProvisionalFields = [];

            foreach ($currentTransaction->provisional_fields as $fieldName) {
                if ($fieldName !== $field->value) {
                    $remainingProvisionalFields[] = $fieldName;
                }
            }

            $currentTransaction->provisional_fields = $remainingProvisionalFields;
            $currentTransaction->revision = $nextRevision;
            $currentTransaction->save();

            return $currentTransaction;
        });
    }
}
