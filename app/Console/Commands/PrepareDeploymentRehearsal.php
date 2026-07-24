<?php

namespace App\Console\Commands;

use App\Operations\DeploymentRehearsal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('app:deployment-rehearsal:prepare {rehearsal : A unique UUID for this rehearsal}')]
#[Description('Create durable queued and scheduled probes before restarting the production stack')]
class PrepareDeploymentRehearsal extends Command
{
    public function handle(DeploymentRehearsal $deploymentRehearsal): int
    {
        $rehearsalId = (string) $this->argument('rehearsal');

        if (! Str::isUuid($rehearsalId)) {
            $this->components->error('The rehearsal identifier must be a UUID.');

            return self::INVALID;
        }

        $deploymentRehearsal->prepare($rehearsalId);
        $this->components->info("Deployment rehearsal {$rehearsalId} is prepared.");

        return self::SUCCESS;
    }
}
