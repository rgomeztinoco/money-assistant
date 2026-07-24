<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class IdempotencyKeyConflict extends Exception implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('This idempotency key was already used for a different Transaction command.');
    }
}
