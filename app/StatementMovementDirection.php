<?php

namespace App;

enum StatementMovementDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
