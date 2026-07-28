<?php

namespace App\Exceptions;

use App\Models\ReceiptBreakdown;
use RuntimeException;

final class StaleReceiptBreakdownRevision extends RuntimeException
{
    public function __construct(public readonly int $currentRevision)
    {
        parent::__construct('The Receipt Breakdown draft changed before this operation was applied.');
    }

    public static function fromBreakdown(ReceiptBreakdown $breakdown): self
    {
        return new self($breakdown->revision);
    }
}
