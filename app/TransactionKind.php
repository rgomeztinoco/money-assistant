<?php

namespace App;

enum TransactionKind: string
{
    case Purchase = 'purchase';
    case Refund = 'refund';
}
