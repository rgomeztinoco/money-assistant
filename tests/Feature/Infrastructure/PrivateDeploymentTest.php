<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function manualDeploymentTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir().'/money-assistant-manual-deployment-'.str()->uuid();
    mkdir($directory, 0700, true);

    return $directory;
}

test('a published release is activated only by a manual host command', function (): void {
    $temporaryDirectory = manualDeploymentTemporaryDirectory();
    $fixtureDirectory = $temporaryDirectory.'/release';
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $configurationFile = $temporaryDirectory.'/deployment.env';
    $productionEnvironment = $temporaryDirectory.'/production.env';
    $launcherLog = $temporaryDirectory.'/launcher.log';
    $systemctlLog = $temporaryDirectory.'/systemctl.log';
    $verifierLog = $temporaryDirectory.'/verifier.log';
    $revision = str_repeat('a', 40);
    $image = 'ghcr.io/rgomeztinoco/money-assistant@sha256:'.str_repeat('b', 64);
    $bundleName = "operational-bundle-{$revision}.tar";
    $bundle = 'verified immutable operational bundle';
    $checksum = hash('sha256', $bundle);

    mkdir($fixtureDirectory, 0700);
    mkdir($fakeBinaryDirectory, 0700);
    file_put_contents($fixtureDirectory.'/'.$bundleName, $bundle);
    file_put_contents($fixtureDirectory.'/'.$bundleName.'.sha256', "{$checksum}  {$bundleName}\n");
    file_put_contents($fixtureDirectory."/release-metadata-{$revision}.env", implode("\n", [
        "SOURCE_REVISION={$revision}",
        "APPLICATION_IMAGE={$image}",
        "BUNDLE_CHECKSUM={$checksum}",
        '',
    ]));
    file_put_contents($configurationFile, implode("\n", [
        'DEPLOYMENT_GITHUB_REPOSITORY=rgomeztinoco/money-assistant',
        'DEPLOYMENT_IMAGE_REPOSITORY=ghcr.io/rgomeztinoco/money-assistant',
        "PRODUCTION_ENV_FILE={$productionEnvironment}",
        '',
    ]));
    chmod($configurationFile, 0600);
    file_put_contents($productionEnvironment, "host-managed=true\n");

    file_put_contents($fakeBinaryDirectory.'/curl', <<<'SH'
#!/bin/sh
output=''
url=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output) output="$2"; shift 2 ;;
        --proto) shift 2 ;;
        --tlsv1.2) shift ;;
        --fail|--location|--silent|--show-error) shift ;;
        *) url="$1"; shift ;;
    esac
