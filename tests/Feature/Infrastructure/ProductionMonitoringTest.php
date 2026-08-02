<?php

use App\IntegrationService;
use App\Models\GmailConnection;
use App\Models\IntegrationIncident;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

function monitoringTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir().'/money-assistant-monitoring-'.str()->uuid();
    mkdir($directory, 0700, true);

    return $directory;
}

/** @param array<string, string> $environment */
function runMonitoringScript(string $script, array $environment, string $input = ''): Process
{
    $process = new Process(
        [base_path($script)],
        base_path(),
        array_merge(['PATH' => getenv('PATH')], $environment),
        $input,
    );
    $process->setTimeout(30);
    $process->run();

    return $process;
}

function installFakeMonitoringCommand(
    string $directory,
    string $name,
    string $contents,
): void {
    file_put_contents($directory.'/'.$name, "#!/bin/sh\nset -eu\n".$contents);
    chmod($directory.'/'.$name, 0700);
}

test('primary alerts are deduplicated, reminded daily, and followed by recovery', function () {
    $temporaryDirectory = monitoringTemporaryDirectory();
    $stateDirectory = $temporaryDirectory.'/state';
    $deliveryLog = $temporaryDirectory.'/deliveries.log';
    $deliveryCommand = $temporaryDirectory.'/deliver';
    mkdir($stateDirectory, 0700, true);
    installFakeMonitoringCommand(
        $temporaryDirectory,
        'deliver',
        <<<'SH'
printf '%s\n' "$1" >> "$MONITOR_TEST_DELIVERY_LOG"
SH,
    );

    $failed = "gmail\twarning\tfailed\t900\tGmail synchronization has been unhealthy for 15 minutes.\n";
    $healthy = "gmail\twarning\thealthy\t900\tGmail synchronization is healthy.\n";
    $environment = [
        'MONITOR_DELIVERY_COMMAND' => $deliveryCommand,
        'MONITOR_STATE_DIRECTORY' => $stateDirectory,
        'MONITOR_TEST_DELIVERY_LOG' => $deliveryLog,
    ];

    try {
        expect(runMonitoringScript('evaluate-monitoring-incidents', [
            ...$environment,
            'MONITOR_NOW_EPOCH' => '1000',
        ], $failed)->getExitCode())->toBe(0)
            ->and(file_exists($deliveryLog))->toBeFalse();

        runMonitoringScript('evaluate-monitoring-incidents', [
            ...$environment,
            'MONITOR_NOW_EPOCH' => '1899',
        ], $failed);
        expect(file_exists($deliveryLog))->toBeFalse();

        runMonitoringScript('evaluate-monitoring-incidents', [
            ...$environment,
            'MONITOR_NOW_EPOCH' => '1900',
        ], $failed);
        expect(file_get_contents($deliveryLog))
            ->toBe("[Money Assistant][warning] Gmail synchronization has been unhealthy for 15 minutes.\n");

        runMonitoringScript('evaluate-monitoring-incidents', [
            ...$environment,
            'MONITOR_NOW_EPOCH' => '2000',
        ], $failed);
        expect(substr_count(file_get_contents($deliveryLog), "\n"))->toBe(1);

        runMonitoringScript('evaluate-monitoring-incidents', [
            ...$environment,
            'MONITOR_NOW_EPOCH' => (string) (1900 + 86_400),
        ], $failed);
        expect(file_get_contents($deliveryLog))
            ->toContain('[Money Assistant][reminder][warning]')
            ->and(substr_count(file_get_contents($deliveryLog), "\n"))->toBe(2);

        runMonitoringScript('evaluate-monitoring-incidents', [
            ...$environment,
            'MONITOR_NOW_EPOCH' => (string) (1901 + 86_400),
        ], $healthy);
        expect(file_get_contents($deliveryLog))
            ->toContain('[Money Assistant][recovered] Gmail synchronization is healthy.')
            ->and(substr_count(file_get_contents($deliveryLog), "\n"))->toBe(3);
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('the second device alerts directly after five missing heartbeat minutes', function () {
    $temporaryDirectory = monitoringTemporaryDirectory();
    $stateDirectory = $temporaryDirectory.'/state';
    $requestConfig = $temporaryDirectory.'/telegram-requests.config';
    $primaryDeliveryLog = $temporaryDirectory.'/primary-deliveries.log';
    $tokenFile = $temporaryDirectory.'/telegram-token';
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $probeCommand = $temporaryDirectory.'/probe';
    $primaryDeliveryCommand = $temporaryDirectory.'/primary-delivery';
    mkdir($stateDirectory, 0700, true);
    mkdir($fakeBinaryDirectory, 0700, true);
    file_put_contents($tokenFile, 'independent-secret-token');
    installFakeMonitoringCommand($temporaryDirectory, 'probe', <<<'SH'
printf '%s\n' "${MONITOR_TEST_HEARTBEAT:-host=failed application=failed openclaw=failed}"
SH);
    installFakeMonitoringCommand($fakeBinaryDirectory, 'curl', <<<'SH'
/bin/cat >> "$MONITOR_TEST_REQUEST_CONFIG"
SH);
    installFakeMonitoringCommand($temporaryDirectory, 'primary-delivery', <<<'SH'
printf '%s\n' "$1" >> "$MONITOR_TEST_PRIMARY_DELIVERY_LOG"
SH);
    $environment = [
        'HEARTBEAT_PROBE_COMMAND' => $probeCommand,
        'MONITOR_DELIVERY_COMMAND' => base_path('deliver-independent-monitor-alert'),
        'MONITOR_PRIMARY_DELIVERY_COMMAND' => $primaryDeliveryCommand,
        'MONITOR_DIRECT_DELIVERY_COMMAND' => base_path('deliver-telegram-monitor-alert'),
        'MONITOR_STATE_DIRECTORY' => $stateDirectory,
        'MONITOR_TELEGRAM_BOT_TOKEN_FILE' => $tokenFile,
        'MONITOR_TELEGRAM_CHAT_ID' => 'owner-chat-id',
        'MONITOR_TEST_REQUEST_CONFIG' => $requestConfig,
        'MONITOR_TEST_PRIMARY_DELIVERY_LOG' => $primaryDeliveryLog,
        'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
    ];

    try {
        runMonitoringScript('monitor-independent-heartbeat', [
            ...$environment,
            'MONITOR_NOW_EPOCH' => '1000',
        ]);
        runMonitoringScript('monitor-independent-heartbeat', [
            ...$environment,
            'MONITOR_NOW_EPOCH' => '1299',
        ]);
        expect(file_exists($requestConfig))->toBeFalse();

        runMonitoringScript('monitor-independent-heartbeat', [
            ...$environment,
            'MONITOR_NOW_EPOCH' => '1300',
        ]);
        expect(file_get_contents($requestConfig))
            ->toContain('Host heartbeat has been absent for five minutes.')
            ->toContain('Application heartbeat has been absent for five minutes.')
            ->toContain('OpenClaw heartbeat has been absent for five minutes.')
            ->toContain('independent-secret-token')
            ->and(substr_count(file_get_contents($requestConfig), 'url ='))->toBe(3)
            ->and(file_exists($primaryDeliveryLog))->toBeFalse();

        runMonitoringScript('monitor-independent-heartbeat', [
            ...$environment,
            'MONITOR_NOW_EPOCH' => '1400',
            'MONITOR_TEST_HEARTBEAT' => 'host=healthy application=healthy openclaw=healthy',
        ]);
        expect(file_get_contents($requestConfig))
            ->toContain('[Money Assistant][recovered] Host heartbeat is healthy.')
            ->toContain('[Money Assistant][recovered] Application heartbeat is healthy.')
            ->toContain('[Money Assistant][recovered] OpenClaw heartbeat is healthy.')
            ->and(substr_count(file_get_contents($requestConfig), 'url ='))->toBe(6);
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('a failed restore uses the primary relay while the host is available', function () {
    $temporaryDirectory = monitoringTemporaryDirectory();
    $stateDirectory = $temporaryDirectory.'/state';
    $primaryDeliveryLog = $temporaryDirectory.'/primary-deliveries.log';
    $directDeliveryLog = $temporaryDirectory.'/direct-deliveries.log';
    $probeCommand = $temporaryDirectory.'/probe';
    $primaryDeliveryCommand = $temporaryDirectory.'/primary-delivery';
    $directDeliveryCommand = $temporaryDirectory.'/direct-delivery';
    $restoreStatus = $temporaryDirectory.'/restore-status';
    mkdir($stateDirectory, 0700, true);
    file_put_contents($restoreStatus, "failed 1000\n");
    installFakeMonitoringCommand($temporaryDirectory, 'probe', <<<'SH'
printf '%s\n' 'host=healthy application=healthy openclaw=healthy'
SH);
    installFakeMonitoringCommand($temporaryDirectory, 'primary-delivery', <<<'SH'
printf '%s\n' "$1" >> "$MONITOR_TEST_PRIMARY_DELIVERY_LOG"
SH);
    installFakeMonitoringCommand($temporaryDirectory, 'direct-delivery', <<<'SH'
printf '%s\n' "$1" >> "$MONITOR_TEST_DIRECT_DELIVERY_LOG"
SH);

    try {
        $process = runMonitoringScript('monitor-independent-heartbeat', [
            'HEARTBEAT_PROBE_COMMAND' => $probeCommand,
            'MONITOR_DELIVERY_COMMAND' => base_path('deliver-independent-monitor-alert'),
            'MONITOR_DIRECT_DELIVERY_COMMAND' => $directDeliveryCommand,
            'MONITOR_NOW_EPOCH' => '1000',
            'MONITOR_PRIMARY_DELIVERY_COMMAND' => $primaryDeliveryCommand,
            'MONITOR_RESTORE_STATUS_FILE' => $restoreStatus,
            'MONITOR_STATE_DIRECTORY' => $stateDirectory,
            'MONITOR_TEST_DIRECT_DELIVERY_LOG' => $directDeliveryLog,
            'MONITOR_TEST_PRIMARY_DELIVERY_LOG' => $primaryDeliveryLog,
        ]);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_get_contents($primaryDeliveryLog))
            ->toContain('[Money Assistant][critical] The latest restore check failed.')
            ->and(file_exists($directDeliveryLog))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('the direct fallback keeps the Telegram token out of command arguments', function () {
    $temporaryDirectory = monitoringTemporaryDirectory();
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $commandLog = $temporaryDirectory.'/commands.log';
    $requestConfig = $temporaryDirectory.'/request.config';
    $tokenFile = $temporaryDirectory.'/telegram-token';
    mkdir($fakeBinaryDirectory, 0700, true);
    file_put_contents($tokenFile, 'secret-telegram-token');
    installFakeMonitoringCommand($fakeBinaryDirectory, 'curl', <<<'SH'
printf 'curl %s\n' "$*" >> "$MONITOR_TEST_COMMAND_LOG"
/bin/cat > "$MONITOR_TEST_REQUEST_CONFIG"
SH);

    try {
        $process = new Process(
            [base_path('deliver-telegram-monitor-alert'), 'Host unavailable'],
            base_path(),
            [
                'MONITOR_TELEGRAM_BOT_TOKEN_FILE' => $tokenFile,
                'MONITOR_TELEGRAM_CHAT_ID' => 'owner-chat-id',
                'MONITOR_TEST_COMMAND_LOG' => $commandLog,
                'MONITOR_TEST_REQUEST_CONFIG' => $requestConfig,
                'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
            ],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_get_contents($commandLog))
            ->toBe("curl --connect-timeout 3 --fail --max-time 5 --silent --show-error --config -\n")
            ->not->toContain('secret-telegram-token')
            ->and(file_get_contents($requestConfig))
            ->toContain('secret-telegram-token')
            ->toContain('chat_id=owner-chat-id')
            ->toContain('text=Host unavailable');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('a primary incident is delivered through the bound OpenClaw Telegram account', function () {
    $temporaryDirectory = monitoringTemporaryDirectory();
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $stateDirectory = $temporaryDirectory.'/state';
    $commandLog = $temporaryDirectory.'/commands.log';
    $monitorEnvironment = $temporaryDirectory.'/monitor.env';
    mkdir($fakeBinaryDirectory, 0700, true);
    mkdir($stateDirectory, 0700, true);
    file_put_contents($monitorEnvironment, implode("\n", [
        'MONITOR_DELIVERY_COMMAND='.base_path('deliver-openclaw-monitor-alert'),
        'MONITOR_OPENCLAW_USER=openclaw',
        'MONITOR_OPENCLAW_ACCOUNT_ID=money-assistant-owner',
        'MONITOR_OPENCLAW_OWNER_SENDER_ID=owner-chat-id',
        '',
    ]));
    installFakeMonitoringCommand($fakeBinaryDirectory, 'id', <<<'SH'
printf '%s\n' 1001
SH);
    installFakeMonitoringCommand($fakeBinaryDirectory, 'runuser', <<<'SH'
printf 'runuser %s\n' "$*" >> "$MONITOR_TEST_COMMAND_LOG"
SH);

    try {
        $process = runMonitoringScript('evaluate-monitoring-incidents', [
            'MONITOR_DELIVERY_COMMAND' => base_path('production-monitor-relay'),
            'MONITOR_ENVIRONMENT_FILE' => $monitorEnvironment,
            'MONITOR_NOW_EPOCH' => '1000',
            'MONITOR_STATE_DIRECTORY' => $stateDirectory,
            'MONITOR_TEST_COMMAND_LOG' => $commandLog,
            'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
        ], "application\tcritical\tfailed\t0\tApplication unavailable\n");

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_get_contents($commandLog))
            ->toContain('openclaw message send')
            ->toContain('--channel telegram')
            ->toContain('--account money-assistant-owner')
            ->toContain('--target owner-chat-id')
            ->toContain('--message [Money Assistant][critical] Application unavailable');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('the application snapshot reports Gmail stalls and repeated Owner login lockouts', function () {
    $owner = User::factory()->create();
    GmailConnection::factory()->for($owner, 'owner')->create([
        'last_successful_sync_at' => now()->subMinutes(16),
    ]);

    event(new Lockout(Request::create('/login', 'POST', [
        'email' => 'not-the-owner@example.com',
    ])));

    $this->artisan('app:monitor-snapshot')
        ->expectsOutputToContain("repeated_login\tcritical\thealthy\t0")
        ->assertSuccessful();

    event(new Lockout(Request::create('/login', 'POST', [
        'email' => $owner->email,
    ])));

    $this->artisan('app:monitor-snapshot')
        ->expectsOutputToContain("gmail\twarning\tfailed\t900")
        ->expectsOutputToContain("repeated_login\tcritical\tfailed\t0")
        ->assertSuccessful();

    event(new Login('web', $owner, false));

    $this->artisan('app:monitor-snapshot')
        ->expectsOutputToContain("repeated_login\tcritical\thealthy\t0")
        ->assertSuccessful();
});

test('repeated failed Owner logins create an actionable monitoring signal', function () {
    $owner = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), [
            'email' => $owner->email,
            'password' => 'wrong-password-'.$attempt,
        ])->assertSessionHasErrors('email');
    }

    $this->artisan('app:monitor-snapshot')
        ->expectsOutputToContain("repeated_login\tcritical\tfailed\t0")
        ->assertSuccessful();

    event(new Login('web', $owner, false));
});

test('the application snapshot reports stalled work and visible OpenClaw delivery failures', function () {
    $owner = User::factory()->create();
    GmailConnection::factory()->for($owner, 'owner')->create([
        'last_successful_sync_at' => now(),
    ]);
    IntegrationIncident::factory()->for($owner, 'owner')->create([
        'integration' => IntegrationService::OpenClaw,
        'visible_at' => now(),
    ]);
    IntegrationIncident::factory()->for($owner, 'owner')->create([
        'integration' => IntegrationService::Gmail,
        'visible_at' => now(),
    ]);
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subMinutes(15)->timestamp,
        'created_at' => now()->subMinutes(20)->timestamp,
    ]);

    $this->artisan('app:monitor-snapshot')
        ->expectsOutputToContain("gmail\twarning\tfailed\t0")
        ->expectsOutputToContain("openclaw_delivery\twarning\tfailed\t0")
        ->expectsOutputToContain("oldest_processing_item\twarning\tfailed\t0")
        ->assertSuccessful();
});

test('the production probe enforces backup disk and credential thresholds', function () {
    $temporaryDirectory = monitoringTemporaryDirectory();
    $fakeBinaryDirectory = $temporaryDirectory.'/bin';
    $backupStatus = $temporaryDirectory.'/backup-status';
    $credentialDeadlines = $temporaryDirectory.'/credential-deadlines';
    mkdir($fakeBinaryDirectory, 0700, true);
    installFakeMonitoringCommand($fakeBinaryDirectory, 'docker', <<<'SH'
case "$*" in
    *'ps --quiet'*) printf '%s\n' container-id ;;
    *'inspect --format'*) printf '%s\n' healthy ;;
    *'app:monitor-snapshot'*)
        printf 'gmail\twarning\thealthy\t900\tGmail synchronization is healthy.\n'
        printf 'openclaw_delivery\twarning\thealthy\t0\tOpenClaw outbound delivery is healthy.\n'
        printf 'oldest_processing_item\twarning\thealthy\t0\tQueued processing is current.\n'
        printf 'repeated_login\tcritical\thealthy\t0\tOwner Account login activity is healthy.\n'
        ;;
esac
SH);
    installFakeMonitoringCommand($fakeBinaryDirectory, 'id', <<<'SH'
printf '%s\n' 1001
SH);
    installFakeMonitoringCommand($fakeBinaryDirectory, 'runuser', <<<'SH'
exit 0
SH);
    installFakeMonitoringCommand($fakeBinaryDirectory, 'df', <<<'SH'
printf '%s\n' 'Filesystem 1024-blocks Used Available Capacity Mounted on'
printf '/dev/test 100 90 10 %s%% /var/lib/docker\n' "$MONITOR_TEST_DISK_PERCENT"
SH);

    try {
        file_put_contents($backupStatus, "success 870400\n");
        file_put_contents($credentialDeadlines, "gmail-oauth 2209600\n");
        $process = runMonitoringScript('production-monitor-probe', [
            'MONITOR_BACKUP_STATUS_FILE' => $backupStatus,
            'MONITOR_CREDENTIAL_DEADLINES_FILE' => $credentialDeadlines,
            'MONITOR_NOW_EPOCH' => '1000000',
            'MONITOR_TEST_DISK_PERCENT' => '90',
            'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
        ]);

        expect($process->getErrorOutput())->toBe('')
            ->and($process->getExitCode())->toBe(0)
            ->and($process->getOutput())
            ->toContain("backup\tcritical\tfailed\t0")
            ->toContain('older than 36 hours')
            ->toContain("disk\tcritical\tfailed\t0")
            ->toContain('at or above 90 percent')
            ->toContain("credential\twarning\tfailed\t0")
            ->toContain('expires within 14 days');

        file_put_contents($backupStatus, "success 870401\n");
        file_put_contents($credentialDeadlines, "gmail-oauth 2209601\n");
        $process = runMonitoringScript('production-monitor-probe', [
            'MONITOR_BACKUP_STATUS_FILE' => $backupStatus,
            'MONITOR_CREDENTIAL_DEADLINES_FILE' => $credentialDeadlines,
            'MONITOR_NOW_EPOCH' => '1000000',
            'MONITOR_TEST_DISK_PERCENT' => '80',
            'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
        ]);

        expect($process->getOutput())
            ->toContain("backup\tcritical\thealthy\t0")
            ->toContain("disk\twarning\tfailed\t0")
            ->toContain('at or above 80 percent')
            ->toContain("credential\twarning\thealthy\t0");

        $process = runMonitoringScript('production-monitor-probe', [
            'MONITOR_BACKUP_STATUS_FILE' => $backupStatus,
            'MONITOR_CREDENTIAL_DEADLINES_FILE' => $temporaryDirectory.'/missing-credential-deadlines',
            'MONITOR_NOW_EPOCH' => '1000000',
            'MONITOR_TEST_DISK_PERCENT' => '70',
            'PATH' => $fakeBinaryDirectory.':'.getenv('PATH'),
        ]);

        expect($process->getOutput())
            ->toContain("credential\tcritical\tfailed\t0")
            ->toContain('inventory is missing or invalid');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('production monitoring enforces the decided thresholds and independent service boundary', function () {
    $productionProbe = file_get_contents(base_path('production-monitor-probe'));
    $independentMonitor = file_get_contents(base_path('monitor-independent-heartbeat'));
    $heartbeat = file_get_contents(base_path('production-heartbeat'));
    $backupPull = file_get_contents(base_path('pull-production-backup'));
    $restore = file_get_contents(base_path('restore-production-backup'));
    $hostTimer = file_get_contents(base_path('money-assistant-monitor.timer'));
    $independentTimer = file_get_contents(base_path('money-assistant-heartbeat.timer'));
    $independentService = file_get_contents(base_path('money-assistant-heartbeat.service'));
    $primaryInstaller = file_get_contents(base_path('install-production-heartbeat'));
    $independentInstaller = file_get_contents(base_path('install-independent-monitoring'));
    $independentDelivery = file_get_contents(base_path('deliver-independent-monitor-alert'));
    $productionRequestRouter = file_get_contents(base_path('route-production-monitor-request'));

    expect($productionProbe)
        ->toContain('gmail\\twarning')
        ->toContain('129600')
        ->toContain('80')
        ->toContain('90')
        ->toContain('1209600')
        ->not->toContain("printf 'repeated_login\\tcritical\\thealthy")
        ->and($independentMonitor)->toContain('\\t300\\t')
        ->and($heartbeat)
        ->toContain('host=healthy')
        ->toContain('application=')
        ->toContain('openclaw=')
        ->not->toContain('database_password', 'application_key', 'HOOK_TOKEN')
        ->and($backupPull)->toContain('BACKUP_STATUS_FILE')
        ->and($restore)->toContain('RESTORE_STATUS_FILE:-/var/lib/money-assistant/monitor/restore-status')
        ->and($hostTimer)->toContain('OnUnitActiveSec=1m')
        ->and($independentTimer)->toContain('OnUnitActiveSec=1m')
        ->and($independentService)->toContain('TimeoutStartSec=75s')
        ->and($primaryInstaller)
        ->toContain('restrict,command="/usr/local/sbin/route-production-monitor-request"')
        ->not->toContain('monitor-telegram-bot-token')
        ->and($independentInstaller)
        ->toContain('MONITOR_TELEGRAM_BOT_TOKEN_FILE')
        ->toContain('APPLICATION_MONITOR_SSH_KEY_FILE')
        ->and($independentDelivery)
        ->toContain('MONITOR_PRIMARY_DELIVERY_COMMAND')
        ->toContain('MONITOR_DIRECT_DELIVERY_COMMAND')
        ->and($productionRequestRouter)
        ->toContain('heartbeat')
        ->toContain('alert')
        ->not->toContain('eval');
});
