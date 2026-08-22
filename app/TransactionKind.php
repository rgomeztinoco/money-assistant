<?php

namespace App;

enum TransactionKind: string
{
    case Purchase = 'purchase';
    case Refund = 'refund';

    public function signedAmount(int|string $amountMinor): ExactInteger
    {
        $amount = ExactInteger::from($amountMinor);

        return $this === self::Refund
            ? ExactInteger::from(0)->subtract($amount)
            : $amount;
    }
}
