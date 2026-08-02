<?php

use App\Models\GmailConnection;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Process\Process;

function backupRecoveryTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir().'/money-assistant-backup-recovery-'.str()->uuid();
    mkdir($directory, 0700, true);

    return $directory;
}

/**
 * @param  array<string, string>  $environment
 */
function runBackupRecoveryScript(string $script, array $environment): Process
{
    $process = new Process(
        [base_path($script)],
        base_path(),
        array_merge(['PATH' => getenv('PATH')], $environment),
    );
    $process->setTimeout(30);
    $process->run();

    return $process;
}

function installFakeBackupBinary(string $directory, string $name, string $contents): void
{
    file_put_contents($directory.'/'.$name, "#!/bin/sh\nset -eu\n".$contents);
    chmod($directory.'/'.$name, 0700);
}

test('the application host exports a consistent independently encrypted recovery bundle', function () {
    $temporaryDirectory = backupRecoveryTemporaryDirectory();
    $hostRoot = $temporaryDirectory.'/host';
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $commandLog = $temporaryDirectory.'/commands.log';
    $archive = $temporaryDirectory.'/backup.tar';
    $extracted = $temporaryDirectory.'/extracted';

    mkdir($fakeBinaryDirectory, 0700, true);
    mkdir($hostRoot.'/etc/money-assistant/secrets', 0700, true);
    mkdir($hostRoot.'/etc/systemd/system', 0700, true);
    mkdir($hostRoot.'/home/openclaw/.openclaw', 0700, true);
    mkdir($hostRoot.'/opt/money-assistant', 0700, true);
    mkdir($hostRoot.'/var/lib/money-assistant/deployments', 0700, true);
    mkdir($hostRoot.'/var/lib/money-assistant/monitor', 0700, true);
    mkdir($extracted, 0700, true);
    file_put_contents($hostRoot.'/etc/money-assistant/production.env', implode("\n", [
        'APP_IMAGE_REPOSITORY=registry.example/money-assistant',
        'APP_IMAGE_DIGEST=sha256:'.str_repeat('a', 64),
        'POSTGRES_IMAGE=postgres@example.invalid@sha256:'.str_repeat('b', 64),
        'DB_DATABASE=money_assistant',
        'DB_USERNAME=money_assistant',
        'OPENCLAW_HOME=/home/openclaw',
        '',
    ]));
    file_put_contents($hostRoot.'/etc/os-release', "NAME=Test Linux\nVERSION_ID=1\n");
    file_put_contents($hostRoot.'/etc/money-assistant/secrets/application_key', 'sensitive-application-key');
    file_put_contents($hostRoot.'/etc/money-assistant/secrets/database_password', 'sensitive-database-password');
    file_put_contents($hostRoot.'/etc/money-assistant/backup-recipient.txt', 'age1testrecipient');
    file_put_contents($hostRoot.'/home/openclaw/.openclaw/openai-oauth.json', 'encrypted-openclaw-state');
    file_put_contents($hostRoot.'/opt/money-assistant/compose.production.yaml', "services: {}\n");
    file_put_contents($hostRoot.'/etc/systemd/system/money-assistant-production.service', "[Service]\n");
    file_put_contents($hostRoot.'/var/lib/money-assistant/deployments/current.env', "APP_IMAGE_DIGEST=sha256:test\n");

    installFakeBackupBinary($fakeBinaryDirectory, 'docker', <<<'SH'
printf 'docker %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
case "$*" in
    *'app:recovery:inventory'*) printf '%s\n' '{"format_version":1,"record_counts":{"users":1},"queue":{"pending":0,"reserved":0,"failed":0},"integrations":{"gmail_connections":0}}' ;;
    *'pg_dump'*)
        [ "${BACKUP_TEST_DUMP_FAILURE:-false}" = false ] || exit 1
        printf '%s' 'consistent-postgresql-dump'
        ;;
    *'run --rm'*) printf '%s' 'private-application-files' ;;
    *'compose version'*) printf '%s\n' 'Docker Compose version v2.test' ;;
    *'version'*) printf '%s\n' 'Docker version test' ;;
