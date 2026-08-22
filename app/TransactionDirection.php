<?php

namespace App;

enum TransactionDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
