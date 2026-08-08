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

test('the stable launcher enforces its host-installed operational allowlist', function (): void {
    $temporaryDirectory = operationalBundleDirectory();
    $bundle = $temporaryDirectory.'/operations.tar';
    $hostAllowlist = $temporaryDirectory.'/host-operational-bundle.allowlist';
    $revision = str_repeat('f', 40);

    try {
        $built = runOperationalCommand('build-operational-bundle', [$revision, $bundle]);
        $checksum = trim($built->getOutput());
        $allowlist = file_get_contents(base_path('operational-bundle.allowlist'));
        file_put_contents(
            $hostAllowlist,
            str_replace("money-assistant-tailnet.service 0644\n", '', $allowlist),
        );

        $validated = runOperationalCommand('activate-production-release', [
            'validate',
            '--source-revision', $revision,
            '--app-image', 'ghcr.io/rgomeztinoco/money-assistant@sha256:'.str_repeat('a', 64),
            '--bundle-checksum', $checksum,
            '--bundle', $bundle,
        ], [
            'OPERATIONAL_BUNDLE_ALLOWLIST' => $hostAllowlist,
            'OPERATIONAL_BUNDLE_ROOT' => $temporaryDirectory.'/staging',
        ]);

        expect($validated->getExitCode())->toBe(1)
            ->and($validated->getErrorOutput())
            ->toContain('unexpected bundle entry: money-assistant-tailnet.service');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('active release verification preserves immutable bundle provenance', function (): void {
    $temporaryDirectory = operationalBundleDirectory();
    $checksum = str_repeat('b', 64);
    $revision = str_repeat('c', 40);
    $bundleDirectory = $temporaryDirectory.'/'.$checksum;
    $activeBundle = $temporaryDirectory.'/active';
    $environmentFile = $temporaryDirectory.'/production.env';
    $verificationLog = $temporaryDirectory.'/verification.log';

    try {
        mkdir($bundleDirectory, 0700);
        copy(base_path('verify-active-production-release'), $bundleDirectory.'/verify-active-production-release');
        chmod($bundleDirectory.'/verify-active-production-release', 0755);
        file_put_contents($bundleDirectory.'/SOURCE_REVISION', $revision."\n");
        file_put_contents($bundleDirectory.'/compose.production.yaml', "services: {}\n");
        file_put_contents($environmentFile, "host-managed=true\n");
        file_put_contents($bundleDirectory.'/deploy-production', <<<'SH'
#!/bin/sh
printf 'args=%s\n' "$*" > "$ACTIVE_VERIFICATION_LOG"
printf 'checksum=%s\n' "$OPERATIONAL_BUNDLE_CHECKSUM" >> "$ACTIVE_VERIFICATION_LOG"
printf 'revision=%s\n' "$OPERATIONAL_BUNDLE_REVISION" >> "$ACTIVE_VERIFICATION_LOG"
printf 'directory=%s\n' "$OPERATIONAL_BUNDLE_DIRECTORY" >> "$ACTIVE_VERIFICATION_LOG"
printf 'active=%s\n' "$OPERATIONAL_BUNDLE_ACTIVE_LINK" >> "$ACTIVE_VERIFICATION_LOG"
printf 'compose=%s\n' "$COMPOSE_FILE" >> "$ACTIVE_VERIFICATION_LOG"
SH);
        chmod($bundleDirectory.'/deploy-production', 0755);
        symlink($bundleDirectory, $activeBundle);

        $verified = new Process(
            [$activeBundle.'/verify-active-production-release'],
            base_path(),
            [
                'ACTIVE_VERIFICATION_LOG' => $verificationLog,
                'OPERATIONAL_BUNDLE_ACTIVE_LINK' => $activeBundle,
                'PRODUCTION_ENV_FILE' => $environmentFile,
                'PATH' => getenv('PATH'),
            ],
        );
        $verified->run();

        expect($verified->getExitCode())->toBe(0, $verified->getErrorOutput())
            ->and(file_get_contents($verificationLog))
            ->toContain('args=verify --env-file '.$environmentFile)
            ->toContain("checksum={$checksum}")
            ->toContain("revision={$revision}")
            ->toContain("directory={$bundleDirectory}")
            ->toContain("active={$activeBundle}")
            ->toContain("compose={$bundleDirectory}/compose.production.yaml");
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});
