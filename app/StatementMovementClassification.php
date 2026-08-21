<?php

namespace App;

enum StatementMovementClassification: string
{
    case NeedsClassification = 'needs_classification';
    case Purchase = 'purchase';
    case Refund = 'refund';
    case Fee = 'fee';
    case Tax = 'tax';
    case Income = 'income';
    case Transfer = 'transfer';
    case CardPayment = 'card_payment';
    case Savings = 'savings';
    case AlreadyRecorded = 'already_recorded';
    case NotAMovement = 'not_a_movement';

    public function contributesToSpending(): bool
    {
        return in_array($this, [
            self::Purchase,
            self::Refund,
            self::Fee,
            self::Tax,
            self::Savings,
        ], true);
    }

    public function transactionKind(StatementMovementDirection $direction): ?TransactionKind
    {
        if (! $this->contributesToSpending()) {
            return null;
        }

        if ($this === self::Refund || ($this === self::Savings && $direction === StatementMovementDirection::Credit)) {
            return TransactionKind::Refund;
        }

        return TransactionKind::Purchase;
    }

    public function summaryKey(StatementMovementDirection $direction): ?string
    {
        return match ($this) {
            self::Purchase, self::Fee, self::Tax => 'spending_minor',
            self::Refund => 'refunds_minor',
            self::Income => 'income_minor',
            self::Transfer => $direction === StatementMovementDirection::Credit
                ? 'transfers_in_minor'
                : 'transfers_out_minor',
            self::Savings => $direction === StatementMovementDirection::Credit
                ? 'savings_withdrawals_minor'
                : 'savings_deposits_minor',
            default => null,
        };
    }
}
