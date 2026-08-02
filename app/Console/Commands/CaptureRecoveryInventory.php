<?php

namespace App\Console\Commands;

use App\Operations\RecoveryVerification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:recovery:inventory')]
#[Description('Print a credential-free inventory for backup recovery verification')]
class CaptureRecoveryInventory extends Command
{
    public function handle(RecoveryVerification $recoveryVerification): int
    {
        $this->line(json_encode(
            $recoveryVerification->inventory(),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
