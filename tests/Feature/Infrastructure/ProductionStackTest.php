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
        ->and(file_get_contents(base_path('production/docker-entrypoint.production')))
        ->toContain('read_secret APP_KEY')
        ->toContain('read_secret DB_PASSWORD')
        ->toContain('read_secret GOOGLE_GMAIL_CLIENT_SECRET');
});

test('only the private proxy publishes a loopback port', function (): void {
    $services = $this->productionCompose['services'];
    $productionEnvironment = parse_ini_file(base_path('.env.production.example'));
    $tailnetService = file_get_contents(base_path('production/money-assistant-tailnet.service'));

    expect($services['proxy']['ports'])->toBe([[
        'target' => 8080,
        'published' => 8443,
        'host_ip' => '127.0.0.1',
        'protocol' => 'tcp',
    ]]);

    foreach (['postgres', 'migrate', 'web', 'worker', 'scheduler'] as $service) {
        expect($services[$service])->not->toHaveKey('ports');
    }

    foreach (['migrate', 'web', 'worker', 'scheduler'] as $service) {
        expect($services[$service]['environment']['APP_URL'])
            ->toBe('${APP_URL:?Set APP_URL}')
            ->and($services[$service]['environment']['GOOGLE_GMAIL_REDIRECT_URI'])
            ->toBe('${GOOGLE_GMAIL_REDIRECT_URI:?Set GOOGLE_GMAIL_REDIRECT_URI}');
    }

    expect($productionEnvironment['PRIVATE_HOSTNAME'])->toBe('money-assistant.example.ts.net')
        ->and($productionEnvironment['APP_URL'])->toBe('https://money-assistant.example.ts.net')
        ->and($productionEnvironment['GOOGLE_GMAIL_REDIRECT_URI'])
        ->toBe('https://money-assistant.example.ts.net/settings/connections/gmail/callback')
        ->and($this->productionCompose['networks']['application']['driver'])->toBe('bridge')
        ->and(file_get_contents(base_path('production/Caddyfile.production')))
        ->toContain('reverse_proxy web:8080')
        ->toContain('header_up X-Forwarded-Proto https')
        ->and($tailnetService)
        ->toContain('tailscale serve --service=svc:money-assistant --https=443 http://127.0.0.1:8443')
        ->toContain('tailscale serve --service=svc:money-assistant --https=443 off')
        ->not->toContain('tailscale serve reset')
        ->and(file_get_contents(base_path('production/verify-private-ingress')))
        ->toContain('tailscale funnel status --json');
});

test('private ingress verifies the named service and rejects public or unhealthy routes', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-private-ingress-'.str()->uuid();
    $binaryDirectory = $temporaryDirectory.'/bin';
    $environmentFile = $temporaryDirectory.'/production.env';
    mkdir($binaryDirectory, 0700, true);
    file_put_contents($environmentFile, "PRIVATE_HOSTNAME=money-assistant.example.ts.net\n");
    file_put_contents($binaryDirectory.'/tailscale', <<<'SH'
#!/bin/sh
case "$1 $2" in
    'status --json')
        printf '%s\n' '{"BackendState":"Running","TailscaleIPs":["100.64.0.10","fd7a:115c:a1e0::10"]}'
        ;;
    'serve status')
        printf '%s\n' "$PRIVATE_INGRESS_TEST_SERVE_STATUS"
        ;;
    'funnel status')
        printf '%s\n' "$PRIVATE_INGRESS_TEST_FUNNEL_STATUS"
        ;;
esac
SH);
    file_put_contents($binaryDirectory.'/ufw', <<<'SH'
#!/bin/sh
case "$1 $2" in
    'status verbose')
        printf '%s\n' 'Status: active' 'Default: deny (incoming), allow (outgoing), disabled (routed)'
        ;;
    'show added')
        printf '%s\n' 'ufw allow in on tailscale0'
        ;;
