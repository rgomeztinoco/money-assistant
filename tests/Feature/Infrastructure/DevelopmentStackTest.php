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

test('development runs its queue worker and scheduler as supervised services', function () {
    $services = $this->developmentCompose['services'];

    expect($services)->toHaveKeys(['laravel.test', 'queue', 'scheduler', 'pgsql', 'mailpit'])
        ->and($services['queue']['restart'])->toBe('unless-stopped')
        ->and($services['queue']['network_mode'])->toBe('host')
        ->and($services['queue']['environment']['DB_HOST'])->toBe('127.0.0.1')
        ->and($services['queue']['environment']['DB_PORT'])->toBe('${FORWARD_DB_PORT:-5432}')
        ->and($services['queue'])->not->toHaveKey('networks')
        ->and($services['queue']['command'])->toBe([
            'php',
            'artisan',
            'queue:work',
            'database',
            '--sleep=3',
            '--tries=3',
            '--timeout=60',
            '--no-interaction',
        ])
        ->and($services['scheduler']['restart'])->toBe('unless-stopped')
        ->and($services['scheduler']['command'])->toBe([
            'php',
            'artisan',
            'schedule:work',
            '--no-interaction',
        ]);
});

test('the default development application port matches the OpenClaw capability origin', function () {
    $environment = file_get_contents(base_path('.env.example'));
    $plugin = file_get_contents(base_path('openclaw/money-assistant-plugin/src/index.ts'));

    expect($environment)
        ->toContain('APP_URL=http://localhost:8443')
        ->toContain('APP_PORT=8443')
        ->toContain('OPENCLAW_CAPABILITY_KEY_ID=')
        ->toContain('OPENCLAW_CAPABILITY_PUBLIC_KEY=')
        ->toContain('OPENCLAW_CAPABILITY_AGENT_ID=money-assistant')
        ->toContain('OPENCLAW_CAPABILITY_ACCOUNT_ID=default')
        ->toContain('OPENCLAW_CAPABILITY_CONVERSATION_ID=')
        ->toContain('OPENCLAW_CAPABILITY_OWNER_SENDER_ID=')
        ->toContain('OPENCLAW_HOOK_URL=http://127.0.0.1:18789/hooks/money-assistant')
        ->toContain('OPENCLAW_HOOK_TOKEN=')
        ->and($plugin)
        ->toContain("const CAPABILITY_ORIGIN = 'http://127.0.0.1:8443'");
});
