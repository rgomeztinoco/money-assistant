<?php

namespace App\Contracts;

use App\Integrations\BcrpData\BcrpExchangeRateObservation;
use Carbon\CarbonImmutable;

interface BcrpData
{
    public function findObservation(CarbonImmutable $applicableOn): ?BcrpExchangeRateObservation;
}
