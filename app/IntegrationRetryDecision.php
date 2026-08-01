<?php

namespace App;

use App\Models\IntegrationIncident;
use Carbon\CarbonImmutable;

final readonly class IntegrationRetryDecision
{
    public function __construct(
        public IntegrationIncident $incident,
        public bool $shouldRetry,
        public ?CarbonImmutable $nextAttemptAt,
    ) {}
}