done
cp "$DEPLOYMENT_RELEASE_FIXTURES/$(basename -- "$url")" "$output"
SH);
    file_put_contents($fakeBinaryDirectory.'/activate-production-release', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" > "$DEPLOYMENT_LAUNCHER_LOG"
SH);
    file_put_contents($fakeBinaryDirectory.'/systemctl', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" > "$DEPLOYMENT_SYSTEMCTL_LOG"
SH);
    file_put_contents($fakeBinaryDirectory.'/verify-production-deployment', <<<'SH'
#!/bin/sh
printf '%s\n' verified > "$DEPLOYMENT_VERIFIER_LOG"
SH);
    foreach (['curl', 'activate-production-release', 'systemctl', 'verify-production-deployment'] as $binary) {
        chmod($fakeBinaryDirectory.'/'.$binary, 0700);
    }

    try {
        $process = new Process(
            [base_path('deploy-production-release'), $revision],
            base_path(),
            [
                'DEPLOYMENT_CONFIGURATION_FILE' => $configurationFile,
                'DEPLOYMENT_CURL' => $fakeBinaryDirectory.'/curl',
                'DEPLOYMENT_EXPECTED_UID' => (string) posix_geteuid(),
                'DEPLOYMENT_HOST_VERIFIER' => $fakeBinaryDirectory.'/verify-production-deployment',
                'DEPLOYMENT_LAUNCHER' => $fakeBinaryDirectory.'/activate-production-release',
                'DEPLOYMENT_LAUNCHER_LOG' => $launcherLog,
                'DEPLOYMENT_RELEASE_FIXTURES' => $fixtureDirectory,
                'DEPLOYMENT_SYSTEMCTL' => $fakeBinaryDirectory.'/systemctl',
                'DEPLOYMENT_SYSTEMCTL_LOG' => $systemctlLog,
                'DEPLOYMENT_VERIFIER_LOG' => $verifierLog,
                'PATH' => getenv('PATH'),
            ],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toContain("Production release activated manually: {$revision}")
            ->and(file_get_contents($launcherLog))
            ->toContain('deploy')
            ->toContain("--source-revision {$revision}")
            ->toContain("--app-image {$image}")
            ->toContain("--bundle-checksum {$checksum}")
            ->toContain("--env-file {$productionEnvironment}")
            ->and(file_get_contents($systemctlLog))
            ->toBe("enable --now money-assistant-production.service money-assistant-tailnet.service\n")
            ->and(file_get_contents($verifierLog))->toBe("verified\n");
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('manual deployment rejects release metadata outside the configured repository', function (): void {
    $temporaryDirectory = manualDeploymentTemporaryDirectory();
    $fixtureDirectory = $temporaryDirectory.'/release';
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $configurationFile = $temporaryDirectory.'/deployment.env';
    $productionEnvironment = $temporaryDirectory.'/production.env';
    $revision = str_repeat('c', 40);
    $bundleName = "operational-bundle-{$revision}.tar";
    $bundle = 'bundle';
    $checksum = hash('sha256', $bundle);

    mkdir($fixtureDirectory, 0700);
    mkdir($fakeBinaryDirectory, 0700);
    file_put_contents($fixtureDirectory.'/'.$bundleName, $bundle);
    file_put_contents($fixtureDirectory.'/'.$bundleName.'.sha256', "{$checksum}  {$bundleName}\n");
    file_put_contents($fixtureDirectory."/release-metadata-{$revision}.env", implode("\n", [
        "SOURCE_REVISION={$revision}",
        'APPLICATION_IMAGE=ghcr.io/another-owner/money-assistant@sha256:'.str_repeat('d', 64),
        "BUNDLE_CHECKSUM={$checksum}",
        '',
    ]));
    file_put_contents($configurationFile, implode("\n", [
        'DEPLOYMENT_GITHUB_REPOSITORY=rgomeztinoco/money-assistant',
        'DEPLOYMENT_IMAGE_REPOSITORY=ghcr.io/rgomeztinoco/money-assistant',
        "PRODUCTION_ENV_FILE={$productionEnvironment}",
        '',
    ]));
    chmod($configurationFile, 0600);
    file_put_contents($productionEnvironment, "host-managed=true\n");
    file_put_contents($fakeBinaryDirectory.'/curl', <<<'SH'
#!/bin/sh
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output) output="$2"; shift 2 ;;
        --proto) shift 2 ;;
        --tlsv1.2) shift ;;
        --fail|--location|--silent|--show-error) shift ;;
        *) url="$1"; shift ;;
    esac
