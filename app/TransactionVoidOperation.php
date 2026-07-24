<?php

namespace App;

enum TransactionVoidOperation: string
{
    case Void = 'void';
    case Restore = 'restore';
}
