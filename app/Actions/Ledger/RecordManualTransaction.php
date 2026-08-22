<?php

namespace App\Actions\Ledger;

use App\Actions\Categorization\ApplyMerchantRuleToTransaction;
use App\Currency;
use App\IncomeSource;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use App\TransactionDirection;
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
        string $merchantDescription,
        ?TransactionDirection $direction = null,
        ?IncomeSource $incomeSource = null,
        ?TransferPurpose $transferPurpose = null,
        array $provisionalFields = [],
        ?string $paymentInstrumentLabel = null,
        ?string $paymentInstrumentLastFour = null,
    ): Transaction {
        if (! is_int($amountMinor) || $amountMinor <= 0) {
            throw new InvalidArgumentException('A Transaction amount must be positive.');
        }

        $merchantDescription = Str::squish($merchantDescription);

        if ($merchantDescription === '') {
            throw new InvalidArgumentException('A merchant or short description is required.');
        }

        $direction ??= match ($kind) {
            TransactionKind::Spending, TransactionKind::Transfer => TransactionDirection::Debit,
            TransactionKind::Refund, TransactionKind::Income => TransactionDirection::Credit,
        };

        return DB::transaction(function () use ($owner, $occurredOn, $amountMinor, $currency, $kind, $direction, $merchantDescription, $incomeSource, $transferPurpose, $provisionalFields, $paymentInstrumentLabel, $paymentInstrumentLastFour): Transaction {
            $transaction = Transaction::create([
                'user_id' => $owner->getKey(),
                'occurred_on' => $occurredOn,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'kind' => $kind,
                'direction' => $direction,
                'income_source' => $kind === TransactionKind::Income ? $incomeSource : null,
                'transfer_purpose' => $kind === TransactionKind::Transfer ? $transferPurpose : null,
                'merchant_description' => $merchantDescription,
                'payment_instrument_label' => $paymentInstrumentLabel,
                'payment_instrument_last_four' => $paymentInstrumentLastFour,
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
