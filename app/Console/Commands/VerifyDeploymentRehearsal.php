<?php

namespace App\Console\Commands;

use App\Operations\DeploymentRehearsal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('app:deployment-rehearsal:verify {rehearsal : The UUID returned when the rehearsal was prepared}')]
#[Description('Verify that queued and scheduled restart probes each completed exactly once')]
class VerifyDeploymentRehearsal extends Command
{
    public function handle(DeploymentRehearsal $deploymentRehearsal): int
    {
        $rehearsalId = (string) $this->argument('rehearsal');

        if (! Str::isUuid($rehearsalId)) {
            $this->components->error('The rehearsal identifier must be a UUID.');

            return self::INVALID;
        }

        if (! $deploymentRehearsal->isComplete($rehearsalId)) {
            $this->components->error('The queued and scheduled probes have not each completed exactly once.');

            return self::FAILURE;
        }

        $this->components->info('The queued and scheduled probes each completed exactly once.');

        return self::SUCCESS;
    }
}
