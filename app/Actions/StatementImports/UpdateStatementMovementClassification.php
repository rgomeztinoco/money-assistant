<?php

namespace App\Actions\StatementImports;

use App\Actions\Ledger\UpdateTransaction;
use App\IncomeSource;
use App\Models\Category;
use App\Models\StatementImport;
use App\Models\StatementMovement;
use App\Models\Transaction;
use App\Models\User;
use App\StatementImports\StatementImportValidationException;
use App\StatementMovementClassification;
use App\TransactionKind;
use Illuminate\Support\Facades\DB;

class UpdateStatementMovementClassification
{
    public function __construct(private UpdateTransaction $updateTransaction) {}

    public function handle(
        User $owner,
        StatementImport $statementImport,
        StatementMovement $statementMovement,
        StatementMovementClassification $classification,
    ): StatementMovement {
        return DB::transaction(function () use ($owner, $statementImport, $statementMovement, $classification): StatementMovement {
            $currentMovement = StatementMovement::query()
                ->whereBelongsTo($statementImport, 'statementImport')
                ->whereKey($statementMovement->getKey())
                ->whereHas(
                    'statementImport',
                    fn ($query) => $query->whereBelongsTo($owner, 'owner'),
                )
                ->with('transaction')
                ->lockForUpdate()
                ->firstOrFail();
            $transaction = $currentMovement->transaction;
            $transactionKind = $classification->transactionKind();

            if (! $transaction instanceof Transaction || $transactionKind === null) {
                throw $this->invalid('Choose a classification for this Statement Movement.');
            }

            if ($transactionKind !== TransactionKind::Spending
                && $transaction->linkedRefunds()->whereNull('voided_at')->exists()) {
                throw $this->invalid('Unlink active Refunds before changing this Statement Movement classification.');
            }

            if (! $transactionKind->supportsCategory() && $transaction->receiptBreakdown()->exists()) {
                throw $this->invalid('Remove the Receipt Breakdown before changing this Statement Movement classification.');
            }

            $categoryId = $this->categoryId(
                owner: $owner,
                transaction: $transaction,
                previousClassification: $currentMovement->classification,
                classification: $classification,
            );

            $this->updateTransaction->handle(
                owner: $owner,
                transaction: $transaction,
                occurredOn: $transaction->occurred_on,
                amountMinor: $transaction->amount_minor,
                currency: $transaction->currency,
                kind: $transactionKind,
                direction: $transaction->direction,
                description: $transaction->description,
                incomeSource: $transactionKind === TransactionKind::Income
                    ? ($transaction->income_source ?? IncomeSource::Other)
                    : null,
                transferPurpose: $classification->transferPurpose(),
                instrumentLabel: $transaction->instrument_label,
                instrumentLastFour: $transaction->instrument_last_four,
                categoryId: $categoryId,
                originalSpendingId: $transactionKind === TransactionKind::Refund
                    ? $transaction->original_spending_id
                    : null,
                removeReceiptBreakdown: false,
            );

            $currentMovement->classification = $classification;
            $currentMovement->save();

            return $currentMovement->refresh()->load('transaction.category');
        }, 3);
    }

    private function categoryId(
        User $owner,
        Transaction $transaction,
        StatementMovementClassification $previousClassification,
        StatementMovementClassification $classification,
    ): ?int {
        $specialCategoryName = match ($classification) {
            StatementMovementClassification::Tax => 'Taxes',
            StatementMovementClassification::Fee => 'Bank Fees',
            default => null,
        };

        if ($specialCategoryName !== null) {
            return Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->availableForAssignment()
                ->whereRaw('lower(name) = lower(?)', [$specialCategoryName])
                ->value('id');
        }

        if (! $classification->contributesToSpending()) {
            return null;
        }

        return $previousClassification->contributesToSpending()
            ? $transaction->category_id
            : null;
    }

    private function invalid(string $message): StatementImportValidationException
    {
        return new StatementImportValidationException(
            $message,
            'invalid_movement_classification_change',
            'classification',
        );
    }
}
