<?php

namespace App\Actions\Ledger;

use App\Actions\Categorization\ApplyLearnedRuleToTransaction;
use App\Actions\Reporting\DiscoverMissingDailyExchangeRates;
use App\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecordManualTransaction
{
    public function __construct(
        private ApplyLearnedRuleToTransaction $applyLearnedRuleToTransaction,
        private DiscoverMissingDailyExchangeRates $discoverMissingDailyExchangeRates,
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

        $transaction = DB::transaction(function () use ($owner, $occurredOn, $amountMinor, $currency, $kind, $merchantDescription, $provisionalFields, $paymentInstrumentLabel, $paymentInstrumentLastFour): Transaction {
            $transaction = Transaction::create([
                'user_id' => $owner->getKey(),
                'occurred_on' => $occurredOn,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'kind' => $kind,
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

            return $this->applyLearnedRuleToTransaction->handle($transaction);
        });

        $this->discoverMissingDailyExchangeRates->handle();

        return $transaction;
    }
}
