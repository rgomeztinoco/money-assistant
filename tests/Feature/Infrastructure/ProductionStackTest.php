<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->productionCompose = Yaml::parseFile(base_path('compose.production.yaml'));
});

test('a clean host can run only immutable production services', function (): void {
    $services = $this->productionCompose['services'];

    expect($services)->toHaveKeys([
        'postgres',
        'migrate',
        'web',
        'worker',
        'scheduler',
        'proxy',
    ]);

    foreach ($services as $service) {
        expect($service)->not->toHaveKey('build');

        foreach ($service['volumes'] ?? [] as $volume) {
            expect($volume['type'])->not->toBe('bind');
        }
    }
});

test('web worker scheduler PostgreSQL and the private proxy publish health checks', function (): void {
    $services = $this->productionCompose['services'];

    foreach (['web', 'worker', 'scheduler', 'postgres', 'proxy'] as $service) {
        expect($services[$service]['healthcheck']['test'])
            ->toBeArray()
            ->not->toBeEmpty();
    }

    expect(implode(' ', $services['worker']['healthcheck']['test']))
        ->toContain('app:health-check worker')
        ->and(implode(' ', $services['scheduler']['healthcheck']['test']))
        ->toContain('app:health-check scheduler')
        ->and($services['proxy']['depends_on']['web']['condition'])
        ->toBe('service_healthy');
});

test('application roles share one pinned image and restart independently', function (): void {
    $services = $this->productionCompose['services'];
    $applicationImage = '${APP_IMAGE_REPOSITORY:?Set APP_IMAGE_REPOSITORY}@${APP_IMAGE_DIGEST:?Set APP_IMAGE_DIGEST}';

    foreach (['migrate', 'web', 'worker', 'scheduler'] as $service) {
        expect($services[$service]['image'])->toBe($applicationImage);
    }

    expect($services['web']['command'])->not->toBe($services['worker']['command'])
        ->and($services['worker']['command'])->not->toBe($services['scheduler']['command']);

    foreach (['web', 'worker', 'scheduler', 'postgres', 'proxy'] as $service) {
        expect($services[$service]['restart'])->toBe('unless-stopped');
    }
});

test('production permits the state-bound Gmail callback and mounts its OAuth secret only in the web role', function (): void {
    $services = $this->productionCompose['services'];
    $entrypoint = file_get_contents(base_path('docker-entrypoint.production'));

    expect($services['web']['environment']['APP_URL'])
        ->toBe('https://${PRIVATE_HOSTNAME:?Set PRIVATE_HOSTNAME}:8443')
        ->and($services['web']['environment']['SESSION_SAME_SITE'])->toBe('lax')
        ->and($services['web']['environment']['GOOGLE_GMAIL_OAUTH_PUBLISHING_STATUS'])->toBe('production')
        ->and($services['web']['environment']['GOOGLE_GMAIL_REDIRECT_URI'])
        ->toBe('https://${PRIVATE_HOSTNAME:?Set PRIVATE_HOSTNAME}:8443/settings/connections/gmail/callback')
        ->and($services['web']['environment']['GOOGLE_GMAIL_CLIENT_SECRET_FILE'])
        ->toBe('/run/secrets/google_gmail_client_secret')
        ->and($services['web']['secrets'])->toContain('google_gmail_client_secret')
        ->and($services['worker']['secrets'])->not->toContain('google_gmail_client_secret')
        ->and($services['scheduler']['secrets'])->not->toContain('google_gmail_client_secret')
        ->and($entrypoint)->toContain('read_secret GOOGLE_GMAIL_CLIENT_SECRET');
});

test('only PostgreSQL and the pinned reverse proxy expose loopback ports', function () {
    $services = $this->productionCompose['services'];
    $environment = file_get_contents(base_path('.env.production.example'));
    preg_match('/^POSTGRES_IMAGE=(.+)$/m', $environment, $postgresImage);

    expect($services['postgres']['image'])
        ->toBe('${POSTGRES_IMAGE:?Set POSTGRES_IMAGE to a pinned PostgreSQL image}')
        ->and($postgresImage[1] ?? null)->toMatch('/@sha256:[a-f0-9]{64}$/')
        ->and($services['proxy']['image'])->toMatch('/@sha256:[a-f0-9]{64}$/')
        ->and($services['proxy']['ports'])->toBe([
            [
                'target' => 8080,
                'published' => 8443,
                'host_ip' => '127.0.0.1',
                'protocol' => 'tcp',
            ],
        ])
        ->and($services['postgres']['ports'])->toBe([
            [
                'target' => 5432,
                'published' => 5432,
                'host_ip' => '127.0.0.1',
                'protocol' => 'tcp',
            ],
        ]);

    foreach (['migrate', 'web', 'worker', 'scheduler'] as $service) {
        expect($services[$service])->not->toHaveKey('ports');
    }
});

