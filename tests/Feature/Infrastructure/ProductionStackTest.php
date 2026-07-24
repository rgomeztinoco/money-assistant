<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->productionCompose = Yaml::parseFile(base_path('compose.production.yaml'));
});

test('a clean host can run only immutable production services', function () {
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

test('web worker scheduler PostgreSQL and the private proxy publish health checks', function () {
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

test('application roles share one pinned image and restart independently', function () {
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

test('Tailscale is the exclusive HTTPS ingress for approved owner devices', function () {
    $policy = file_get_contents(base_path('tailscale-policy.hujson'));
    $service = file_get_contents(base_path('money-assistant-tailnet.service'));

    expect($policy)
        ->toContain('"tag:money-assistant-approved-device"')
        ->toContain('"srcPosture": ["posture:approved-owner-device"]')
        ->toContain('"tag:money-assistant:443"')
        ->toContain('"deny": ["tag:money-assistant:80", "tag:money-assistant:8443", "tag:money-assistant:18789"]')
        ->and($service)
        ->toContain('tailscale wait')
        ->toContain('tailscale serve --bg --https=443 http://127.0.0.1:8443')
        ->not->toContain('--set-path=/hooks/money-assistant')
        ->not->toContain('tailscale funnel');
});

test('OpenClaw integrations have isolated transport and directional credentials', function () {
    $services = $this->productionCompose['services'];

    expect($services)->not->toHaveKey('openclaw')
        ->and($services['web']['secrets'])->toContain('openclaw_capability_public_key')
        ->and($services['worker']['secrets'])->toContain('openclaw_hook_token')
        ->and($services['web']['secrets'])->not->toContain('openclaw_hook_token')
        ->and($services['worker']['secrets'])->not->toContain('openclaw_capability_public_key')
        ->and($services['worker']['environment']['OPENCLAW_HOOK_URL'])
        ->toBe('${OPENCLAW_HOOK_URL:?Set OPENCLAW_HOOK_URL to the private mapped hook}');

    expect(file_get_contents(base_path('docker-entrypoint.production')))
        ->toContain('read_secret OPENCLAW_CAPABILITY_PUBLIC_KEY')
        ->toContain('read_secret OPENCLAW_HOOK_TOKEN');

    foreach ($services as $service) {
        expect(array_keys($service['environment'] ?? []))
            ->not->toContain('OPENCLAW_GATEWAY_TOKEN');

        foreach ($service['volumes'] ?? [] as $volume) {
            expect($volume['source'] ?? null)->not->toBe('openclaw');
        }
    }
});

test('the production stack ships a private ingress verifier', function () {
    $verifier = file_get_contents(base_path('verify-private-ingress'));

    expect($verifier)
        ->toContain('tailscale serve status --json')
        ->toContain('tailscale funnel status --json')
        ->toContain('ufw status verbose')
        ->toContain('ss -H -lnt')
        ->toContain('docker compose');
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
        ->toContain('verify-private-ingress')
        ->toContain('app:deployment-rehearsal:verify');
});

test('failed deployments restore and verify the previous healthy release', function () {
    $deployment = file_get_contents(base_path('deploy-production'));

    expect($deployment)
        ->toContain('rollback')
        ->toContain('previous.env')
        ->toContain('up --detach --wait --pull never')
        ->toContain('verify_stack');
});
