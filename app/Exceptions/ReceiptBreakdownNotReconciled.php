<?php

namespace App\Exceptions;

use RuntimeException;

final class ReceiptBreakdownNotReconciled extends RuntimeException
{
    public function __construct(public readonly string $deltaMinor)
    {
        parent::__construct('The Receipt Breakdown does not reconcile exactly.');
    }
}