test('the host-networked worker can reach PostgreSQL without opening the application network', function (): void {
    $services = $this->productionCompose['services'];
    $networks = $this->productionCompose['networks'];

    expect($services['postgres']['networks'])
        ->toContain('application', 'database-host')
        ->and($networks['application']['internal'])->toBeTrue()
        ->and($networks['database-host'])
        ->not->toHaveKey('internal');
});

test('Tailscale serves production HTTPS without replacing the existing tailnet policy', function () {
    $service = file_get_contents(base_path('money-assistant-tailnet.service'));
    $verifier = file_get_contents(base_path('verify-private-ingress'));

    expect($service)
        ->toContain('tailscale wait')
        ->toContain('tailscale serve --bg --https=8443 http://127.0.0.1:8443')
        ->toContain('tailscale serve --https=8443 off')
        ->not->toContain('tailscale serve --bg --https=443')
        ->not->toContain('tailscale serve --https=443 off')
        ->not->toContain('--set-path=/hooks/money-assistant')
        ->not->toContain('tailscale funnel')
        ->and($verifier)
        ->toContain("jq -e '.BackendState == \"Running\"'")
        ->not->toContain('tag:money-assistant-approved-device', 'tag:money-assistant');
});

test('production contains no OpenClaw process credentials hooks or plugin assets', function (): void {
    $compose = file_get_contents(base_path('compose.production.yaml'));
    $environment = file_get_contents(base_path('.env.production.example'));
    $entrypoint = file_get_contents(base_path('docker-entrypoint.production'));

    expect($this->productionCompose['services'])
        ->not->toHaveKey('openclaw')
        ->and($compose)
        ->not->toContain('OPENCLAW_', 'openclaw')
        ->and($environment)
        ->not->toContain('OPENCLAW_')
        ->and($entrypoint)
        ->not->toContain('OPENCLAW_')
        ->and(base_path('openclaw'))
        ->not->toBeDirectory();
});

test('the production stack ships a private ingress verifier', function () {
    $verifier = file_get_contents(base_path('verify-private-ingress'));
    expect($verifier)
        ->toContain('tailscale serve status --json')
        ->toContain('${PRIVATE_HOSTNAME}:8443')
        ->toContain('.TCP["8443"].HTTPS == true')
        ->toContain('"https://${PRIVATE_HOSTNAME}:8443/up"')
        ->toContain('tailscale funnel status --json')
        ->toContain('ufw status verbose')
        ->toContain('ss -H -lnt')
        ->toContain('docker compose')
        ->not->toContain('.TCP["443"].HTTPS == true', '18789', '19789', 'OPENCLAW_');
});

test('deployments record pinned application and database versions without secrets', function () {
    $services = $this->productionCompose['services'];
    $deployment = file_get_contents(base_path('deploy-production'));

    expect($services['postgres']['image'])
        ->toBe('${POSTGRES_IMAGE:?Set POSTGRES_IMAGE to a pinned PostgreSQL image}')
        ->and($deployment)
        ->toContain('flock')
        ->toContain('application_image')
        ->toContain('database_image')
        ->toContain('application_revision')
        ->toContain('database_version')
        ->toContain('previous_application_image')
        ->toContain('production-trust-gate')
        ->not->toContain('docker compose config')
        ->not->toContain('set -x');
});

test('systemd restores the production stack before private ingress after reboot', function () {
    $productionService = file_get_contents(base_path('money-assistant-production.service'));
    $tailnetService = file_get_contents(base_path('money-assistant-tailnet.service'));
    $installer = file_get_contents(base_path('install-production-services'));
    $rehearsal = file_get_contents(base_path('rehearse-production-reboot'));

    expect($productionService)
        ->toContain('Requires=docker.service')
        ->toContain('After=docker.service')
        ->toContain('up --detach --wait --pull never')
        ->toContain('WantedBy=multi-user.target')
        ->and($tailnetService)
        ->toContain('Requires=money-assistant-production.service')
        ->toContain('After=money-assistant-production.service tailscale-online.target')
        ->and($installer)
        ->toContain('systemctl enable --now money-assistant-production.service money-assistant-tailnet.service')
        ->and($rehearsal)
        ->toContain('deployment.lock')
        ->toContain('systemctl reboot')
        ->toContain('systemctl is-active --quiet')
        ->toContain('/usr/local/sbin/verify-production-deployment')
        ->toContain('app:deployment-rehearsal:verify');
});

test('failed deployments restore and verify the previous healthy release', function () {
    $deployment = file_get_contents(base_path('deploy-production'));

    expect($deployment)
        ->toContain('rollback')
        ->toContain('previous.env')
        ->toContain('install_environment "$previous_environment" "$environment_file"')
        ->toContain('up --detach --wait --pull never')
        ->toContain('verify_stack');
});

