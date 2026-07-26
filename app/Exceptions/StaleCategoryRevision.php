<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class StaleCategoryRevision extends Exception implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('This Category changed while you were reviewing it. Review its current state and try again.');
    }
}