esac
SH);
    installFakeBackupBinary($fakeBinaryDirectory, 'age', <<<'SH'
printf 'age %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
if [ "${1:-}" = --version ]; then
    printf '%s\n' 'age test-version'
    exit 0
fi
for candidate in "$@"; do
    archive="$candidate"
done
exec /bin/cat "$archive"
SH);
    installFakeBackupBinary($fakeBinaryDirectory, 'id', <<<'SH'
if [ "${1:-}" = -u ]; then
    if [ "$#" -eq 1 ]; then
        printf '%s\n' 0
    else
        printf '%s\n' 1001
    fi
    exit 0
fi
exec /usr/bin/id "$@"
SH);
    installFakeBackupBinary($fakeBinaryDirectory, 'openclaw', <<<'SH'
printf '%s\n' 'OpenClaw 2026.7.1'
SH);
    installFakeBackupBinary($fakeBinaryDirectory, 'runuser', <<<'SH'
printf 'runuser %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
while [ "$#" -gt 0 ] && [ "$1" != -- ]; do
    shift
done
shift
exec "$@"
SH);
    installFakeBackupBinary($fakeBinaryDirectory, 'systemctl', <<<'SH'
printf 'systemctl %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
SH);
    installFakeBackupBinary($fakeBinaryDirectory, 'tailscale', <<<'SH'
printf '%s\n' '1.98.0'
SH);

    try {
        $process = runBackupRecoveryScript('export-production-backup', [
            'BACKUP_TEST_COMMAND_LOG' => $commandLog,
            'BACKUP_WORK_ROOT' => $temporaryDirectory,
            'ENVIRONMENT_FILE' => $hostRoot.'/etc/money-assistant/production.env',
            'HOST_ROOT' => $hostRoot,
            'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
        ]);
        file_put_contents($archive, $process->getOutput());
        $extract = new Process(['tar', '-xf', $archive, '-C', $extracted]);
        $extract->run();

        expect($process->getExitCode())->toBe(0)
            ->and($extract->getExitCode())->toBe(0)
            ->and(glob($extracted.'/*'))->not->toBeEmpty()
            ->and(file_get_contents($extracted.'/database.dump'))->toBe('consistent-postgresql-dump')
            ->and(file_get_contents($extracted.'/application-storage.tar'))->toBe('private-application-files')
            ->and(file_get_contents($extracted.'/application-inventory.json'))
            ->toContain('"users":1')
            ->and(file_get_contents($extracted.'/manifest.env'))
            ->toContain('BACKUP_FORMAT_VERSION=1')
            ->toContain('APP_IMAGE=registry.example/money-assistant@sha256:'.str_repeat('a', 64))
            ->and(file_get_contents($extracted.'/SHA256SUMS'))
            ->toContain('database.dump', 'application-storage.tar', 'host-configuration.tar')
            ->and(file_get_contents($commandLog))
            ->toContain('artisan down')
            ->toContain('systemctl --user stop openclaw-gateway.service')
            ->toContain('stop worker scheduler')
            ->toContain('stop web')
            ->toContain('pg_dump')
            ->toContain('artisan up')
            ->toContain('systemctl --user start openclaw-gateway.service')
            ->toContain('age --encrypt --recipient-file')
            ->not->toContain('sensitive-application-key', 'sensitive-database-password')
            ->and($process->getErrorOutput())
            ->not->toContain('sensitive-application-key', 'sensitive-database-password')
            ->and(file_get_contents($hostRoot.'/var/lib/money-assistant/monitor/backup-status'))
            ->toStartWith('success ');

        expect(file_get_contents($extracted.'/version-inventory.txt'))
            ->toContain('OpenClaw 2026.7.1')
            ->toContain('age test-version')
            ->toContain('1.98.0');

        $hostArchive = new Process(['tar', '-tf', $extracted.'/host-configuration.tar']);
        $hostArchive->run();

        expect($hostArchive->getOutput())
            ->toContain('etc/money-assistant/production.env')
            ->toContain('etc/money-assistant/secrets/application_key')
            ->toContain('opt/money-assistant/compose.production.yaml')
            ->toContain('etc/systemd/system/money-assistant-production.service')
            ->toContain('home/openclaw/.openclaw/openai-oauth.json')
            ->toContain('var/lib/money-assistant/deployments/current.env');

        file_put_contents($commandLog, '');
        $failedProcess = runBackupRecoveryScript('export-production-backup', [
            'BACKUP_TEST_COMMAND_LOG' => $commandLog,
            'BACKUP_TEST_DUMP_FAILURE' => 'true',
            'BACKUP_WORK_ROOT' => $temporaryDirectory,
            'ENVIRONMENT_FILE' => $hostRoot.'/etc/money-assistant/production.env',
            'HOST_ROOT' => $hostRoot,
            'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
        ]);

        expect($failedProcess->getExitCode())->toBe(1)
            ->and(file_get_contents($commandLog))
            ->toContain('start web worker scheduler')
            ->toContain('artisan up')
            ->toContain('systemctl --user start openclaw-gateway.service')
            ->and(file_get_contents($hostRoot.'/var/lib/money-assistant/monitor/backup-status'))
            ->toStartWith('failed ');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('the second device pulls without repository authority on the application host and applies retention', function () {
    $temporaryDirectory = backupRecoveryTemporaryDirectory();
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $commandLog = $temporaryDirectory.'/commands.log';
    $capturedBackup = $temporaryDirectory.'/captured.age';
    mkdir($fakeBinaryDirectory, 0700, true);
    file_put_contents($temporaryDirectory.'/repository-password', 'repository-secret');
    file_put_contents($temporaryDirectory.'/known-hosts', 'host ssh-ed25519 public-key');
    file_put_contents($temporaryDirectory.'/pull-key', 'private-key');

    installFakeBackupBinary($fakeBinaryDirectory, 'ssh', <<<'SH'
printf 'ssh %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
printf '%s' 'independently-encrypted-backup'
SH);
    installFakeBackupBinary($fakeBinaryDirectory, 'restic', <<<'SH'
printf 'restic %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
if [ "${BACKUP_TEST_RESTIC_FAILURE:-false}" = true ] && [ "$1" = check ]; then
    exit 1
fi
case "$1" in
    backup) /bin/cat > "$BACKUP_TEST_CAPTURED_BACKUP" ;;
esac
SH);

    try {
        $process = runBackupRecoveryScript('pull-production-backup', [
            'APPLICATION_BACKUP_HOST' => 'money-assistant-backup@100.64.0.10',
            'APPLICATION_BACKUP_KNOWN_HOSTS_FILE' => $temporaryDirectory.'/known-hosts',
            'APPLICATION_BACKUP_SSH_KEY_FILE' => $temporaryDirectory.'/pull-key',
            'BACKUP_LOCK_FILE' => $temporaryDirectory.'/backup.lock',
            'BACKUP_STATUS_FILE' => $temporaryDirectory.'/backup-status',
            'BACKUP_TEST_CAPTURED_BACKUP' => $capturedBackup,
            'BACKUP_TEST_COMMAND_LOG' => $commandLog,
            'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
            'RESTIC_PASSWORD_FILE' => $temporaryDirectory.'/repository-password',
            'RESTIC_REPOSITORY' => $temporaryDirectory.'/repository',
        ]);

        expect($process->getExitCode())->toBe(0)
            ->and(file_get_contents($capturedBackup))->toBe('independently-encrypted-backup')
            ->and(file_get_contents($commandLog))
            ->toContain('ssh -o BatchMode=yes')
            ->toContain('ClearAllForwardings=yes')
            ->toContain('StrictHostKeyChecking=yes')
            ->toContain('restic backup --stdin --stdin-filename money-assistant-backup.tar.age')
            ->toContain('restic forget --tag money-assistant --keep-daily 7 --keep-weekly 5 --keep-monthly 12 --prune')
            ->toContain('restic check')
            ->not->toContain('repository-secret')
            ->and(file_get_contents($temporaryDirectory.'/backup-status'))
            ->toStartWith('success ');

        $failedProcess = runBackupRecoveryScript('pull-production-backup', [
            'APPLICATION_BACKUP_HOST' => 'money-assistant-backup@100.64.0.10',
            'APPLICATION_BACKUP_KNOWN_HOSTS_FILE' => $temporaryDirectory.'/known-hosts',
            'APPLICATION_BACKUP_SSH_KEY_FILE' => $temporaryDirectory.'/pull-key',
            'BACKUP_LOCK_FILE' => $temporaryDirectory.'/backup.lock',
            'BACKUP_STATUS_FILE' => $temporaryDirectory.'/backup-status',
            'BACKUP_TEST_CAPTURED_BACKUP' => $capturedBackup,
            'BACKUP_TEST_COMMAND_LOG' => $commandLog,
            'BACKUP_TEST_RESTIC_FAILURE' => 'true',
            'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
            'RESTIC_PASSWORD_FILE' => $temporaryDirectory.'/repository-password',
            'RESTIC_REPOSITORY' => $temporaryDirectory.'/repository',
        ]);

        expect($failedProcess->getExitCode())->toBe(1)
            ->and(file_get_contents($temporaryDirectory.'/backup-status'))
            ->toStartWith('failed ');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('restore uses an isolated temporary environment and verifies the complete recovery bundle', function () {
    $temporaryDirectory = backupRecoveryTemporaryDirectory();
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $bundleDirectory = $temporaryDirectory.'/bundle';
    $hostFiles = $temporaryDirectory.'/host-files';
    $storageFiles = $temporaryDirectory.'/storage-files';
    $archive = $temporaryDirectory.'/backup.tar';
    $commandLog = $temporaryDirectory.'/commands.log';
    mkdir($fakeBinaryDirectory, 0700, true);
    mkdir($bundleDirectory, 0700, true);
    mkdir($hostFiles.'/etc/money-assistant/secrets', 0700, true);
    mkdir($hostFiles.'/home/openclaw/.config/systemd/user/openclaw-gateway.service.d', 0700, true);
    mkdir($hostFiles.'/home/openclaw/.openclaw', 0700, true);
    mkdir($hostFiles.'/opt/money-assistant', 0700, true);
    mkdir($storageFiles, 0700, true);
    file_put_contents($hostFiles.'/etc/money-assistant/secrets/application_key', 'base64:restored-application-key');
    file_put_contents($hostFiles.'/etc/money-assistant/secrets/application_previous_keys', '');
    file_put_contents($hostFiles.'/etc/money-assistant/secrets/database_password', 'restored-database-password');
    file_put_contents($hostFiles.'/etc/money-assistant/secrets/google_gmail_client_secret', 'restored-gmail-client-secret');
    file_put_contents($hostFiles.'/etc/money-assistant/secrets/openclaw_capability_private_key', 'restored-openclaw-private-key');
    file_put_contents($hostFiles.'/etc/money-assistant/secrets/openclaw_capability_public_key', 'restored-openclaw-public-key');
    file_put_contents($hostFiles.'/etc/money-assistant/secrets/openclaw_hook_token', 'restored-openclaw-hook-token');
    file_put_contents($hostFiles.'/etc/money-assistant/openclaw.env', "OPENCLAW_MONEY_ASSISTANT_KEY_ID=recovered\n");
    file_put_contents($hostFiles.'/etc/money-assistant/production.env', "DB_DATABASE=money_assistant\nDB_USERNAME=money_assistant\nOPENCLAW_HOME=/home/openclaw\n");
    file_put_contents($hostFiles.'/home/openclaw/.config/systemd/user/openclaw-gateway.service.d/money-assistant.conf', "[Service]\n");
    file_put_contents($hostFiles.'/home/openclaw/.openclaw/openai-oauth.json', 'restored-openclaw-runtime-state');
    file_put_contents($hostFiles.'/opt/money-assistant/compose.production.yaml', "services: {}\n");
    file_put_contents($storageFiles.'/private-file', 'restored-private-file');
    file_put_contents($bundleDirectory.'/database.dump', 'restored-postgresql-dump');
    file_put_contents($bundleDirectory.'/application-inventory.json', '{"format_version":1,"record_counts":{"users":1},"queue":{"pending":0,"reserved":0,"failed":0},"integrations":{"gmail_connections":0}}');
    file_put_contents($bundleDirectory.'/manifest.env', implode("\n", [
        'BACKUP_FORMAT_VERSION=1',
        'CREATED_AT=2026-08-01T00:00:00Z',
        'APP_IMAGE=registry.example/money-assistant@sha256:'.str_repeat('a', 64),
        'POSTGRES_IMAGE=postgres@example.invalid@sha256:'.str_repeat('b', 64),
        'OPENCLAW_HOME=/home/openclaw',
        '',
    ]));
    file_put_contents($bundleDirectory.'/version-inventory.txt', "application_image=test\n");
    foreach ([
        [$hostFiles, $bundleDirectory.'/host-configuration.tar'],
        [$storageFiles, $bundleDirectory.'/application-storage.tar'],
    ] as [$source, $target]) {
        $tar = new Process(['tar', '-cf', $target, '-C', $source, '.']);
        $tar->mustRun();
    }
    $checksumLines = [];
    foreach (glob($bundleDirectory.'/*') as $file) {
        $checksumLines[] = hash_file('sha256', $file).'  '.basename($file);
    }
    file_put_contents($bundleDirectory.'/SHA256SUMS', implode("\n", $checksumLines)."\n");
    (new Process(['tar', '-cf', $archive, '-C', $bundleDirectory, '.']))->mustRun();
    file_put_contents($temporaryDirectory.'/owner-password', 'correct horse battery staple');
    file_put_contents($temporaryDirectory.'/age-identity', 'AGE-SECRET-KEY-test');
    file_put_contents($temporaryDirectory.'/repository-password', 'repository-secret');

    installFakeBackupBinary($fakeBinaryDirectory, 'restic', <<<'SH'
printf 'restic %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
/bin/cat "$BACKUP_TEST_ARCHIVE"
SH);
    installFakeBackupBinary($fakeBinaryDirectory, 'age', <<<'SH'
printf 'age %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
exec /bin/cat
SH);
    installFakeBackupBinary($fakeBinaryDirectory, 'docker', <<<'SH'
printf 'docker %s\n' "$*" >> "$BACKUP_TEST_COMMAND_LOG"
case "$*" in
    *'container inspect'*|*'network inspect'*|*'volume inspect'*) exit 1 ;;
    *'config --services'*) printf '%s\n' postgres migrate web worker scheduler proxy ;;
    *'pg_isready'*) exit 0 ;;
    *'http://127.0.0.1:8080/up'*) exit 0 ;;
    *'app:recovery:verify'*) printf '%s\n' 'Recovered application verification passed.' ;;
esac
SH);

    try {
        $process = runBackupRecoveryScript('restore-production-backup', [
            'BACKUP_AGE_IDENTITY_FILE' => $temporaryDirectory.'/age-identity',
            'BACKUP_TEST_ARCHIVE' => $archive,
            'BACKUP_TEST_COMMAND_LOG' => $commandLog,
            'KEEP_RECOVERY' => 'true',
            'OWNER_PASSWORD_FILE' => $temporaryDirectory.'/owner-password',
            'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
            'RECOVERY_NAME' => 'money-assistant-recovery-test',
            'RECOVERY_WORK_ROOT' => $temporaryDirectory,
            'RESTIC_PASSWORD_FILE' => $temporaryDirectory.'/repository-password',
            'RESTIC_REPOSITORY' => $temporaryDirectory.'/repository',
            'RESTORE_STATUS_FILE' => $temporaryDirectory.'/restore-status',
        ]);
        $commands = file_get_contents($commandLog);

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toContain('Isolated recovery verification completed')
            ->and($process->getErrorOutput())->toContain('Retained isolated recovery environment')
            ->and($commands)
            ->toContain('restic dump latest money-assistant-backup.tar.age')
            ->toContain('age --decrypt --identity')
            ->toContain('config --services')
            ->toContain('network create --internal')
            ->toContain('pg_restore')
            ->toContain('frankenphp run --config /etc/frankenphp/Caddyfile')
            ->toContain('http://127.0.0.1:8080/up')
            ->toContain('app:recovery:verify /recovery/application-inventory.json')
            ->not->toContain('queue:work', 'schedule:work', '--publish', 'rm --force', 'restored-database-password', 'correct horse battery staple')
            ->and(file_get_contents($temporaryDirectory.'/restore-status'))
            ->toStartWith('success ');

        file_put_contents($commandLog, '');
        $refusedProcess = runBackupRecoveryScript('restore-production-backup', [
            'BACKUP_AGE_IDENTITY_FILE' => $temporaryDirectory.'/age-identity',
            'BACKUP_TEST_ARCHIVE' => $archive,
            'BACKUP_TEST_COMMAND_LOG' => $commandLog,
            'OWNER_PASSWORD_FILE' => $temporaryDirectory.'/owner-password',
            'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
            'RECOVERY_NAME' => 'money-assistant-production',
            'RECOVERY_WORK_ROOT' => $temporaryDirectory,
            'RESTIC_PASSWORD_FILE' => $temporaryDirectory.'/repository-password',
            'RESTIC_REPOSITORY' => $temporaryDirectory.'/repository',
            'RESTORE_STATUS_FILE' => $temporaryDirectory.'/restore-status',
        ]);

        expect($refusedProcess->getExitCode())->toBe(1)
            ->and($refusedProcess->getErrorOutput())->toContain('must not target the live production project')
            ->and(file_get_contents($commandLog))->toBe('')
            ->and(file_get_contents($temporaryDirectory.'/restore-status'))
            ->toStartWith('failed ');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('backup installation keeps repository and decryption authority on the second device', function () {
    $exportInstaller = file_get_contents(base_path('install-production-backup-export'));
    $pullInstaller = file_get_contents(base_path('install-backup-pull-services'));
    $service = file_get_contents(base_path('money-assistant-backup-pull.service'));
    $timer = file_get_contents(base_path('money-assistant-backup-pull.timer'));
    $backupPullEnvironmentExample = file_get_contents(base_path('backup-pull.env.example'));
    $tailnetPolicy = file_get_contents(base_path('tailscale-policy.hujson'));

    expect($exportInstaller)
        ->toContain('restrict,command="sudo -n /usr/local/sbin/export-production-backup"')
        ->toContain('/etc/sudoers.d/money-assistant-backup')
        ->not->toContain('RESTIC_PASSWORD', 'AGE-SECRET-KEY')
        ->and($pullInstaller)
        ->toContain('restic init')
        ->toContain('restore-production-backup')
        ->toContain('systemctl enable --now money-assistant-backup-pull.timer')
        ->and($service)
        ->toContain('EnvironmentFile=/etc/money-assistant/backup-pull.env')
        ->toContain('PrivateTmp=true')
        ->toContain('Restart=on-failure')
        ->toContain('RestartSec=15m')
        ->and($timer)
        ->toContain('OnCalendar=*-*-* 03:30:00')
        ->toContain('Persistent=true')
        ->and($backupPullEnvironmentExample)
        ->toContain('RESTIC_REPOSITORY=')
        ->toContain('RESTIC_PASSWORD_FILE=')
        ->toContain('BACKUP_AGE_IDENTITY_FILE=')
        ->toContain('APPLICATION_BACKUP_SSH_KEY_FILE=')
        ->and($tailnetPolicy)
        ->toContain('"tag:money-assistant-backup-device"')
        ->toContain('"dst": ["tag:money-assistant:22"]')
        ->toContain('"accept": ["tag:money-assistant:22"]');
});

test('recovered application verification checks counts decryption authentication queues and integrations without contact', function () {
    Http::fake();
    Mail::fake();
    Notification::fake();
    $owner = User::factory()->create([
        'password' => Hash::make('correct horse battery staple'),
    ]);
    GmailConnection::factory()->for($owner, 'owner')->create([
        'access_token' => 'encrypted-access-token',
        'refresh_token' => 'encrypted-refresh-token',
    ]);
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    expect(Artisan::call('app:recovery:inventory'))->toBe(0);
    $inventory = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $temporaryDirectory = backupRecoveryTemporaryDirectory();
    $inventoryFile = $temporaryDirectory.'/inventory.json';
    $passwordFile = $temporaryDirectory.'/owner-password';
    file_put_contents($inventoryFile, json_encode($inventory, JSON_THROW_ON_ERROR));
    file_put_contents($passwordFile, 'correct horse battery staple');

    try {
        expect($inventory['record_counts']['users'])->toBe(1)
            ->and($inventory['queue'])->toBe([
                'pending' => 1,
                'reserved' => 0,
                'failed' => 0,
            ])
            ->and($inventory['integrations']['gmail_connections'])->toBe(1)
            ->and(Artisan::call('app:recovery:verify', [
                'inventory' => $inventoryFile,
                '--owner-password-file' => $passwordFile,
            ]))->toBe(0)
            ->and(Artisan::output())
            ->toContain('Recovered application verification passed.')
            ->not->toContain('encrypted-access-token', 'encrypted-refresh-token', 'correct horse battery staple');

        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('recovered application verification rejects record drift and invalid Owner Account credentials', function () {
    $owner = User::factory()->create([
        'password' => Hash::make('correct horse battery staple'),
    ]);
    GmailConnection::factory()->for($owner, 'owner')->create();

    Artisan::call('app:recovery:inventory');
    $temporaryDirectory = backupRecoveryTemporaryDirectory();
    $inventoryFile = $temporaryDirectory.'/inventory.json';
    $passwordFile = $temporaryDirectory.'/owner-password';
    file_put_contents($inventoryFile, Artisan::output());
    file_put_contents($passwordFile, 'incorrect password');

    try {
        expect(Artisan::call('app:recovery:verify', [
            'inventory' => $inventoryFile,
            '--owner-password-file' => $passwordFile,
        ]))->toBe(1)
            ->and(Artisan::output())->toContain('Owner Account authentication failed.');

        file_put_contents($passwordFile, 'correct horse battery staple');
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        expect(Artisan::call('app:recovery:verify', [
            'inventory' => $inventoryFile,
            '--owner-password-file' => $passwordFile,
        ]))->toBe(1)
            ->and(Artisan::output())->toContain('Restored record counts do not match the backup inventory.');

        DB::table('jobs')->delete();
        DB::table((new GmailConnection)->getTable())->update([
            'access_token' => 'not-valid-encrypted-content',
        ]);

        expect(Artisan::call('app:recovery:verify', [
            'inventory' => $inventoryFile,
            '--owner-password-file' => $passwordFile,
        ]))->toBe(1)
            ->and(Artisan::output())->toContain('Restored integration credentials could not be decrypted.');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});
