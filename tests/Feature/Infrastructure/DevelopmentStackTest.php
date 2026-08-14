<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->developmentCompose = Yaml::parseFile(base_path('compose.yaml'));
    $this->productionCompose = Yaml::parseFile(base_path('compose.production.yaml'));
});

test('development services publish ports only on loopback', function (): void {
    $services = $this->developmentCompose['services'];

    expect($services['laravel.test']['ports'])->toBe([
        '127.0.0.1:${APP_PORT:-8080}:80',
        '127.0.0.1:${VITE_PORT:-5173}:${VITE_PORT:-5173}',
    ])->and($services['pgsql']['ports'])->toBe([
        '127.0.0.1:${FORWARD_DB_PORT:-5433}:5432',
    ])->and($services['mailpit']['ports'])->toBe([
        '127.0.0.1:${FORWARD_MAILPIT_PORT:-1025}:1025',
        '127.0.0.1:${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}:8025',
    ]);
});

test('starting Sail launches only manually controlled development services', function (): void {
    $services = $this->developmentCompose['services'];

    expect(array_keys($services))->toBe(['laravel.test', 'pgsql', 'mailpit']);

    foreach ($services as $service) {
        expect($service)->not->toHaveKey('restart');
    }
});

test('development background processes have explicit interactive lifecycles', function (): void {
    $composer = json_decode(
        file_get_contents(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts']['dev'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'npx concurrently -c "#93c5fd,#fb7185,#fdba74" "php artisan queue:work" "php artisan pail --timeout=0" "npm run dev" --names=queue,logs,vite --kill-others',
    ])->and($composer['scripts']['dev:scheduler'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        'php artisan schedule:work --no-interaction',
    ]);
});

test('development and production lifecycle commands target isolated Compose resources', function (): void {
    $developmentProject = $this->developmentCompose['name'];
    $productionProject = $this->productionCompose['name'];
    $projectResourceNames = fn (array $compose, string $resourceType): array => array_map(
        fn (string $resource): string => $compose['name'].'_'.$resource,
        array_keys($compose[$resourceType]),
    );
    $developmentNetworks = $projectResourceNames($this->developmentCompose, 'networks');
    $productionNetworks = $projectResourceNames($this->productionCompose, 'networks');
    $developmentVolumes = $projectResourceNames($this->developmentCompose, 'volumes');
    $productionVolumes = $projectResourceNames($this->productionCompose, 'volumes');
    $productionService = file_get_contents(base_path('money-assistant-production.service'));

    expect($developmentProject)->toBe('money-assistant-development')
        ->and($productionProject)->toBe('money-assistant-production')
        ->and(array_intersect($developmentNetworks, $productionNetworks))->toBe([])
        ->and(array_intersect($developmentVolumes, $productionVolumes))->toBe([])
        ->and($this->developmentCompose['services'])->not->toHaveKey('migrate')
        ->and($this->productionCompose['services'])->toHaveKey('migrate')
        ->and(substr_count($productionService, '--project-name money-assistant-production'))->toBe(1)
        ->and($productionService)->toContain('--env-file /etc/money-assistant/production.env');
});

test('development and production integrations retain Gmail configuration', function (): void {
    $developmentEnvironment = file_get_contents(base_path('.env.example'));
    $productionEnvironment = file_get_contents(base_path('.env.production.example'));
    $developmentCompose = file_get_contents(base_path('compose.yaml'));
    $gmailContract = file_get_contents(base_path('app/Contracts/Gmail.php'));

    expect($developmentEnvironment)
        ->toContain('APP_URL=http://localhost:8080')
        ->toContain('APP_PORT=8080')
        ->toContain('FORWARD_DB_PORT=5433')
        ->toContain('GOOGLE_GMAIL_CLIENT_ID=')
        ->toContain('GOOGLE_GMAIL_CLIENT_SECRET=')
        ->toContain('GOOGLE_GMAIL_OAUTH_PUBLISHING_STATUS=testing')
        ->and($productionEnvironment)
        ->toContain('GOOGLE_GMAIL_REDIRECT_URI=https://money-assistant.example.ts.net:8443/settings/connections/gmail/callback')
        ->toContain('GOOGLE_GMAIL_OAUTH_PUBLISHING_STATUS=production')
        ->and($gmailContract)
        ->toContain("public const READ_ONLY_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';")
        ->and($developmentCompose)
        ->not->toContain('tailscale', 'https://', '8443');

    expect($developmentEnvironment)
        ->not->toContain('OPENCLAW_')
        ->and($productionEnvironment)
        ->not->toContain('OPENCLAW_')
        ->and(base_path('openclaw'))
        ->not->toBeDirectory();
});
