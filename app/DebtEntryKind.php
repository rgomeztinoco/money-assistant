<?php

namespace App;

enum DebtEntryKind: string
{
    case Funding = 'funding';
    case Repayment = 'repayment';
    case Adjustment = 'adjustment';
}
