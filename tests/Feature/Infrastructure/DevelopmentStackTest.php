<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->developmentCompose = Yaml::parseFile(base_path('compose.yaml'));
});

test('development services publish ports only on loopback', function () {
    $services = $this->developmentCompose['services'];

    expect($services['laravel.test']['ports'])->toBe([
        '127.0.0.1:${APP_PORT:-8443}:80',
        '127.0.0.1:${VITE_PORT:-5173}:${VITE_PORT:-5173}',
    ])->and($services['pgsql']['ports'])->toBe([
        '127.0.0.1:${FORWARD_DB_PORT:-5432}:5432',
    ])->and($services['mailpit']['ports'])->toBe([
        '127.0.0.1:${FORWARD_MAILPIT_PORT:-1025}:1025',
        '127.0.0.1:${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}:8025',
    ]);
});

test('the default development application port matches the OpenClaw capability origin', function () {
    $environment = file_get_contents(base_path('.env.example'));
    $plugin = file_get_contents(base_path('openclaw/money-assistant-plugin/src/index.ts'));

    expect($environment)
        ->toContain('APP_URL=http://localhost:8443')
        ->toContain('APP_PORT=8443')
        ->and($plugin)
        ->toContain("const CAPABILITY_ORIGIN = 'http://127.0.0.1:8443'");
});
