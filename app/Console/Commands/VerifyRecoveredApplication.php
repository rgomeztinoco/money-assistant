<?php

namespace App\Console\Commands;

use App\Operations\RecoveryVerification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;

#[Signature('app:recovery:verify
    {inventory : Path to the application inventory captured with the backup}
    {--owner-password-file= : Path to a file containing the Owner Account recovery password}')]
#[Description('Verify restored records, credentials, authentication, queues, and integrations')]
class VerifyRecoveredApplication extends Command
{
    public function handle(RecoveryVerification $recoveryVerification): int
    {
        $inventoryPath = (string) $this->argument('inventory');
        $ownerPasswordPath = (string) $this->option('owner-password-file');

        if (! is_readable($inventoryPath)) {
            $this->components->error('The backup application inventory is not readable.');

            return self::FAILURE;
        }

        if ($ownerPasswordPath === '' || ! is_readable($ownerPasswordPath)) {
            $this->components->error('The Owner Account password file is not readable.');

            return self::FAILURE;
        }

        try {
            $inventory = json_decode(
                (string) file_get_contents($inventoryPath),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            $this->components->error('The backup application inventory is not valid JSON.');

            return self::FAILURE;
        }

        if (! is_array($inventory)) {
            $this->components->error('The backup application inventory has an invalid shape.');

            return self::FAILURE;
        }

        $ownerPassword = Str::of((string) file_get_contents($ownerPasswordPath))
            ->rtrim("\r\n")
            ->toString();
        $failures = $recoveryVerification->verify($inventory, $ownerPassword);

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->components->error($failure);
            }

            return self::FAILURE;
        }

        $this->components->info('Recovered application verification passed.');

        return self::SUCCESS;
    }
}
