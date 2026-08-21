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
    case Warda = 'warda';
    case AlreadyRecorded = 'already_recorded';
    case NotAMovement = 'not_a_movement';
}