esac
SH);
    file_put_contents($binaryDirectory.'/docker', <<<'SH'
#!/bin/sh
printf '%s\n' "$PRIVATE_INGRESS_TEST_COMPOSE_STATUS"
SH);
    file_put_contents($binaryDirectory.'/ss', <<<'SH'
#!/bin/sh
printf '%s\n' "$PRIVATE_INGRESS_TEST_LISTENERS"
SH);
    file_put_contents($binaryDirectory.'/curl', <<<'SH'
#!/bin/sh
[ "$*" = '--fail --silent --show-error https://money-assistant.example.ts.net/up' ] || exit 64
[ "${PRIVATE_INGRESS_TEST_HEALTHY:-true}" = true ]
SH);
    file_put_contents($binaryDirectory.'/id', <<<'SH'
#!/bin/sh
printf '%s\n' '0'
SH);
    file_put_contents($binaryDirectory.'/jq', <<<'PHP'
#!/usr/bin/env php
<?php

$arguments = array_slice($argv, 1);
$variables = [];

for ($index = 0; $index < count($arguments); $index++) {
    if ($arguments[$index] === '--arg') {
        $variables[$arguments[$index + 1]] = $arguments[$index + 2];
        $index += 2;
    }
}

$filter = end($arguments);
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

if (str_contains($filter, 'TailscaleIPs')) {
    echo implode(' ', $input['TailscaleIPs']).PHP_EOL;
    exit(0);
}

if (str_contains($filter, '.BackendState == "Running"')) {
    exit($input['BackendState'] === 'Running' ? 0 : 1);
}

if (str_contains($filter, '.Services[$service]')) {
    $service = $input['Services'][$variables['service']] ?? [];
    $handlers = $service['Web'][$variables['host_port']]['Handlers'] ?? [];
    $valid = ($service['TCP']['443']['HTTPS'] ?? false) === true
        && ($handlers['/']['Proxy'] ?? null) === 'http://127.0.0.1:8443'
        && array_keys($handlers) === ['/'];

    exit($valid ? 0 : 1);
}

if (str_contains($filter, 'AllowFunnel')) {
    $allowFunnel = $input['AllowFunnel'] ?? [];
    exit(count(array_filter($allowFunnel)) === 0 ? 0 : 1);
}

if (str_contains($filter, '.services.proxy.ports')) {
    $services = $input['services'];
    $valid = $services['proxy']['ports'] === [[
        'host_ip' => '127.0.0.1',
        'mode' => 'ingress',
        'protocol' => 'tcp',
        'published' => '8443',
        'target' => 8080,
    ]];

    foreach ($services as $name => $service) {
        if ($name !== 'proxy' && ($service['ports'] ?? []) !== []) {
            $valid = false;
        }
    }

    exit($valid ? 0 : 1);
}

exit(1);
PHP);

    foreach (['tailscale', 'ufw', 'docker', 'ss', 'curl', 'id', 'jq'] as $command) {
        chmod($binaryDirectory.'/'.$command, 0700);
    }

    try {
        $serveStatus = [
            'TCP' => ['8443' => ['HTTPS' => true]],
            'Web' => [
                'ricardo-server.example.ts.net:8443' => [
                    'Handlers' => ['/' => ['Proxy' => 'http://127.0.0.1:8443']],
                ],
            ],
            'Services' => [
                'svc:money-assistant' => [
                    'TCP' => ['443' => ['HTTPS' => true]],
                    'Web' => [
                        'money-assistant.example.ts.net:443' => [
                            'Handlers' => ['/' => ['Proxy' => 'http://127.0.0.1:8443']],
                        ],
                    ],
                ],
            ],
        ];
        $composeStatus = [
            'services' => [
                'postgres' => [],
                'migrate' => [],
                'web' => [],
                'worker' => [],
                'scheduler' => [],
                'proxy' => [
                    'ports' => [[
                        'host_ip' => '127.0.0.1',
                        'mode' => 'ingress',
                        'protocol' => 'tcp',
                        'published' => '8443',
                        'target' => 8080,
                    ]],
                ],
            ],
        ];
        $environment = [
            'PATH' => $binaryDirectory.':'.getenv('PATH'),
            'PRIVATE_INGRESS_TEST_COMPOSE_STATUS' => json_encode($composeStatus, JSON_THROW_ON_ERROR),
            'PRIVATE_INGRESS_TEST_FUNNEL_STATUS' => json_encode([
                'AllowFunnel' => ['ricardo-server.example.ts.net:8443' => false],
            ], JSON_THROW_ON_ERROR),
            'PRIVATE_INGRESS_TEST_LISTENERS' => implode("\n", [
                'LISTEN 0 4096 127.0.0.1:8443 0.0.0.0:*',
                'LISTEN 0 4096 127.0.0.1:8080 0.0.0.0:*',
                'LISTEN 0 4096 100.64.0.10:443 0.0.0.0:*',
                'LISTEN 0 4096 100.64.0.10:8443 0.0.0.0:*',
                'LISTEN 0 4096 [fd7a:115c:a1e0::10]:8443 [::]:*',
            ]),
            'PRIVATE_INGRESS_TEST_SERVE_STATUS' => json_encode($serveStatus, JSON_THROW_ON_ERROR),
        ];
        $approvedIngress = new Process([
            base_path('production/verify-private-ingress'),
            $environmentFile,
        ], base_path(), $environment);
        $approvedIngress->run();

        expect($approvedIngress->getExitCode())->toBe(0, $approvedIngress->getErrorOutput())
            ->and($approvedIngress->getOutput())->toContain('Private ingress verification passed.');

        $missingServiceStatus = $serveStatus;
        unset($missingServiceStatus['Services']);

        $wrongBackendStatus = $serveStatus;
        $wrongBackendStatus['Services']['svc:money-assistant']['Web']['money-assistant.example.ts.net:443']['Handlers']['/']['Proxy'] = 'http://127.0.0.1:8444';

        $publicComposeStatus = $composeStatus;
        $publicComposeStatus['services']['proxy']['ports'][0]['host_ip'] = '0.0.0.0';

        $rejections = [
            'missing named service' => [
                ['PRIVATE_INGRESS_TEST_SERVE_STATUS' => json_encode($missingServiceStatus, JSON_THROW_ON_ERROR)],
                'the named Tailscale Service does not exclusively route HTTPS to the loopback proxy',
            ],
            'incorrect backend target' => [
                ['PRIVATE_INGRESS_TEST_SERVE_STATUS' => json_encode($wrongBackendStatus, JSON_THROW_ON_ERROR)],
                'the named Tailscale Service does not exclusively route HTTPS to the loopback proxy',
            ],
            'Funnel-enabled ingress' => [
                ['PRIVATE_INGRESS_TEST_FUNNEL_STATUS' => json_encode([
                    'AllowFunnel' => ['money-assistant.example.ts.net:443' => true],
                ], JSON_THROW_ON_ERROR)],
                'Tailscale Funnel must remain disabled',
            ],
            'non-loopback Docker publication' => [
                ['PRIVATE_INGRESS_TEST_COMPOSE_STATUS' => json_encode($publicComposeStatus, JSON_THROW_ON_ERROR)],
                'Compose publishes an application port outside loopback',
            ],
            'failed canonical health check' => [
                ['PRIVATE_INGRESS_TEST_HEALTHY' => 'false'],
                'canonical tailnet HTTPS health probe failed',
            ],
        ];

        foreach ($rejections as [$environmentOverrides, $failureMessage]) {
            $rejectedIngress = new Process([
                base_path('production/verify-private-ingress'),
                $environmentFile,
            ], base_path(), [
                ...$environment,
                ...$environmentOverrides,
            ]);
            $rejectedIngress->run();

            expect($rejectedIngress->getExitCode())->toBe(1)
                ->and($rejectedIngress->getErrorOutput())->toContain($failureMessage);
        }

        $lanIngress = new Process([
            base_path('production/verify-private-ingress'),
            $environmentFile,
        ], base_path(), [
            ...$environment,
            'PRIVATE_INGRESS_TEST_LISTENERS' => 'LISTEN 0 4096 192.168.1.50:443 0.0.0.0:*',
        ]);
        $lanIngress->run();

        expect($lanIngress->getExitCode())->toBe(1)
            ->and($lanIngress->getErrorOutput())
            ->toContain('an application listener is reachable outside its approved private interface');

        $publicDevelopmentIngress = new Process([
            base_path('production/verify-private-ingress'),
            $environmentFile,
        ], base_path(), [
            ...$environment,
            'PRIVATE_INGRESS_TEST_LISTENERS' => 'LISTEN 0 4096 0.0.0.0:8080 0.0.0.0:*',
        ]);
        $publicDevelopmentIngress->run();

        expect($publicDevelopmentIngress->getExitCode())->toBe(1)
            ->and($publicDevelopmentIngress->getErrorOutput())
            ->toContain('an application listener is reachable outside its approved private interface');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
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

if [ "${DEPLOYMENT_TEST_FAIL_MIGRATION:-false}" = true ] && printf '%s' "$*" | grep -q 'run --rm --no-deps migrate'; then
    exit 17
fi
SH);
    chmod($binaryDirectory.'/docker', 0700);

    try {
        $environment = [
            'COMPOSE_FILE' => false,
            'DEPLOYMENT_LOCK_FILE' => $temporaryDirectory.'/deployment.lock',
            'DEPLOYMENT_TEST_COMMAND_LOG' => $commandLog,
            'ENVIRONMENT_FILE' => $environmentFile,
            'PATH' => $binaryDirectory.':'.getenv('PATH'),
        ];
        $deployment = new Process([base_path('production/deploy-production')], $temporaryDirectory, $environment);
        $deployment->run();

        expect($deployment->getExitCode())->toBe(0, $deployment->getErrorOutput())
            ->and($deployment->getOutput())->toContain('Production deployment completed successfully.');

        $commands = file($commandLog, FILE_IGNORE_NEW_LINES);

        expect($commands)->toHaveCount(5)
            ->and($commands[0])->toContain('--file '.base_path('production/../compose.production.yaml'), 'config --quiet')
            ->and($commands[1])->toContain('build --pull migrate')
            ->and($commands[2])->toContain('up --detach --wait postgres')
            ->and($commands[3])->toContain('run --rm --no-deps migrate')
            ->and($commands[4])
            ->toContain('up --detach --wait --remove-orphans --force-recreate --no-deps web worker scheduler proxy');

        file_put_contents($commandLog, '');
        $failedDeployment = new Process([base_path('production/deploy-production')], $temporaryDirectory, [
            ...$environment,
            'DEPLOYMENT_TEST_FAIL_MIGRATION' => 'true',
        ]);
        $failedDeployment->run();

        expect($failedDeployment->getExitCode())->toBe(17)
            ->and($failedDeployment->getOutput())->not->toContain('completed successfully')
            ->and(file_get_contents($commandLog))->not->toContain('web worker scheduler proxy');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('deployment reads its environment from the host-managed configuration directory', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/money-assistant-deploy-environment-'.str()->uuid();
    $binaryDirectory = $temporaryDirectory.'/bin';
    $commandLog = $temporaryDirectory.'/commands.log';
    $configurationDirectory = $temporaryDirectory.'/configuration';
    $environmentFile = $configurationDirectory.'/production.env';

    mkdir($binaryDirectory, 0700, true);
    mkdir($configurationDirectory, 0700, true);
    file_put_contents($environmentFile, "PRIVATE_HOSTNAME=money-assistant.example.ts.net\n");
    file_put_contents($binaryDirectory.'/docker', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$DEPLOYMENT_TEST_COMMAND_LOG"
SH);
    chmod($binaryDirectory.'/docker', 0700);

    try {
        $deployment = new Process([base_path('production/deploy-production')], $temporaryDirectory, [
            'COMPOSE_FILE' => false,
            'DEPLOYMENT_LOCK_FILE' => $temporaryDirectory.'/deployment.lock',
            'DEPLOYMENT_TEST_COMMAND_LOG' => $commandLog,
            'MONEY_ASSISTANT_CONFIGURATION_DIRECTORY' => $configurationDirectory,
            'PATH' => $binaryDirectory.':'.getenv('PATH'),
        ]);
        $deployment->run();

        expect($deployment->getExitCode())->toBe(0, $deployment->getErrorOutput())
            ->and(file_get_contents($commandLog))
            ->toContain('--env-file '.$environmentFile);
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

test('systemd restores the deployment path before private ingress', function (): void {
    $productionService = file_get_contents(base_path('production/money-assistant-production.service'));
    $tailnetService = file_get_contents(base_path('production/money-assistant-tailnet.service'));

    expect($productionService)
        ->toContain('WorkingDirectory=/opt/money-assistant')
        ->toContain('ExecStart=/opt/money-assistant/production/deploy-production')
        ->toContain('WantedBy=multi-user.target')
        ->and($tailnetService)
        ->toContain('Requires=money-assistant-production.service')
        ->toContain('After=money-assistant-production.service tailscale-online.target');
});
