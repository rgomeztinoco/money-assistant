<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class StaleDailyExchangeRateRevision extends Exception implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('This Daily Exchange Rate changed while you were reviewing it. Review its current value and try again.');
    }
}
