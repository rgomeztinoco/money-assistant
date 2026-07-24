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

test('only the pinned reverse proxy exposes a tailnet-bound port', function () {
    $services = $this->productionCompose['services'];

    expect($services['postgres']['image'])->toMatch('/@sha256:[a-f0-9]{64}$/')
        ->and($services['proxy']['image'])->toMatch('/@sha256:[a-f0-9]{64}$/')
        ->and($services['proxy']['ports'])->toBe([
            [
                'target' => 8443,
                'published' => '${PRIVATE_HTTPS_PORT:-443}',
                'host_ip' => '${PRIVATE_BIND_ADDRESS:?Set PRIVATE_BIND_ADDRESS to the host tailnet IP}',
                'protocol' => 'tcp',
            ],
        ]);

    foreach (['postgres', 'migrate', 'web', 'worker', 'scheduler'] as $service) {
        expect($services[$service])->not->toHaveKey('ports');
    }
});
