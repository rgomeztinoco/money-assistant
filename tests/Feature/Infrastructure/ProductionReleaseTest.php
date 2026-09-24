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
    mkdir($sourceDirectory.'/production', 0755);
    file_put_contents($sourceDirectory.'/tracked.txt', "release contents\n");
    file_put_contents($sourceDirectory.'/production/Caddyfile.production', "http:// { respond 200 }\n");

    runReleaseSetupCommand(['git', 'init', '--initial-branch=main', $sourceDirectory]);
    runReleaseSetupCommand(['git', '-C', $sourceDirectory, 'config', 'user.email', 'release@example.test']);
    runReleaseSetupCommand(['git', '-C', $sourceDirectory, 'config', 'user.name', 'Release Test']);
    runReleaseSetupCommand(['git', '-C', $sourceDirectory, 'add', '.']);
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
            touch -d '+1 second' "$RELEASE_TEST_BACKUP_DIRECTORY/money-assistant-new.dump.age"
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

function installReleaseTestGitHub(string $binaryDirectory): void
{
    file_put_contents($binaryDirectory.'/gh', <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$RELEASE_TEST_GITHUB_LOG"
[ "${RELEASE_TEST_GITHUB_ERROR:-0}" = 0 ] || exit 1

case "$1 $2" in
    'run list')
        [ "${RELEASE_TEST_MISSING_RUN:-0}" = 0 ] || exit 0
        printf '123\t%s\t%s\t%s\n' "$RELEASE_TEST_REVISION" "${RELEASE_TEST_RUN_BRANCH:-main}" "${RELEASE_TEST_RUN_EVENT:-push}"
        ;;
    'run view')
        printf '__run__\t%s\n' "${RELEASE_TEST_RUN_STATUS:-completed}"
        cat "$RELEASE_TEST_CHECKS_FILE"
        ;;
    *) exit 64 ;;
esac
SH);
    chmod($binaryDirectory.'/gh', 0700);
    file_put_contents($binaryDirectory.'/sleep', <<<'SH'
#!/bin/sh
set -eu
printf 'wait\n' >> "$RELEASE_TEST_GITHUB_LOG"
cp "$RELEASE_TEST_CHECKS_FILE.next" "$RELEASE_TEST_CHECKS_FILE"

if [ "${RELEASE_TEST_ADVANCE_REMOTE:-0}" = 1 ]; then
    git -C "$MONEY_ASSISTANT_RELEASE_SOURCE_DIRECTORY" commit --allow-empty -m 'New main during CI'
    git -C "$MONEY_ASSISTANT_RELEASE_SOURCE_DIRECTORY" push origin main
    git -C "$MONEY_ASSISTANT_RELEASE_SOURCE_DIRECTORY" reset --hard "$RELEASE_TEST_REVISION"
fi
SH);
    chmod($binaryDirectory.'/sleep', 0700);
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
    installReleaseTestGitHub($binaryDirectory);
    $checksFile = $temporaryDirectory.'/checks.tsv';
    file_put_contents($checksFile, "ci\tcompleted\tsuccess\nproduction-stack\tcompleted\tsuccess\n");

    return [
        'BACKUP_DIRECTORY' => $backupDirectory,
        'ENVIRONMENT_FILE' => $environmentFile,
        'MONEY_ASSISTANT_APPLICATION_DIRECTORY' => $temporaryDirectory.'/application',
        'MONEY_ASSISTANT_RELEASE_SOURCE_DIRECTORY' => $sourceDirectory,
        'PATH' => $binaryDirectory.':'.getenv('PATH'),
        'RELEASE_LOCK_FILE' => $temporaryDirectory.'/release.lock',
        'RELEASE_TEST_BACKUP_DIRECTORY' => $backupDirectory,
        'RELEASE_TEST_COMMAND_LOG' => $temporaryDirectory.'/commands.log',
        'RELEASE_TEST_GITHUB_LOG' => $temporaryDirectory.'/github.log',
        'RELEASE_TEST_CHECKS_FILE' => $checksFile,
        'RELEASE_TEST_REVISION' => runReleaseSetupCommand(['git', '-C', $sourceDirectory, 'rev-parse', 'HEAD']),
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

test('optional local checks build current assets and mirror the CI test partitions', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $runbook = file_get_contents(base_path('docs/production-deployment.md'));

    expect($composer['scripts']['test:deployment'] ?? null)->toBe([
        'pnpm run build',
        '@php artisan test --compact tests/Feature',
        '@php artisan test --compact --parallel --processes=2 tests/Browser',
    ])->and($runbook)->toContain('vendor/bin/sail composer test:deployment');
});

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
            ->and(fileperms($environment['MONEY_ASSISTANT_APPLICATION_DIRECTORY'].'/production') & 0777)
            ->toBe(0755)
            ->and(fileperms($environment['MONEY_ASSISTANT_APPLICATION_DIRECTORY'].'/production/Caddyfile.production') & 0777)
            ->toBe(0644)
            ->and($commands)
            ->toContain('sudo systemctl start money-assistant-backup.service')
            ->toContain('sudo '.$environment['MONEY_ASSISTANT_APPLICATION_DIRECTORY'].'/production/install-production-services')
            ->toContain('sudo '.$environment['MONEY_ASSISTANT_APPLICATION_DIRECTORY'].'/production/deploy-production')
            ->toContain('sudo '.$environment['MONEY_ASSISTANT_APPLICATION_DIRECTORY'].'/production/verify-private-ingress '.$environment['ENVIRONMENT_FILE']);

        expect(strpos($commands, 'systemctl start money-assistant-backup.service'))
            ->toBeLessThan(strpos($commands, ' rsync '));

        expect(file_get_contents($environment['RELEASE_TEST_GITHUB_LOG']))
            ->toContain('--branch main --commit '.$repository['revision'].' --event push --workflow tests.yml')
            ->toContain('run view 123');
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

