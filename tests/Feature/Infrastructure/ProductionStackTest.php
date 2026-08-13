<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->productionCompose = Yaml::parseFile(base_path('compose.production.yaml'));
});

test('production Compose retains the application database and private proxy roles', function (): void {
    $services = $this->productionCompose['services'];

    expect(array_keys($services))->toBe([
        'postgres', 'migrate', 'web', 'worker', 'scheduler', 'proxy',
    ]);

    foreach (['web', 'worker', 'scheduler', 'postgres', 'proxy'] as $service) {
        expect($services[$service]['restart'])->toBe('unless-stopped')
            ->and($services[$service]['healthcheck']['test'])->toBeArray()->not->toBeEmpty();
    }

    expect($services['migrate']['command'])
        ->toContain('migrate', '--force', '--isolated', '--no-interaction')
        ->and($services['web']['command'])->toContain('frankenphp')
        ->and($services['worker']['command'])->toContain('queue:work')
        ->and($services['scheduler']['command'])->toContain('schedule:work');
});

test('production Compose builds one hardened application image', function (): void {
    $services = $this->productionCompose['services'];

    foreach (['migrate', 'web', 'worker', 'scheduler'] as $service) {
        expect($services[$service]['image'])->toBe('${APP_IMAGE:-money-assistant:production}')
            ->and($services[$service]['build'])->toBe(['context' => '.', 'target' => 'production'])
            ->and($services[$service]['read_only'])->toBeTrue()
            ->and($services[$service]['security_opt'])->toContain('no-new-privileges:true')
            ->and($services[$service]['cap_drop'])->toBe(['ALL'])
            ->and($services[$service]['environment']['APP_ENV'])->toBe('production')
            ->and($services[$service]['environment']['APP_DEBUG'])->toBe('false');
    }

    expect($services['web']['secrets'])->toContain('google_gmail_client_secret')
        ->and($services['worker']['secrets'])->toContain('google_gmail_client_secret')
        ->and($services['scheduler']['secrets'])->not->toContain('google_gmail_client_secret')
        ->and(file_get_contents(base_path('docker-entrypoint.production')))
        ->toContain('read_secret APP_KEY')
        ->toContain('read_secret DB_PASSWORD')
        ->toContain('read_secret GOOGLE_GMAIL_CLIENT_SECRET');
});

test('only the private proxy publishes a loopback port', function (): void {
    $services = $this->productionCompose['services'];

    expect($services['proxy']['ports'])->toBe([[
        'target' => 8080,
        'published' => 8443,
        'host_ip' => '127.0.0.1',
        'protocol' => 'tcp',
    ]]);

    foreach (['postgres', 'migrate', 'web', 'worker', 'scheduler'] as $service) {
        expect($services[$service])->not->toHaveKey('ports');
    }

    expect($this->productionCompose['networks']['application']['driver'])->toBe('bridge')
        ->and(file_get_contents(base_path('Caddyfile.production')))->toContain('reverse_proxy web:8080')
        ->and(file_get_contents(base_path('money-assistant-tailnet.service')))
        ->toContain('tailscale serve --bg --https=8443 http://127.0.0.1:8443')
        ->and(file_get_contents(base_path('verify-private-ingress')))
        ->toContain('tailscale funnel status --json');
});

test('deployment builds migrates and starts the healthy topology in order', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-deploy-'.str()->uuid();
    $binaryDirectory = $temporaryDirectory.'/bin';
    $environmentFile = $temporaryDirectory.'/production.env';
    $commandLog = $temporaryDirectory.'/commands.log';
    mkdir($binaryDirectory, 0700, true);
    file_put_contents($environmentFile, "PRIVATE_HOSTNAME=money-assistant.example.ts.net\n");
    file_put_contents($binaryDirectory.'/docker', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$DEPLOYMENT_TEST_COMMAND_LOG"

if [ "${DEPLOYMENT_TEST_FAIL_MIGRATION:-false}" = true ] && printf '%s' "$*" | grep -q 'run --rm migrate'; then
    exit 17
fi
SH);
    chmod($binaryDirectory.'/docker', 0700);

    try {
        $environment = [
            'COMPOSE_FILE' => base_path('compose.production.yaml'),
            'DEPLOYMENT_LOCK_FILE' => $temporaryDirectory.'/deployment.lock',
            'DEPLOYMENT_TEST_COMMAND_LOG' => $commandLog,
            'ENVIRONMENT_FILE' => $environmentFile,
            'PATH' => $binaryDirectory.':'.getenv('PATH'),
        ];
        $deployment = new Process([base_path('deploy-production')], base_path(), $environment);
        $deployment->run();

        expect($deployment->getExitCode())->toBe(0, $deployment->getErrorOutput())
            ->and($deployment->getOutput())->toContain('Production deployment completed successfully.');

        $commands = file($commandLog, FILE_IGNORE_NEW_LINES);

        expect($commands)->toHaveCount(5)
            ->and($commands[0])->toContain('config --quiet')
            ->and($commands[1])->toContain('build --pull migrate')
            ->and($commands[2])->toContain('up --detach --wait postgres')
            ->and($commands[3])->toContain('run --rm migrate')
            ->and($commands[4])->toContain('up --detach --wait --remove-orphans web worker scheduler proxy');

        file_put_contents($commandLog, '');
        $failedDeployment = new Process([base_path('deploy-production')], base_path(), [
            ...$environment,
            'DEPLOYMENT_TEST_FAIL_MIGRATION' => 'true',
        ]);
        $failedDeployment->run();

        expect($failedDeployment->getExitCode())->toBe(17)
            ->and(file_get_contents($commandLog))->not->toContain('web worker scheduler proxy');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('systemd restores the deployment path before private ingress', function (): void {
    $productionService = file_get_contents(base_path('money-assistant-production.service'));
    $tailnetService = file_get_contents(base_path('money-assistant-tailnet.service'));

    expect($productionService)
        ->toContain('WorkingDirectory=/opt/money-assistant')
        ->toContain('ExecStart=/opt/money-assistant/deploy-production')
        ->toContain('WantedBy=multi-user.target')
        ->and($tailnetService)
        ->toContain('Requires=money-assistant-production.service')
        ->toContain('After=money-assistant-production.service tailscale-online.target');
});