done
cp "$DEPLOYMENT_RELEASE_FIXTURES/$(basename -- "$url")" "$output"
SH);
    file_put_contents($fakeBinaryDirectory.'/launcher', "#!/bin/sh\nexit 0\n");
    file_put_contents($fakeBinaryDirectory.'/verifier', "#!/bin/sh\nexit 0\n");
    chmod($fakeBinaryDirectory.'/curl', 0700);
    chmod($fakeBinaryDirectory.'/launcher', 0700);
    chmod($fakeBinaryDirectory.'/verifier', 0700);

    try {
        $process = new Process(
            [base_path('deploy-production-release'), $revision],
            base_path(),
            [
                'DEPLOYMENT_CONFIGURATION_FILE' => $configurationFile,
                'DEPLOYMENT_CURL' => $fakeBinaryDirectory.'/curl',
                'DEPLOYMENT_EXPECTED_UID' => (string) posix_geteuid(),
                'DEPLOYMENT_HOST_VERIFIER' => $fakeBinaryDirectory.'/verifier',
                'DEPLOYMENT_LAUNCHER' => $fakeBinaryDirectory.'/launcher',
                'DEPLOYMENT_RELEASE_FIXTURES' => $fixtureDirectory,
                'PATH' => getenv('PATH'),
            ],
        );
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('release image repository is not allowed');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('the host bootstrap installs a local deployment command without changing network access', function (): void {
    $installer = file_get_contents(base_path('install-production-deployment'));
    $manualDeployer = file_get_contents(base_path('deploy-production-release'));
    $verifier = file_get_contents(base_path('verify-production-deployment'));
    $runbook = file_get_contents(base_path('docs/production-deployment.md'));

    expect($installer)
        ->toContain('install -m 0755 "${script_directory}/deploy-production-release" /usr/local/sbin/deploy-production-release')
        ->toContain('DEPLOYMENT_GITHUB_REPOSITORY=')
        ->toContain('DEPLOYMENT_IMAGE_REPOSITORY=')
        ->toContain('systemctl daemon-reload')
        ->not->toContain('useradd', 'sshd', 'visudo', 'ufw', 'tailscale')
        ->and($manualDeployer)
        ->toContain('releases/download/release-${revision}')
        ->toContain('release-metadata-${revision}.env')
        ->toContain('operational-bundle-${revision}.tar')
        ->toContain('activate-production-release')
        ->not->toContain('ssh', 'tailscale')
        ->and($verifier)
        ->toContain('/usr/local/sbin/deploy-production-release')
        ->toContain('/var/lib/money-assistant/operational-bundles/active')
        ->toContain('verify-private-ingress')
        ->not->toContain('sshd', 'visudo', 'ufw show added', 'money-assistant-deploy')
        ->and($runbook)
        ->toContain('sudo deploy-production-release <revision>')
        ->toContain('does not replace the tailnet policy')
        ->toContain('promotion of the existing development data are separate operations')
        ->not->toContain('TAILSCALE_OIDC_CLIENT_ID', 'DEPLOYMENT_SSH_PRIVATE_KEY');
});

test('the host verifier checks the active release and reboot services without requiring machine tags', function (): void {
    $temporaryDirectory = manualDeploymentTemporaryDirectory();
    $configurationDirectory = $temporaryDirectory.'/etc/money-assistant';
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $activeBundleDirectory = $temporaryDirectory.'/'.str_repeat('e', 64);
    $activeBundleLink = $temporaryDirectory.'/active';
    $launcher = $temporaryDirectory.'/activate-production-release';
    $manualDeployer = $temporaryDirectory.'/deploy-production-release';
    $commandLog = $temporaryDirectory.'/commands.log';

    mkdir($configurationDirectory, 0700, true);
    mkdir($fakeBinaryDirectory, 0700, true);
    mkdir($activeBundleDirectory, 0700, true);
    file_put_contents($configurationDirectory.'/deployment.env', "configured=true\n");
    file_put_contents($configurationDirectory.'/production.env', "host-managed=true\n");
    chmod($configurationDirectory.'/deployment.env', 0600);
    chmod($configurationDirectory.'/production.env', 0600);
    foreach ([$launcher, $manualDeployer] as $hostExecutable) {
        file_put_contents($hostExecutable, "#!/bin/sh\nexit 0\n");
        chmod($hostExecutable, 0755);
    }
    file_put_contents($activeBundleDirectory.'/verify-active-production-release', <<<'SH'
#!/bin/sh
printf 'verify-active\n' >> "$DEPLOYMENT_VERIFICATION_LOG"
SH);
    file_put_contents($activeBundleDirectory.'/verify-private-ingress', <<<'SH'
#!/bin/sh
printf 'verify-ingress %s\n' "$*" >> "$DEPLOYMENT_VERIFICATION_LOG"
SH);
    chmod($activeBundleDirectory.'/verify-active-production-release', 0755);
    chmod($activeBundleDirectory.'/verify-private-ingress', 0755);
    symlink($activeBundleDirectory, $activeBundleLink);
    file_put_contents($fakeBinaryDirectory.'/systemctl', <<<'SH'
#!/bin/sh
printf 'systemctl %s\n' "$*" >> "$DEPLOYMENT_VERIFICATION_LOG"
SH);
    chmod($fakeBinaryDirectory.'/systemctl', 0755);

    try {
        $owner = trim((new Process(['stat', '-c', '%U:%G', $launcher]))->mustRun()->getOutput());
        $process = new Process(
            [base_path('verify-production-deployment')],
            base_path(),
            [
                'DEPLOYMENT_ACTIVE_BUNDLE' => $activeBundleLink,
                'DEPLOYMENT_CONFIGURATION_DIRECTORY' => $configurationDirectory,
                'DEPLOYMENT_EXPECTED_OWNER' => $owner,
                'DEPLOYMENT_EXPECTED_UID' => (string) posix_geteuid(),
                'DEPLOYMENT_LAUNCHER' => $launcher,
                'DEPLOYMENT_MANUAL_DEPLOYER' => $manualDeployer,
                'DEPLOYMENT_VERIFICATION_LOG' => $commandLog,
                'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
            ],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toContain('Manual production deployment is healthy')
            ->and(file_get_contents($commandLog))
            ->toContain('systemctl is-enabled --quiet money-assistant-production.service')
            ->toContain('systemctl is-active --quiet money-assistant-tailnet.service')
            ->toContain('verify-active')
            ->toContain('verify-ingress '.$configurationDirectory.'/production.env');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});
