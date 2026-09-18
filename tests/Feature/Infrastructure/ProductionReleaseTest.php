<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function runReleaseSetupCommand(array $command): string
{
    $process = new Process($command, base_path());
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException($process->getErrorOutput());
    }

    return trim($process->getOutput());
}

/** @return array{source: string, revision: string} */
function createReleaseRepository(string $temporaryDirectory): array
{
    $sourceDirectory = $temporaryDirectory.'/source';
    $remoteDirectory = $temporaryDirectory.'/origin.git';

    mkdir($sourceDirectory, 0700, true);
    file_put_contents($sourceDirectory.'/tracked.txt', "release contents\n");

    runReleaseSetupCommand(['git', 'init', '--initial-branch=main', $sourceDirectory]);
    runReleaseSetupCommand(['git', '-C', $sourceDirectory, 'config', 'user.email', 'release@example.test']);
    runReleaseSetupCommand(['git', '-C', $sourceDirectory, 'config', 'user.name', 'Release Test']);
    runReleaseSetupCommand(['git', '-C', $sourceDirectory, 'add', 'tracked.txt']);
    runReleaseSetupCommand(['git', '-C', $sourceDirectory, 'commit', '-m', 'Release fixture']);
    runReleaseSetupCommand(['git', 'init', '--bare', $remoteDirectory]);
    runReleaseSetupCommand(['git', '-C', $sourceDirectory, 'remote', 'add', 'origin', $remoteDirectory]);
    runReleaseSetupCommand(['git', '-C', $sourceDirectory, 'push', '--set-upstream', 'origin', 'main']);

    return [
        'source' => $sourceDirectory,
        'revision' => runReleaseSetupCommand(['git', '-C', $sourceDirectory, 'rev-parse', 'HEAD']),
    ];
}

function installReleaseTestSudo(string $binaryDirectory): void
{
    file_put_contents($binaryDirectory.'/sudo', <<<'SH'
#!/bin/sh
set -eu

printf 'sudo %s\n' "$*" >> "$RELEASE_TEST_COMMAND_LOG"

case "${1:-}" in
    -v)
        exit 0
        ;;
    find)
        shift
        exec /usr/bin/find "$@"
        ;;
    systemctl)
        if [ "${2:-} ${3:-}" = 'start money-assistant-backup.service' ]; then
            touch "$RELEASE_TEST_BACKUP_DIRECTORY/money-assistant-new.dump.age"
            exit 0
        fi

        if [ "${2:-}" = show ]; then
            printf '%s\n' "${RELEASE_TEST_BACKUP_RESULT:-success}"
            exit 0
        fi
        ;;
    install)
        for argument in "$@"; do
            target="$argument"
        done
        mkdir -p "$target"
        exit 0
        ;;
    rsync)
        previous=''
        for argument in "$@"; do
            source="$previous"
            destination="$argument"
            previous="$argument"
        done
        mkdir -p "$destination"
        find "$destination" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
        cp -a "${source%/}/." "${destination%/}/"
        exit 0
        ;;
    chmod)
        shift
        exec /usr/bin/chmod "$@"
        ;;
    */production/install-production-services|*/production/deploy-production|*/production/verify-private-ingress)
        exit 0
        ;;
esac

printf 'Unexpected sudo command: %s\n' "$*" >&2
exit 64
SH);
    chmod($binaryDirectory.'/sudo', 0700);
}

