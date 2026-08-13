<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function productionTrustTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir().'/money-assistant-production-trust-'.str()->uuid();
    mkdir($directory, 0700, true);

    return $directory;
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function runProductionTrustScript(string $script, array $arguments, array $environment): Process
{
    $process = new Process(
        [base_path($script), ...$arguments],
        base_path(),
        array_merge(['PATH' => getenv('PATH')], $environment),
    );
    $process->setTimeout(30);
    $process->run();

    return $process;
}

test('production trust stays withheld until every current deployment drill has fresh proof', function () {
    $temporaryDirectory = productionTrustTemporaryDirectory();
    $deploymentStateDirectory = $temporaryDirectory.'/deployments';
    $evidenceDirectory = $temporaryDirectory.'/trust-evidence';
    $commandDirectory = $temporaryDirectory.'/commands';
    $now = 1785661200;
    mkdir($deploymentStateDirectory, 0700, true);
    mkdir($commandDirectory, 0700, true);
    file_put_contents($deploymentStateDirectory.'/current.json', json_encode([
        'application_image' => 'ghcr.io/rgomeztinoco/money-assistant@sha256:'.str_repeat('a', 64),
        'application_revision' => 'revision-under-test',
        'database_image' => 'postgres:18@sha256:'.str_repeat('d', 64),
        'operational_bundle_checksum' => str_repeat('e', 64),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $environment = [
        'DEPLOYMENT_STATE_DIRECTORY' => $deploymentStateDirectory,
        'PRODUCTION_TRUST_EVIDENCE_DIRECTORY' => $evidenceDirectory,
        'PRODUCTION_TRUST_COMMAND_DIRECTORY' => $commandDirectory,
        'PRODUCTION_TRUST_NOW_EPOCH' => (string) $now,
        'PRODUCTION_TRUST_TESTING' => 'true',
    ];
    $gateCommands = [
        'forced-crash' => 'rehearse-production-crash',
        'backup-retention' => 'pull-production-backup',
        'clean-restore' => 'restore-production-backup',
        'credential-rotation' => 'rehearse-all-credential-rotations',
        'independent-alert' => 'rehearse-independent-alert',
        'pinned-upgrade' => 'rehearse-production-upgrade',
        'ubuntu-security-updates' => 'verify-production-security-updates',
        'acceptance-matrix' => 'verify-production-acceptance-matrix',
    ];

    foreach (array_unique($gateCommands) as $command) {
        file_put_contents($commandDirectory.'/'.$command, <<<'SH'
#!/bin/sh
printf 'PRODUCTION_TRUST_EVIDENCE gate=%s outcome=passed\n' "$PRODUCTION_TRUST_GATE"
SH);
        chmod($commandDirectory.'/'.$command, 0700);
    }

    try {
        $unapprovedCommandDirectory = runProductionTrustScript(
            'production-trust-gate',
            ['verify'],
            [...$environment, 'PRODUCTION_TRUST_TESTING' => 'false'],
        );

        expect($unapprovedCommandDirectory->getExitCode())->toBe(1)
            ->and($unapprovedCommandDirectory->getErrorOutput())
            ->toContain('reserved for automated tests');

        $withheld = runProductionTrustScript('production-trust-gate', ['verify'], $environment);

        expect($withheld->getExitCode())->toBe(1)
            ->and($withheld->getOutput())
            ->toContain('Production trust: WITHHELD')
            ->and(file_get_contents($evidenceDirectory.'/status'))
            ->toBe("withheld revision-under-test {$now}\n");

        foreach (array_keys($gateCommands) as $gate) {
            expect($withheld->getOutput())->toContain($gate);
            $recorded = runProductionTrustScript(
                'production-trust-gate',
                ['run', $gate],
                $environment,
            );

            expect($recorded->getExitCode())->toBe(0);
        }

        $trusted = runProductionTrustScript('production-trust-gate', ['verify'], $environment);

        expect($trusted->getExitCode())->toBe(0)
            ->and($trusted->getOutput())
            ->toContain('Production trust: TRUSTED')
            ->toContain('revision-under-test')
            ->not->toContain('Drill passed')
            ->and(file_get_contents($evidenceDirectory.'/status'))
            ->toBe("trusted revision-under-test {$now}\n");

        $record = json_decode(
            file_get_contents($evidenceDirectory.'/acceptance-matrix.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($record)
            ->gate->toBe('acceptance-matrix')
            ->application_revision->toBe('revision-under-test')
            ->database_image->toBe('postgres:18@sha256:'.str_repeat('d', 64))
            ->operational_bundle_checksum->toBe(str_repeat('e', 64))
            ->recorded_at_epoch->toBe($now)
            ->producer->toBe('verify-production-acceptance-matrix')
            ->proof_sha256->toBe(hash_file('sha256', $evidenceDirectory.'/acceptance-matrix.proof'));

        file_put_contents($evidenceDirectory.'/acceptance-matrix.proof', "tampered\n");
        $tamperedProof = runProductionTrustScript('production-trust-gate', ['verify'], $environment);

        expect($tamperedProof->getExitCode())->toBe(1)
            ->and($tamperedProof->getOutput())
            ->toContain('INVALID acceptance-matrix: retained proof digest does not match');

        expect(runProductionTrustScript(
            'production-trust-gate',
            ['run', 'acceptance-matrix'],
            $environment,
        )->getExitCode())->toBe(0);

        $selfAttestedProof = $temporaryDirectory.'/self-attested-proof.log';
        file_put_contents($selfAttestedProof, "PRODUCTION_TRUST_EVIDENCE gate=acceptance-matrix outcome=passed\n");
        $selfAttested = runProductionTrustScript(
            'production-trust-gate',
            ['record', 'acceptance-matrix', $selfAttestedProof],
            $environment,
        );

        expect($selfAttested->getExitCode())->toBe(1)
            ->and($selfAttested->getErrorOutput())->toContain('unknown action: record');

        $activeDeployment = json_decode(
            file_get_contents($deploymentStateDirectory.'/current.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $activeDeployment['operational_bundle_checksum'] = str_repeat('f', 64);
        file_put_contents(
            $deploymentStateDirectory.'/current.json',
            json_encode($activeDeployment, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $changedBundle = runProductionTrustScript('production-trust-gate', ['verify'], $environment);

        expect($changedBundle->getExitCode())->toBe(1)
            ->and($changedBundle->getOutput())
            ->toContain('Production trust: WITHHELD')
            ->toContain('does not match the active deployment');

        file_put_contents($deploymentStateDirectory.'/current.json', json_encode([
            'application_image' => 'ghcr.io/rgomeztinoco/money-assistant@sha256:'.str_repeat('b', 64),
            'application_revision' => 'new-revision',
            'database_image' => 'postgres:18@sha256:'.str_repeat('d', 64),
            'operational_bundle_checksum' => str_repeat('f', 64),
        ], JSON_THROW_ON_ERROR));

        $changedDeployment = runProductionTrustScript('production-trust-gate', ['verify'], $environment);

        expect($changedDeployment->getExitCode())->toBe(1)
            ->and($changedDeployment->getOutput())
            ->toContain('Production trust: WITHHELD')
            ->toContain('does not match the active deployment');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('the Ubuntu security policy is daily, reboot bounded, and independently verifiable', function () {
    $configuration = file_get_contents(base_path('money-assistant-unattended-upgrades.conf'));
    $policy = file_get_contents(base_path('production-security-policy.env'));
    $installer = file_get_contents(base_path('install-production-security-updates'));
    $verifier = file_get_contents(base_path('verify-production-security-updates'));
    $criticalFixTracker = file_get_contents(base_path('track-critical-security-fixes'));

    expect($configuration)
        ->toContain('APT::Periodic::Update-Package-Lists "1";')
        ->toContain('APT::Periodic::Unattended-Upgrade "1";')
        ->toContain('Unattended-Upgrade::Automatic-Reboot "true";')
        ->toContain('Unattended-Upgrade::Automatic-Reboot-WithUsers "true";')
        ->toContain('Unattended-Upgrade::Automatic-Reboot-Time "03:30";')
        ->and($policy)
        ->toContain('AUTOMATIC_SECURITY_UPDATE_INTERVAL_DAYS=1')
        ->toContain('REBOOT_DEADLINE_DAYS=7')
        ->toContain('CRITICAL_REMOTE_FIX_DEADLINE_HOURS=72')
        ->toContain('PINNED_UPGRADE_REVIEW_INTERVAL_DAYS=31')
        ->and($installer)
        ->toContain('apt-get install --yes unattended-upgrades update-notifier-common')
        ->toContain('money-assistant-unattended-upgrades.conf')
        ->toContain('systemctl enable --now apt-daily.timer apt-daily-upgrade.timer')
        ->toContain('verify-production-security-updates')
        ->and($verifier)
        ->toContain('apt-config dump')
        ->toContain('unattended-upgrade --dry-run --debug')
        ->toContain('apt-daily.timer apt-daily-upgrade.timer')
        ->toContain('CRITICAL_REMOTE_FIX_DEADLINE_HOURS')
        ->toContain('REBOOT_DEADLINE_DAYS')
        ->toContain('track-critical-security-fixes')
        ->and($criticalFixTracker)
        ->toContain('detected')
        ->toContain('resolved')
        ->toContain('deadline');
});

test('critical remotely exploitable fixes are tracked from detection through resolution within 72 hours', function () {
    $temporaryDirectory = productionTrustTemporaryDirectory();
    $ledger = $temporaryDirectory.'/critical-fixes';
    $now = 1785661200;
    $environment = [
        'CRITICAL_REMOTE_FIX_LEDGER' => $ledger,
        'CRITICAL_REMOTE_FIX_NOW_EPOCH' => (string) $now,
        'CRITICAL_REMOTE_FIX_DEADLINE_HOURS' => '72',
    ];

    try {
        $detected = runProductionTrustScript(
            'track-critical-security-fixes',
            ['detected', 'USN-9999-1', (string) ($now - 71 * 3600)],
            $environment,
        );
        $withinDeadline = runProductionTrustScript(
            'track-critical-security-fixes',
            ['verify'],
            $environment,
        );

        expect($detected->getExitCode())->toBe(0)
            ->and($withinDeadline->getExitCode())->toBe(0);

        $overdue = runProductionTrustScript(
            'track-critical-security-fixes',
            ['verify'],
            [...$environment, 'CRITICAL_REMOTE_FIX_NOW_EPOCH' => (string) ($now + 2 * 3600)],
        );

        expect($overdue->getExitCode())->toBe(1)
            ->and($overdue->getErrorOutput())->toContain('exceeded the 72-hour deadline');

        $resolved = runProductionTrustScript(
            'track-critical-security-fixes',
            ['resolved', 'USN-9999-1', (string) ($now - 1)],
            $environment,
        );
        $verified = runProductionTrustScript(
            'track-critical-security-fixes',
            ['verify'],
            $environment,
        );

        expect($resolved->getExitCode())->toBe(0)
            ->and($verified->getExitCode())->toBe(0)
            ->and(file_get_contents($ledger))->toContain("USN-9999-1\t".($now - 71 * 3600)."\t".($now - 1));
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('a pinned upgrade rehearsal requires review and fresh backup evidence before isolated migration and health checks', function () {
    $temporaryDirectory = productionTrustTemporaryDirectory();
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $commandLog = $temporaryDirectory.'/commands.log';
    $deploymentStateDirectory = $temporaryDirectory.'/deployments';
    $backupStatus = $temporaryDirectory.'/backup-status';
    $dependencyReview = $temporaryDirectory.'/dependency-review.txt';
    $acceptanceMatrix = $temporaryDirectory.'/acceptance-matrix.txt';
    $rollbackPlan = $temporaryDirectory.'/rollback-plan.txt';
    $now = 1785661200;
    mkdir($fakeBinaryDirectory, 0700, true);
    mkdir($deploymentStateDirectory, 0700, true);
    file_put_contents($backupStatus, 'success '.($now - 3600)."\n");
    $candidate = 'ghcr.io/rgomeztinoco/money-assistant@sha256:'.str_repeat('c', 64);
    $databaseCandidate = 'postgres:18.5@sha256:'.str_repeat('e', 64);
    $rollbackCandidate = 'ghcr.io/rgomeztinoco/money-assistant@sha256:'.str_repeat('b', 64);
    $rollbackDatabase = 'postgres:18.4@sha256:'.str_repeat('f', 64);
    file_put_contents($deploymentStateDirectory.'/current.json', json_encode([
        'application_image' => $candidate,
        'database_image' => $databaseCandidate,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    file_put_contents($dependencyReview, "DEPENDENCY_REVIEW outcome=passed application_image={$candidate} database_image={$databaseCandidate} rollback_application_image={$rollbackCandidate} rollback_database_image={$rollbackDatabase}\n");
    file_put_contents($acceptanceMatrix, "ACCEPTANCE_MATRIX outcome=passed application_image={$candidate}\n");
    file_put_contents($rollbackPlan, "ROLLBACK_PLAN application_image={$rollbackCandidate} database_image={$rollbackDatabase}\nRestore the prior pinned release after a failed health check.\n");
    file_put_contents($fakeBinaryDirectory.'/restore-production-backup', <<<'SH'
#!/bin/sh
printf 'candidate=%s database=%s args=%s\n' "$RECOVERY_APPLICATION_IMAGE_OVERRIDE" "$RECOVERY_POSTGRES_IMAGE_OVERRIDE" "$*" >> "$UPGRADE_TEST_COMMAND_LOG"
printf '%s\n' 'Isolated recovery verification completed in 9 seconds.'
SH);
    chmod($fakeBinaryDirectory.'/restore-production-backup', 0700);
    $environment = [
        'BACKUP_STATUS_FILE' => $backupStatus,
        'DEPLOYMENT_STATE_DIRECTORY' => $deploymentStateDirectory,
        'PRODUCTION_UPGRADE_NOW_EPOCH' => (string) $now,
        'RESTORE_PRODUCTION_BACKUP' => $fakeBinaryDirectory.'/restore-production-backup',
        'UPGRADE_TEST_COMMAND_LOG' => $commandLog,
    ];

    try {
        $rehearsal = runProductionTrustScript('rehearse-production-upgrade', [
            $candidate,
            '--database-image', $databaseCandidate,
            '--rollback-app-image', $rollbackCandidate,
            '--rollback-database-image', $rollbackDatabase,
            '--rollback-plan', $rollbackPlan,
            '--dependency-review', $dependencyReview,
            '--acceptance-matrix', $acceptanceMatrix,
        ], $environment);

        expect($rehearsal->getExitCode())->toBe(
            0,
            $rehearsal->getErrorOutput().$rehearsal->getOutput(),
        )
            ->and($rehearsal->getOutput())
            ->toContain('Pinned upgrade rehearsal passed')
            ->and(file_get_contents($commandLog))
            ->toContain('candidate='.$candidate)
            ->toContain('database='.$databaseCandidate)
            ->toContain('args=latest')
            ->toContain('candidate='.$rollbackCandidate)
            ->toContain('database='.$rollbackDatabase)
            ->and(substr_count(file_get_contents($commandLog), "args=latest\n"))->toBe(2)
            ->and($rehearsal->getOutput())
            ->not->toContain(file_get_contents($dependencyReview));

        file_put_contents($backupStatus, 'success '.($now - 129601)."\n");
        $staleBackup = runProductionTrustScript('rehearse-production-upgrade', [
            $candidate,
            '--database-image', $databaseCandidate,
            '--rollback-app-image', $rollbackCandidate,
            '--rollback-database-image', $rollbackDatabase,
            '--rollback-plan', $rollbackPlan,
            '--dependency-review', $dependencyReview,
            '--acceptance-matrix', $acceptanceMatrix,
        ], $environment);

        expect($staleBackup->getExitCode())->toBe(1)
            ->and($staleBackup->getErrorOutput())->toContain('fresh encrypted backup');

        $unpinned = runProductionTrustScript('rehearse-production-upgrade', [
            'ghcr.io/rgomeztinoco/money-assistant:latest',
            '--database-image', $databaseCandidate,
            '--rollback-app-image', $rollbackCandidate,
            '--rollback-database-image', $rollbackDatabase,
            '--rollback-plan', $rollbackPlan,
            '--dependency-review', $dependencyReview,
            '--acceptance-matrix', $acceptanceMatrix,
        ], $environment);

        expect($unpinned->getExitCode())->toBe(1)
            ->and($unpinned->getErrorOutput())->toContain('repository and sha256 digest');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('approved target drills execute independent delivery and the complete acceptance matrix', function () {
    $independentAlert = file_get_contents(base_path('rehearse-independent-alert'));
    $acceptanceMatrix = file_get_contents(base_path('verify-production-acceptance-matrix'));
    $credentialRotation = file_get_contents(base_path('rehearse-all-credential-rotations'));

    expect($independentAlert)
        ->toContain('MONITOR_DIRECT_DELIVERY_COMMAND')
        ->toContain('MONITOR_DELIVERY_COMMAND')
        ->toContain('monitor-independent-heartbeat')
        ->toContain('host=failed application=failed')
        ->toContain('host=healthy application=healthy')
        ->toContain('PRODUCTION_TRUST_EVIDENCE gate=independent-alert outcome=passed')
        ->and($acceptanceMatrix)
        ->toContain('git rev-parse HEAD')
        ->toContain('docker image inspect')
        ->toContain('application_image_id')
        ->toContain('/usr/local/sbin/verify-production-deployment')
        ->toContain('vendor/bin/sail composer ci:check')
        ->toContain('vendor/bin/sail npm run build')
        ->toContain('PRODUCTION_TRUST_EVIDENCE gate=acceptance-matrix outcome=passed');
    expect($credentialRotation)
        ->toContain('"$rotation_command" application')
        ->toContain('PRODUCTION_TRUST_EVIDENCE gate=credential-rotation outcome=passed');
});

test('the forced crash drill kills in-flight processing and verifies catch-up without financial effects', function () {
    $rehearsal = file_get_contents(base_path('rehearse-production-crash'));

    expect($rehearsal)
        ->toContain('--hold-for-crash')
        ->toContain('Crash start marker:')
        ->toContain('docker kill --signal KILL')
        ->toContain('app:deployment-rehearsal:verify')
        ->toContain('one voided rehearsal Transaction')
        ->toContain('completed each durable probe exactly once')
        ->toContain('PRODUCTION_TRUST_EVIDENCE gate=forced-crash outcome=passed');
});
