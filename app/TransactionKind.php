<?php

namespace App;

enum TransactionKind: string
{
    case Spending = 'spending';
    case Refund = 'refund';
    case Income = 'income';
    case Transfer = 'transfer';
    case Debt = 'debt';

    public function affectsNetSpending(): bool
    {
        return $this === self::Spending || $this === self::Refund;
    }

    public function supportsCategory(): bool
    {
        return $this->affectsNetSpending();
    }

    public function netSpendingAmount(int|string $amountMinor): ExactInteger
    {
        $amount = ExactInteger::from($amountMinor);

        return match ($this) {
            self::Spending => $amount,
            self::Refund => ExactInteger::from(0)->subtract($amount),
            self::Income, self::Transfer, self::Debt => ExactInteger::from(0),
        };
    }
}
