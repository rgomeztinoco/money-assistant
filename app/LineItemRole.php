<?php

namespace App;

enum LineItemRole: string
{
    case PurchasedItem = 'purchased_item';
    case Tax = 'tax';
    case Discount = 'discount';
    case Tip = 'tip';
    case Fee = 'fee';
    case Rounding = 'rounding';
    case OtherAdjustment = 'other_adjustment';
    case Unidentified = 'unidentified';

    public function acceptsLineTotal(int $lineTotalMinor): bool
    {
        return match ($this) {
            self::PurchasedItem => $lineTotalMinor > 0,
            default => $lineTotalMinor !== 0,
        };
    }

    public function requiresCategoryForConfirmation(): bool
    {
        return ! in_array($this, [self::PurchasedItem, self::Unidentified], true);
    }
}
