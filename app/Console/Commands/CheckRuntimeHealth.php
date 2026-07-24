<?php

namespace App\Console\Commands;

use App\Operations\RuntimeHealth;
use App\RuntimeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:health-check {service : The worker or scheduler service}')]
#[Description('Check that a runtime service completed a recent durable health probe')]
class CheckRuntimeHealth extends Command
{
    public function handle(RuntimeHealth $runtimeHealth): int
    {
        $service = RuntimeService::tryFrom((string) $this->argument('service'));

        if ($service === null) {
            $this->components->error('The service must be worker or scheduler.');

            return self::INVALID;
        }

        if (! $runtimeHealth->isFresh($service)) {
            $this->components->error("The {$service->value} health probe is stale or missing.");

            return self::FAILURE;
        }

        $this->components->info("The {$service->value} health probe is fresh.");

        return self::SUCCESS;
    }
}
