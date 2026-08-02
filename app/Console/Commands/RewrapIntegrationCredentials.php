<?php

namespace App\Console\Commands;

use App\Operations\CredentialRotation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:credentials:rewrap')]
#[Description('Re-encrypt retained integration credentials with the current application key')]
class RewrapIntegrationCredentials extends Command
{
    public function handle(CredentialRotation $credentialRotation): int
    {
        $rewrappedConnections = $credentialRotation->rewrapIntegrationCredentials();

        $this->components->info("Re-encrypted {$rewrappedConnections} Gmail connection credential set(s).");

        return self::SUCCESS;
    }
}
