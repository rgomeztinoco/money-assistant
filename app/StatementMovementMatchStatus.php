<?php

namespace App;

enum StatementMovementMatchStatus: string
{
    case New = 'new';
    case Matched = 'matched';
    case Ambiguous = 'ambiguous';
}
