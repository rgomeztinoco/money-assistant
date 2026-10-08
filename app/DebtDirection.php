<?php

namespace App;

enum DebtDirection: string
{
    case Owed = 'owed';
    case Receivable = 'receivable';

    public function movementDirection(DebtEntryKind $kind): MovementDirection
    {
        return ($this === self::Owed) === ($kind === DebtEntryKind::Funding)
            ? MovementDirection::Credit
            : MovementDirection::Debit;
    }
}