/** @return array<string, string> */
function releaseTestEnvironment(string $temporaryDirectory, string $sourceDirectory): array
{
    $binaryDirectory = $temporaryDirectory.'/bin';
    $backupDirectory = $temporaryDirectory.'/backups';
    $environmentFile = $temporaryDirectory.'/production.env';

    mkdir($binaryDirectory, 0700, true);
    mkdir($backupDirectory, 0700, true);
    file_put_contents($backupDirectory.'/money-assistant-old.dump.age', 'old backup');
    file_put_contents($environmentFile, "PRIVATE_HOSTNAME=money-assistant.example.test\n");
    installReleaseTestSudo($binaryDirectory);

    return [
        'BACKUP_DIRECTORY' => $backupDirectory,
        'ENVIRONMENT_FILE' => $environmentFile,
        'MONEY_ASSISTANT_APPLICATION_DIRECTORY' => $temporaryDirectory.'/application',
        'MONEY_ASSISTANT_RELEASE_SOURCE_DIRECTORY' => $sourceDirectory,
        'PATH' => $binaryDirectory.':'.getenv('PATH'),
        'RELEASE_LOCK_FILE' => $temporaryDirectory.'/release.lock',
        'RELEASE_TEST_BACKUP_DIRECTORY' => $backupDirectory,
        'RELEASE_TEST_COMMAND_LOG' => $temporaryDirectory.'/commands.log',
    ];
}

/** @param array<string, string> $environment */
function runProductionRelease(array $environment): Process
{
    $process = new Process(
        [base_path('production/release-production')],
        base_path(),
        $environment,
    );
    $process->run();

    return $process;
}

test('the production release creates a fresh backup before promoting and deploying one clean main revision', function () {
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-release-'.str()->uuid();
    mkdir($temporaryDirectory, 0700, true);
    $repository = createReleaseRepository($temporaryDirectory);
    $environment = releaseTestEnvironment($temporaryDirectory, $repository['source']);

    try {
        $release = runProductionRelease($environment);
        $commands = file_get_contents($environment['RELEASE_TEST_COMMAND_LOG']);

        expect($release->getExitCode())->toBe(0, $release->getErrorOutput())
            ->and($release->getOutput())
            ->toContain('Production release completed successfully.')
            ->toContain($repository['revision'])
            ->and(file_get_contents($environment['MONEY_ASSISTANT_APPLICATION_DIRECTORY'].'/tracked.txt'))
            ->toBe("release contents\n")
            ->and($commands)
            ->toContain('sudo systemctl start money-assistant-backup.service')
            ->toContain('sudo '.$environment['MONEY_ASSISTANT_APPLICATION_DIRECTORY'].'/production/install-production-services')
            ->toContain('sudo '.$environment['MONEY_ASSISTANT_APPLICATION_DIRECTORY'].'/production/deploy-production')
            ->toContain('sudo '.$environment['MONEY_ASSISTANT_APPLICATION_DIRECTORY'].'/production/verify-private-ingress '.$environment['ENVIRONMENT_FILE']);

        expect(strpos($commands, 'systemctl start money-assistant-backup.service'))
            ->toBeLessThan(strpos($commands, ' rsync '));
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('the production release stops before privileged work when the main checkout is dirty', function () {
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-release-'.str()->uuid();
    mkdir($temporaryDirectory, 0700, true);
    $repository = createReleaseRepository($temporaryDirectory);
    $environment = releaseTestEnvironment($temporaryDirectory, $repository['source']);
    file_put_contents($repository['source'].'/tracked.txt', "dirty contents\n");

    try {
        $release = runProductionRelease($environment);

        expect($release->getExitCode())->toBe(1)
            ->and($release->getErrorOutput())->toContain('release source must be a clean main checkout')
            ->and(file_exists($environment['RELEASE_TEST_COMMAND_LOG']))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('the production release stops before promotion when the fresh backup fails', function () {
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-release-'.str()->uuid();
    mkdir($temporaryDirectory, 0700, true);
    $repository = createReleaseRepository($temporaryDirectory);
    $environment = [
        ...releaseTestEnvironment($temporaryDirectory, $repository['source']),
        'RELEASE_TEST_BACKUP_RESULT' => 'failed',
    ];

    try {
        $release = runProductionRelease($environment);
        $commands = file_get_contents($environment['RELEASE_TEST_COMMAND_LOG']);

        expect($release->getExitCode())->toBe(1)
            ->and($release->getErrorOutput())->toContain('fresh production backup failed')
            ->and($commands)->not->toContain(' rsync ', 'deploy-production');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});
