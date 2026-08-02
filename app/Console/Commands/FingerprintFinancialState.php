<?php

namespace App\Console\Commands;

use App\Operations\CredentialRotation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:financial-state:fingerprint')]
#[Description('Print a credential-free digest of the Owner Account financial state')]
class FingerprintFinancialState extends Command
{
    public function handle(CredentialRotation $credentialRotation): int
    {
        $this->line($credentialRotation->financialStateFingerprint());

        return self::SUCCESS;
    }
}
