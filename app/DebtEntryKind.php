<?php

namespace App;

enum DebtEntryKind: string
{
    case Funding = 'funding';
    case Repayment = 'repayment';
    case InterestCharge = 'interest_charge';
    case Adjustment = 'adjustment';
}