test('production releases isolate migration and admit background writers only after web health', function (): void {
    $services = $this->productionCompose['services'];
    $migrationStorage = collect($services['migrate']['volumes'])->firstWhere('target', '/app/storage');

    expect($services['migrate']['command'])
        ->toContain('--force', '--isolated', '--no-interaction')
        ->and($services['migrate']['environment']['CACHE_STORE'])->toBe('file')
        ->and($migrationStorage['source'])->toBe('application-storage')
        ->and($services['worker']['depends_on']['web']['condition'])
        ->toBe('service_healthy')
        ->and($services['scheduler']['depends_on']['web']['condition'])
        ->toBe('service_healthy');
});

test('deployments freeze writers around a verified PostgreSQL rollback snapshot', function (): void {
    $deployment = file_get_contents(base_path('deploy-production'));

    expect($deployment)
        ->toContain('config --quiet')
        ->toContain('verify_candidate_storage')
        ->toContain('touch "$1" && rm -f "$1"')
        ->toContain('exec --user 33:33 --no-TTY web')
        ->toContain('stop worker scheduler web proxy')
        ->toContain('pg_dump')
        ->toContain('--format=custom')
        ->toContain('pg_restore --list')
        ->toContain('install -m 0600')
        ->toContain('run --rm --no-deps migrate')
        ->toContain('DEPLOYMENT_REQUESTS_ENABLED=false')
        ->toContain('DEPLOYMENT_REQUESTS_ENABLED=true')
        ->toContain('remove_candidate_activation_marker')
        ->toContain('create_candidate_activation_marker')
        ->toContain('up --detach --wait --no-deps web proxy')
        ->toContain('up --detach --wait --no-deps worker scheduler')
        ->toContain('pg_restore')
        ->toContain('--exit-on-error')
        ->toContain('--create')
        ->not->toContain('--entrypoint')
        ->not->toContain('migrate:rollback');
});

test('deployment storage writeability is verified before maintenance starts', function (): void {
    $deployment = file_get_contents(base_path('deploy-production'));
    $storageProbe = strpos($deployment, 'verify_candidate_storage ||');
    $maintenanceStart = strpos($deployment, 'maintenance_started_epoch=', $storageProbe);

    expect($storageProbe)->not->toBeFalse()
        ->and($maintenanceStart)->not->toBeFalse()
        ->and($storageProbe)->toBeLessThan($maintenanceStart);
});

test('failed deployment recovery is attempted once and reported through monitoring', function (): void {
    $deployment = file_get_contents(base_path('deploy-production'));

    expect($deployment)
        ->toContain('rollback_attempted')
        ->toContain('DEPLOYMENT_ALERT_COMMAND')
        ->toContain('[ -x "$deployment_alert_command" ]')
        ->toContain('Production deployment failed')
        ->toContain('Production deployment recovered')
        ->toContain('database commit point was preserved without automatic rollback')
        ->not->toContain('while restore');
});

test('a restored deployment records a terminal transaction phase', function (): void {
    $deployment = file_get_contents(base_path('deploy-production'));
    $restoreTransactionStart = strpos($deployment, 'restore_transaction()');
    $interruptedRecoveryStart = strpos($deployment, 'recover_interrupted_transaction()');
    $restoreTransaction = substr(
        $deployment,
        $restoreTransactionStart,
        $interruptedRecoveryStart - $restoreTransactionStart,
    );
    $terminalPhase = strpos($restoreTransaction, 'record_deployment_phase rolled_back');
    $snapshotRemoval = strpos($restoreTransaction, 'rm -f -- "$rollback_snapshot"');

    expect($terminalPhase)->not->toBeFalse()
        ->and($snapshotRemoval)->not->toBeFalse()
        ->and($terminalPhase)->toBeLessThan($snapshotRemoval);
});

test('the final candidate is health checked while fenced before rollback is disarmed', function (): void {
    $deployment = file_get_contents(base_path('deploy-production'));
    $candidateHealth = strpos($deployment, 'activate_web_release "$candidate_environment"');
    $rollbackDisarmed = strpos($deployment, 'maintenance_active=false', $candidateHealth);
    $requestAdmission = strpos($deployment, 'create_candidate_activation_marker', $rollbackDisarmed);
    $backgroundAdmission = strpos($deployment, 'activate_background_services', $requestAdmission);

    expect($candidateHealth)->not->toBeFalse()
        ->and($rollbackDisarmed)->not->toBeFalse()
        ->and($requestAdmission)->not->toBeFalse()
        ->and($backgroundAdmission)->not->toBeFalse()
        ->and($candidateHealth)->toBeLessThan($rollbackDisarmed)
        ->and($rollbackDisarmed)->toBeLessThan($requestAdmission)
        ->and($requestAdmission)->toBeLessThan($backgroundAdmission)
        ->and($deployment)->not->toContain('activate_web_release "$active_candidate_environment"');
});
