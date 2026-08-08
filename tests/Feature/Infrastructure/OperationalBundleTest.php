<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function operationalBundleDirectory(): string
{
    $directory = sys_get_temp_dir().'/money-assistant-operational-bundle-'.str()->uuid();
    mkdir($directory, 0700, true);

    return $directory;
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function runOperationalCommand(string $command, array $arguments, array $environment = []): Process
{
    $process = new Process(
        [base_path($command), ...$arguments],
        base_path(),
        array_merge(['PATH' => getenv('PATH')], $environment),
    );
    $process->setTimeout(30);
    $process->run();

    return $process;
}

test('an allowlisted operational bundle is immutable and accepted by the host launcher', function (): void {
    $temporaryDirectory = operationalBundleDirectory();
    $bundle = $temporaryDirectory.'/operations.tar';
    $stagingDirectory = $temporaryDirectory.'/staging';
    $revision = str_repeat('a', 40);

    try {
        $built = runOperationalCommand('build-operational-bundle', [$revision, $bundle]);

        expect($built->getExitCode())->toBe(0, $built->getErrorOutput())
            ->and($built->getOutput())->toMatch('/^[a-f0-9]{64}\n$/');

        $checksum = trim($built->getOutput());
        $validated = runOperationalCommand('activate-production-release', [
            'validate',
            '--source-revision', $revision,
            '--app-image', 'ghcr.io/rgomeztinoco/money-assistant@sha256:'.str_repeat('b', 64),
            '--bundle-checksum', $checksum,
            '--bundle', $bundle,
        ], ['OPERATIONAL_BUNDLE_ROOT' => $stagingDirectory]);

        expect($validated->getExitCode())->toBe(0, $validated->getErrorOutput())
            ->and($validated->getOutput())->toContain("Operational bundle validated: {$checksum}")
            ->and(is_dir($stagingDirectory.'/'.$checksum))->toBeTrue()
            ->and(is_file($stagingDirectory.'/'.$checksum.'/Caddyfile.production'))->toBeTrue()
            ->and(file_get_contents($stagingDirectory.'/'.$checksum.'/SOURCE_REVISION'))->toBe($revision."\n");
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('deployment signals reach the staged deployment process', function (): void {
    $launcher = file_get_contents(base_path('activate-production-release'));

    expect($launcher)->toContain('exec "${candidate_directory}/deploy-production" deploy');
});

test('an operational bundle can be written into an existing shared directory', function (): void {
    $temporaryDirectory = sys_get_temp_dir();
    $bundle = $temporaryDirectory.'/money-assistant-operational-bundle-'.str()->uuid().'.tar';
    $directoryPermissions = fileperms($temporaryDirectory) & 07777;

    try {
        $built = runOperationalCommand('build-operational-bundle', [str_repeat('f', 40), $bundle]);

        expect($built->getExitCode())->toBe(0, $built->getErrorOutput())
            ->and(is_file($bundle))->toBeTrue()
            ->and(fileperms($temporaryDirectory) & 07777)->toBe($directoryPermissions);
    } finally {
        if (is_file($bundle)) {
            unlink($bundle);
        }
    }
});

test('the host launcher rejects unexpected bundle contents and mismatched release inputs', function (): void {
    $temporaryDirectory = operationalBundleDirectory();
    $bundle = $temporaryDirectory.'/operations.tar';
    $revision = str_repeat('c', 40);

    try {
        $built = runOperationalCommand('build-operational-bundle', [$revision, $bundle]);
        $checksum = trim($built->getOutput());
        $unpacked = $temporaryDirectory.'/unpacked';
        mkdir($unpacked, 0700);
        (new Process(['tar', '-xf', $bundle, '-C', $unpacked]))->mustRun();
        file_put_contents($unpacked.'/runtime-credential', 'must-not-ship');
        file_put_contents(
            $unpacked.'/MANIFEST.sha256',
            '0644 '.hash_file('sha256', $unpacked.'/runtime-credential')." runtime-credential\n",
            FILE_APPEND,
        );
        (new Process(['tar', '-cf', $bundle, '-C', $unpacked, '.']))->mustRun();
        $tamperedChecksum = hash_file('sha256', $bundle);

        $unexpected = runOperationalCommand('activate-production-release', [
            'validate', '--source-revision', $revision,
            '--app-image', 'example.test/app@sha256:'.str_repeat('d', 64),
            '--bundle-checksum', $tamperedChecksum,
            '--bundle', $bundle,
        ], ['OPERATIONAL_BUNDLE_ROOT' => $temporaryDirectory.'/staging']);
        $mismatched = runOperationalCommand('activate-production-release', [
            'validate', '--source-revision', str_repeat('e', 40),
            '--app-image', 'example.test/app@sha256:'.str_repeat('d', 64),
            '--bundle-checksum', $checksum,
            '--bundle', $bundle,
        ], ['OPERATIONAL_BUNDLE_ROOT' => $temporaryDirectory.'/other-staging']);

        expect($unexpected->getExitCode())->toBe(1)
            ->and($unexpected->getErrorOutput())->toContain('unexpected bundle entry')
            ->and($mismatched->getExitCode())->toBe(1);
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});
