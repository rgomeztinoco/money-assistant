<?php

namespace App\Actions\Ledger;

use App\Actions\Categorization\ApplyMerchantRuleToTransaction;
use App\Currency;
use App\IncomeSource;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\ReviewableTransactionField;
use App\TransactionKind;
use App\TransferPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecordManualTransaction
{
    public function __construct(
        private ApplyMerchantRuleToTransaction $applyMerchantRuleToTransaction,
    ) {}

    /**
     * @param  list<ReviewableTransactionField>  $provisionalFields
     */
    public function handle(
        User $owner,
        CarbonImmutable $occurredOn,
        mixed $amountMinor,
        Currency $currency,
        TransactionKind $kind,
        string $description,
        ?MovementDirection $direction = null,
        ?IncomeSource $incomeSource = null,
        ?TransferPurpose $transferPurpose = null,
        array $provisionalFields = [],
        ?string $instrumentLabel = null,
        ?string $instrumentLastFour = null,
    ): Transaction {
        if (! is_int($amountMinor) || $amountMinor <= 0) {
            throw new InvalidArgumentException('A Transaction amount must be positive.');
        }

        $description = Str::squish($description);

        if ($description === '') {
            throw new InvalidArgumentException('A short description is required.');
        }

        $direction ??= match ($kind) {
            TransactionKind::Spending, TransactionKind::Transfer => MovementDirection::Debit,
            TransactionKind::Refund, TransactionKind::Income => MovementDirection::Credit,
        };

        return DB::transaction(function () use ($owner, $occurredOn, $amountMinor, $currency, $kind, $direction, $description, $incomeSource, $transferPurpose, $provisionalFields, $instrumentLabel, $instrumentLastFour): Transaction {
            $transaction = Transaction::create([
                'user_id' => $owner->getKey(),
                'occurred_on' => $occurredOn,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'kind' => $kind,
                'direction' => $direction,
                'income_source' => $kind === TransactionKind::Income ? $incomeSource : null,
                'transfer_purpose' => $kind === TransactionKind::Transfer ? $transferPurpose : null,
                'description' => $description,
                'instrument_label' => $instrumentLabel,
                'instrument_last_four' => $instrumentLastFour,
                'confirmed_at' => now(),
                'provisional_fields' => collect($provisionalFields)
                    ->map(fn (ReviewableTransactionField $field): string => $field->value)
                    ->unique()
                    ->values()
                    ->all(),
            ]);

            return $kind->supportsCategory()
                ? $this->applyMerchantRuleToTransaction->handle($transaction)
                : $transaction;
        });
    }
}
