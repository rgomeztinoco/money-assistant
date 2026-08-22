<?php

namespace App;

enum MovementDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
