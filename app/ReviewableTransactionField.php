<?php

namespace App;

use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

enum ReviewableTransactionField: string
{
    case OccurredOn = 'occurred_on';
    case AmountMinor = 'amount_minor';
    case Currency = 'currency';
    case Kind = 'kind';
    case MerchantDescription = 'merchant_description';

    public function label(): string
    {
        return match ($this) {
            self::OccurredOn => 'Occurrence date',
            self::AmountMinor => 'Amount in minor units',
            self::Currency => 'Currency',
            self::Kind => 'Transaction kind',
            self::MerchantDescription => 'Merchant or description',
        };
    }

    public function valueFor(Transaction $transaction): string
    {
        return match ($this) {
            self::OccurredOn => $transaction->occurred_on->toDateString(),
            self::AmountMinor => (string) $transaction->amount_minor,
            self::Currency => $transaction->currency->value,
            self::Kind => $transaction->kind->value,
            self::MerchantDescription => $transaction->merchant_description,
        };
    }

    public function normalizeCorrection(
        mixed $value,
    ): CarbonImmutable|Currency|TransactionKind|int|string {
        return match ($this) {
            self::OccurredOn => $this->normalizeOccurrenceDate($value),
            self::AmountMinor => $this->normalizeAmountMinor($value),
            self::Currency => $this->normalizeCurrency($value),
            self::Kind => $this->normalizeKind($value),
            self::MerchantDescription => $this->normalizeMerchantDescription($value),
        };
    }

    private function normalizeOccurrenceDate(mixed $value): CarbonImmutable
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('The corrected occurrence date must use YYYY-MM-DD.');
        }

        try {
            $occurredOn = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (\Throwable) {
            throw new InvalidArgumentException('The corrected occurrence date must use YYYY-MM-DD.');
        }

        if ($occurredOn->toDateString() !== $value) {
            throw new InvalidArgumentException('The corrected occurrence date must use YYYY-MM-DD.');
        }

        return $occurredOn;
    }

    private function normalizeAmountMinor(mixed $value): int
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            throw new InvalidArgumentException('The corrected amount must be a positive integer.');
        }

        $amountMinor = (int) $value;
        $normalizedDigits = Str::of((string) $value)->ltrim('0')->toString();
        $normalizedDigits = $normalizedDigits === '' ? '0' : $normalizedDigits;

        if ($amountMinor < 1 || (string) $amountMinor !== $normalizedDigits) {
            throw new InvalidArgumentException('The corrected amount must be a positive integer.');
        }

        return $amountMinor;
    }

    private function normalizeCurrency(mixed $value): Currency
    {
        $currency = is_string($value) ? Currency::tryFrom($value) : null;

        return $currency ?? throw new InvalidArgumentException('The corrected currency is not supported.');
    }

    private function normalizeKind(mixed $value): TransactionKind
    {
        $kind = is_string($value) ? TransactionKind::tryFrom($value) : null;

        return $kind ?? throw new InvalidArgumentException('The corrected Transaction kind is not supported.');
    }

    private function normalizeMerchantDescription(mixed $value): string
    {
        $merchantDescription = is_string($value) ? Str::squish($value) : '';

        if ($merchantDescription === '' || Str::length($merchantDescription) > 255) {
            throw new InvalidArgumentException('A corrected merchant or short description is required.');
        }

        return $merchantDescription;
    }
}
