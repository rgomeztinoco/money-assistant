<?php

namespace App\Integrations\BcrpData;

use Carbon\CarbonImmutable;

final readonly class BcrpExchangeRateObservation
{
    public function __construct(
        public CarbonImmutable $observedOn,
        public CarbonImmutable $retrievedAt,
        public string $value,
        public int $sourcePrecision,
    ) {}
}
