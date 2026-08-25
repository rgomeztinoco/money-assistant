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
        ], true);
    }

    public function transactionKind(): ?TransactionKind
    {
        return match ($this) {
            self::Purchase, self::Fee, self::Tax => TransactionKind::Spending,
            self::Refund => TransactionKind::Refund,
            self::Income => TransactionKind::Income,
            self::Transfer, self::CardPayment, self::Savings => TransactionKind::Transfer,
            self::NeedsClassification, self::AlreadyRecorded, self::NotAMovement => null,
        };
    }

    public function transferPurpose(): ?TransferPurpose
    {
        return match ($this) {
            self::Savings => TransferPurpose::Savings,
            self::CardPayment => TransferPurpose::CardPayment,
            self::Transfer => TransferPurpose::Internal,
            default => null,
        };
    }

    public function isCompatibleWith(
        TransactionKind $transactionKind,
        ?TransferPurpose $transferPurpose,
    ): bool {
        return $this->transactionKind() === $transactionKind
            && ($this->transferPurpose() === null || $this->transferPurpose() === $transferPurpose);
    }

    public function summaryKey(MovementDirection $direction): ?string
    {
        return match ($this) {
            self::Purchase, self::Fee, self::Tax => 'spending_minor',
            self::Refund => 'refunds_minor',
            self::Income => 'income_minor',
            self::Transfer => $direction === MovementDirection::Credit
                ? 'transfers_in_minor'
                : 'transfers_out_minor',
            self::Savings => $direction === MovementDirection::Credit
                ? 'savings_withdrawals_minor'
                : 'savings_deposits_minor',
            default => null,
        };
    }
}
