<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function backupTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir().'/money-assistant-backup-'.str()->uuid();
    mkdir($directory, 0700, true);

    return $directory;
}

function installBackupCommand(string $directory, string $name, string $contents): void
{
    file_put_contents($directory.'/'.$name, "#!/bin/sh\nset -eu\n".$contents);
    chmod($directory.'/'.$name, 0700);
}

/** @param array<string, string> $environment */
function runBackupCommand(string $script, array $arguments, array $environment): Process
{
    $process = new Process(
        [base_path($script), ...$arguments],
        base_path(),
        ['PATH' => getenv('PATH'), ...$environment],
    );
    $process->run();

    return $process;
}

test('the scheduled backup streams a PostgreSQL custom dump into Age encryption', function (): void {
    $temporaryDirectory = backupTemporaryDirectory();
    $binaryDirectory = $temporaryDirectory.'/bin';
    $backupDirectory = $temporaryDirectory.'/backups';
    $commandLog = $temporaryDirectory.'/commands.log';
    $environmentFile = $temporaryDirectory.'/production.env';
    $recipientFile = $temporaryDirectory.'/recipient.txt';
    $applicationDirectory = $temporaryDirectory.'/application';
    mkdir($binaryDirectory, 0700, true);
    file_put_contents($environmentFile, "DB_DATABASE=money_assistant\nDB_USERNAME=money_assistant\n");
    file_put_contents($recipientFile, "age1recipient\n");
    installBackupCommand($binaryDirectory, 'docker', <<<'SH'
printf 'docker %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
[ "${BACKUP_TEST_DUMP_FAILURE:-false}" = false ] || exit 23
printf '%s' 'postgresql-custom-dump'
SH);
    installBackupCommand($binaryDirectory, 'age', <<<'SH'
printf 'age %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
[ "${1:-}" = --encrypt ] || exit 2
[ "${2:-}" = --recipients-file ] || {
    printf '%s\n' 'Age requires --recipients-file for a recipient file.' >&2
    exit 2
}
while [ "$#" -gt 0 ]; do
    if [ "$1" = --output ]; then
        output="$2"
        shift 2
        continue
    fi
    shift
done
/bin/cat > "$output"
SH);

    try {
        $backup = runBackupCommand('export-production-backup', [], [
            'BACKUP_AGE_RECIPIENT_FILE' => $recipientFile,
            'BACKUP_DIRECTORY' => $backupDirectory,
            'BACKUP_LOCK_FILE' => $temporaryDirectory.'/backup.lock',
            'BACKUP_TEST_COMMAND_LOG' => $commandLog,
            'ENVIRONMENT_FILE' => $environmentFile,
            'MONEY_ASSISTANT_APPLICATION_DIRECTORY' => $applicationDirectory,
            'PATH' => $binaryDirectory.':'.getenv('PATH'),
        ]);
        $backups = glob($backupDirectory.'/*.dump.age');

        expect($backup->getExitCode())->toBe(0, $backup->getErrorOutput())
            ->and($backups)->toHaveCount(1)
            ->and(file_get_contents($backups[0]))->toBe('postgresql-custom-dump')
            ->and(substr(sprintf('%o', fileperms($backups[0])), -4))->toBe('0600')
            ->and(file_get_contents($commandLog))
            ->toContain('--file '.$applicationDirectory.'/compose.production.yaml')
            ->toContain('pg_dump --username money_assistant --dbname money_assistant --format=custom --no-owner --no-privileges')
            ->toContain('age --encrypt --recipients-file '.$recipientFile)
            ->not->toContain('application_key', 'database_password');

        $failedBackup = runBackupCommand('export-production-backup', [], [
            'BACKUP_AGE_RECIPIENT_FILE' => $recipientFile,
            'BACKUP_DIRECTORY' => $backupDirectory,
            'BACKUP_LOCK_FILE' => $temporaryDirectory.'/backup.lock',
            'BACKUP_TEST_COMMAND_LOG' => $commandLog,
            'BACKUP_TEST_DUMP_FAILURE' => 'true',
            'ENVIRONMENT_FILE' => $environmentFile,
            'MONEY_ASSISTANT_APPLICATION_DIRECTORY' => $applicationDirectory,
            'PATH' => $binaryDirectory.':'.getenv('PATH'),
        ]);

        expect($failedBackup->getExitCode())->toBe(23)
            ->and(glob($backupDirectory.'/*.tmp.*'))->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('restore decrypts into a non-production database and verifies migrations', function (): void {
    $temporaryDirectory = backupTemporaryDirectory();
    $binaryDirectory = $temporaryDirectory.'/bin';
    $commandLog = $temporaryDirectory.'/commands.log';
    $environmentFile = $temporaryDirectory.'/production.env';
    $identityFile = $temporaryDirectory.'/identity.txt';
    $backupFile = $temporaryDirectory.'/backup.dump.age';
    $applicationDirectory = $temporaryDirectory.'/application';
    mkdir($binaryDirectory, 0700, true);
    file_put_contents($environmentFile, "DB_DATABASE=money_assistant\nDB_USERNAME=money_assistant\n");
    file_put_contents($identityFile, "AGE-SECRET-KEY-test\n");
    file_put_contents($backupFile, 'encrypted-backup');
    installBackupCommand($binaryDirectory, 'age', <<<'SH'
printf 'age %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
printf '%s' 'decrypted-postgresql-dump'
SH);
    installBackupCommand($binaryDirectory, 'docker', <<<'SH'
printf 'docker %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
case "$*" in
    *"SELECT 1 FROM pg_database"*) exit 0 ;;
    *"SELECT count(*) FROM migrations"*) printf '%s\n' 6 ;;
    *pg_restore*) /bin/cat >> "$BACKUP_TEST_RESTORE_INPUT" ;;
esac
SH);

    try {
        $restore = runBackupCommand('restore-production-backup', [
            $backupFile,
            'money_assistant_restore_20260813',
        ], [
            'BACKUP_AGE_IDENTITY_FILE' => $identityFile,
            'BACKUP_TEST_COMMAND_LOG' => $commandLog,
            'BACKUP_TEST_RESTORE_INPUT' => $temporaryDirectory.'/restored.dump',
            'ENVIRONMENT_FILE' => $environmentFile,
            'MONEY_ASSISTANT_APPLICATION_DIRECTORY' => $applicationDirectory,
            'PATH' => $binaryDirectory.':'.getenv('PATH'),
        ]);

        expect($restore->getExitCode())->toBe(0, $restore->getErrorOutput())
            ->and($restore->getOutput())->toContain('Encrypted database restore verified')
            ->and(file_get_contents($temporaryDirectory.'/restored.dump'))->toBe('decrypted-postgresql-dump')
            ->and(file_get_contents($commandLog))
            ->toContain('--file '.$applicationDirectory.'/compose.production.yaml')
            ->toContain('createdb --username money_assistant money_assistant_restore_20260813')
            ->toContain('pg_restore --username money_assistant --dbname money_assistant_restore_20260813 --exit-on-error --no-owner --no-privileges')
            ->toContain('SELECT count(*) FROM migrations')
            ->not->toContain('dropdb');

        $productionRestore = runBackupCommand('restore-production-backup', [
            $backupFile,
            'money_assistant',
        ], [
            'BACKUP_AGE_IDENTITY_FILE' => $identityFile,
            'BACKUP_TEST_COMMAND_LOG' => $commandLog,
            'ENVIRONMENT_FILE' => $environmentFile,
            'MONEY_ASSISTANT_APPLICATION_DIRECTORY' => $applicationDirectory,
            'PATH' => $binaryDirectory.':'.getenv('PATH'),
        ]);

        expect($productionRestore->getExitCode())->toBe(1)
            ->and($productionRestore->getErrorOutput())->toContain('restore database must match');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('the backup installer enables one hardened daily timer', function (): void {
    $installer = file_get_contents(base_path('install-production-backup'));
    $service = file_get_contents(base_path('money-assistant-backup.service'));
    $timer = file_get_contents(base_path('money-assistant-backup.timer'));

    expect($installer)
        ->toContain('install -m 0755')
        ->toContain('export-production-backup')
        ->toContain('restore-production-backup')
        ->toContain('systemctl enable --now money-assistant-backup.timer')
        ->and($service)
        ->toContain('PrivateTmp=true')
        ->toContain('NoNewPrivileges=true')
        ->and($timer)
        ->toContain('OnCalendar=*-*-* 03:30:00')
        ->toContain('Persistent=true');
});
