<?php

namespace App\Actions\Reporting;

use App\Currency;
use App\ExactInteger;
use InvalidArgumentException;

final class ConvertTransactionAmount
{
    public function handle(
        int|string $amountMinor,
        Currency $from,
        Currency $to,
        int|string $penPerUsdScaled,
    ): string {
        $amount = ExactInteger::from($amountMinor);
        $rate = ExactInteger::from($penPerUsdScaled);

        if ($amount->compare(ExactInteger::from(0)) < 0 || $rate->compare(ExactInteger::from(0)) <= 0) {
            throw new InvalidArgumentException('Conversion requires a non-negative amount and positive Daily Exchange Rate.');
        }

        if ($from === $to) {
            return $amount->value();
        }

        [$multiplier, $divisor] = $from === Currency::Usd
            ? [$rate->value(), '1000000']
            : ['1000000', $rate->value()];
        $numerator = bcmul($amount->value(), $multiplier);
        $quotient = bcdiv($numerator, $divisor, 0);
        $remainder = bcmod($numerator, $divisor);

        if (bccomp(bcmul($remainder, '2'), $divisor) >= 0) {
            $quotient = bcadd($quotient, '1');
        }

        return ExactInteger::from($quotient)->value();
    }
}
