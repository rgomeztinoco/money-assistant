<?php

namespace App\Actions\Ledger;

use App\ExactInteger;
use App\Models\ReceiptBreakdown;
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
        TransactionFieldResolution $resolution,
        mixed $replacementValue = null,
    ): Transaction {
        return DB::transaction(function () use (
            $owner,
            $transaction,
            $field,
            $resolution,
            $replacementValue,
        ): Transaction {
            $currentTransaction = Transaction::query()
                ->whereKey($transaction->getKey())
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($field->value, $currentTransaction->provisional_fields, true)) {
                throw new InvalidArgumentException('The Transaction field is not awaiting review.');
            }

            if ($resolution === TransactionFieldResolution::Correct) {
                $normalizedValue = $field->normalizeReplacement($replacementValue);
                $currentTransaction->setAttribute($field->value, $normalizedValue);
                $this->removeInvalidatedReceiptBreakdown($currentTransaction, $field);
            }

            $remainingProvisionalFields = [];

            foreach ($currentTransaction->provisional_fields as $fieldName) {
                if ($fieldName !== $field->value) {
                    $remainingProvisionalFields[] = $fieldName;
                }
            }

            $currentTransaction->provisional_fields = $remainingProvisionalFields;
            $currentTransaction->save();

            return $currentTransaction;
        });
    }

    private function removeInvalidatedReceiptBreakdown(
        Transaction $transaction,
        ReviewableTransactionField $field,
    ): void {
        if ($field !== ReviewableTransactionField::AmountMinor) {
            return;
        }

        $breakdown = ReceiptBreakdown::query()
            ->where('transaction_id', $transaction->getKey())
            ->lockForUpdate()
            ->first();

        if ($breakdown === null) {
            return;
        }

        $lineItemTotal = ExactInteger::from(0);

        foreach ($breakdown->lineItems()->lockForUpdate()->get() as $lineItem) {
            $lineItemTotal = $lineItemTotal->add(ExactInteger::from($lineItem->line_total_minor));
        }

        if ($lineItemTotal->compare(ExactInteger::from($transaction->amount_minor)) !== 0) {
            $breakdown->delete();
        }
    }
}
