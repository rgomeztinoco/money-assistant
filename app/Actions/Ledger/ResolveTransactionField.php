<?php

namespace App\Actions\Ledger;

use App\Actions\Categorization\CollectLearnedRuleSuggestionEvidence;
use App\CategoryAssignmentProvenance;
use App\ExactInteger;
use App\Exceptions\StaleTransactionRevision;
use App\Models\Category;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use App\TransactionFieldResolution;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ResolveTransactionField
{
    public function __construct(
        private CollectLearnedRuleSuggestionEvidence $collectLearnedRuleSuggestionEvidence,
    ) {}

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
                $this->demoteInvalidatedReceiptBreakdown($currentTransaction, $field);

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

            if ($resolution === TransactionFieldResolution::Correct
                && in_array($field, [
                    ReviewableTransactionField::MerchantDescription,
                    ReviewableTransactionField::Kind,
                    ReviewableTransactionField::Currency,
                ], true)) {
                $currentAssignment = $currentTransaction->currentCategoryAssignment()->first();

                if ($currentAssignment?->source === CategoryAssignmentProvenance::Owner
                    && $currentAssignment->is_correction) {
                    Category::query()
                        ->whereKey($currentAssignment->category_id)
                        ->whereBelongsTo($owner, 'owner')
                        ->lockForUpdate()
                        ->first();

                    $this->collectLearnedRuleSuggestionEvidence->handle(
                        $currentTransaction,
                        $currentAssignment,
                    );
                }
            }

            return $currentTransaction;
        });
    }

    private function demoteInvalidatedReceiptBreakdown(
        Transaction $transaction,
        ReviewableTransactionField $field,
    ): void {
        if (! in_array($field, [
            ReviewableTransactionField::AmountMinor,
            ReviewableTransactionField::Currency,
            ReviewableTransactionField::Kind,
        ], true)) {
            return;
        }

        $breakdowns = ReceiptBreakdown::query()
            ->where('transaction_id', $transaction->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $confirmedBreakdown = $breakdowns->firstWhere('status', 'confirmed');

        if ($confirmedBreakdown === null) {
            return;
        }

        if ($field === ReviewableTransactionField::AmountMinor) {
            $lineItemTotal = ExactInteger::from(0);

            foreach ($confirmedBreakdown->lineItems()->lockForUpdate()->get() as $lineItem) {
                $lineItemTotal = $lineItemTotal->add(ExactInteger::from($lineItem->line_total_minor));
            }

            if ($lineItemTotal->compare(ExactInteger::from($transaction->amount_minor)) === 0) {
                return;
            }
        }

        $confirmedBreakdown->status = $breakdowns->contains('status', 'draft')
            ? 'superseded'
            : 'draft';
        $confirmedBreakdown->confirmed_at = $confirmedBreakdown->status === 'draft'
            ? null
            : $confirmedBreakdown->confirmed_at;
        $confirmedBreakdown->revision++;
        $confirmedBreakdown->save();
    }
}