test('the production release rejects local main when it differs from origin', function (string $position): void {
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-release-'.str()->uuid();
    mkdir($temporaryDirectory, 0700, true);
    $repository = createReleaseRepository($temporaryDirectory);
    $environment = releaseTestEnvironment($temporaryDirectory, $repository['source']);
    runReleaseSetupCommand(['git', '-C', $repository['source'], 'commit', '--allow-empty', '-m', 'Next revision']);

    if ($position === 'behind') {
        runReleaseSetupCommand(['git', '-C', $repository['source'], 'push', 'origin', 'main']);
        runReleaseSetupCommand(['git', '-C', $repository['source'], 'reset', '--hard', $repository['revision']]);
    }

    $localRevision = runReleaseSetupCommand(['git', '-C', $repository['source'], 'rev-parse', 'HEAD']);

    try {
        $release = runProductionRelease($environment);

        expect($release->getExitCode())->toBe(1)
            ->and($release->getErrorOutput())->toContain('local main must exactly match origin/main')
            ->and(file_exists($environment['RELEASE_TEST_COMMAND_LOG']))->toBeFalse()
            ->and(runReleaseSetupCommand(['git', '-C', $repository['source'], 'rev-parse', 'HEAD']))->toBe($localRevision);
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
})->with(['ahead', 'behind']);

test('the production release blocks missing or unsuccessful required GitHub checks', function (string $checks): void {
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-release-'.str()->uuid();
    mkdir($temporaryDirectory, 0700, true);
    $repository = createReleaseRepository($temporaryDirectory);
    $environment = releaseTestEnvironment($temporaryDirectory, $repository['source']);
    file_put_contents($environment['RELEASE_TEST_CHECKS_FILE'], $checks);

    try {
        $release = runProductionRelease($environment);

        expect($release->getExitCode())->toBe(1)
            ->and($release->getErrorOutput())->toContain('required GitHub check')
            ->and(file_exists($environment['RELEASE_TEST_COMMAND_LOG']))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
})->with([
    'missing production stack' => ["ci\tcompleted\tsuccess\n"],
    'failed CI' => ["ci\tcompleted\tfailure\nproduction-stack\tcompleted\tsuccess\n"],
    'cancelled production stack' => ["ci\tcompleted\tsuccess\nproduction-stack\tcompleted\tcancelled\n"],
    'skipped CI' => ["ci\tcompleted\tskipped\nproduction-stack\tcompleted\tsuccess\n"],
]);

test('the production release blocks missing inaccessible or mismatched GitHub runs', function (string $key, string $value, string $message): void {
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-release-'.str()->uuid();
    mkdir($temporaryDirectory, 0700, true);
    $repository = createReleaseRepository($temporaryDirectory);
    $environment = [...releaseTestEnvironment($temporaryDirectory, $repository['source']), $key => $value];

    try {
        $release = runProductionRelease($environment);

        expect($release->getExitCode())->toBe(1)
            ->and($release->getErrorOutput())->toContain($message)
            ->and(file_exists($environment['RELEASE_TEST_COMMAND_LOG']))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
})->with([
    'missing run' => ['RELEASE_TEST_MISSING_RUN', '1', 'no GitHub CI push run exists'],
    'API failure' => ['RELEASE_TEST_GITHUB_ERROR', '1', 'could not read GitHub CI'],
    'another revision' => ['RELEASE_TEST_REVISION', str_repeat('a', 40), 'does not match the release revision'],
    'another branch' => ['RELEASE_TEST_RUN_BRANCH', 'feature', 'does not match the release revision'],
    'PR checks only' => ['RELEASE_TEST_RUN_EVENT', 'pull_request', 'does not match the release revision'],
]);

test('the production release waits for pending CI and rechecks main before deployment', function (bool $advanceRemote, string $checks): void {
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-release-'.str()->uuid();
    mkdir($temporaryDirectory, 0700, true);
    $repository = createReleaseRepository($temporaryDirectory);
    $environment = releaseTestEnvironment($temporaryDirectory, $repository['source']);
    $environment['RELEASE_TEST_ADVANCE_REMOTE'] = $advanceRemote ? '1' : '0';
    $environment['RELEASE_TEST_RUN_STATUS'] = 'in_progress';
    file_put_contents($environment['RELEASE_TEST_CHECKS_FILE'], $checks);
    file_put_contents($environment['RELEASE_TEST_CHECKS_FILE'].'.next', "ci\tcompleted\tsuccess\nproduction-stack\tcompleted\tsuccess\n");

    try {
        $release = runProductionRelease($environment);

        expect($release->getOutput())->toContain('Waiting for required GitHub checks')
            ->and(file_get_contents($environment['RELEASE_TEST_GITHUB_LOG']))->toContain('wait');

        if ($advanceRemote) {
            expect($release->getExitCode())->toBe(1)
                ->and($release->getErrorOutput())->toContain('main changed while checking CI')
                ->and(file_exists($environment['RELEASE_TEST_COMMAND_LOG']))->toBeFalse();
        } else {
            expect($release->getExitCode())->toBe(0, $release->getErrorOutput())
                ->and($release->getOutput())->toContain('Production release completed successfully.');
        }
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
})->with([
    'unchanged main' => [false, "ci\tqueued\t\nproduction-stack\tin_progress\t\n"],
    'main advanced during CI' => [true, "ci\tqueued\t\nproduction-stack\tin_progress\t\n"],
    'CI awaits prerequisite jobs' => [false, "production-stack\tin_progress\t\n"],
]);

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
