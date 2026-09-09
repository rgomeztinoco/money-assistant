<?php

use Symfony\Component\Yaml\Yaml;

test('required CI checks match workflow jobs', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $ruleset = json_decode(file_get_contents(base_path('.github/rulesets/protect-main.json')), true, flags: JSON_THROW_ON_ERROR);
    $requiredStatusChecks = collect($ruleset['rules'])
        ->firstWhere('type', 'required_status_checks')['parameters']['required_status_checks'];
    $requiredContexts = collect($requiredStatusChecks)->pluck('context')->all();

    expect($requiredContexts)->not->toBeEmpty()
        ->toEqualCanonicalizing(array_keys($workflow['jobs']));
});

test('CI runs the quality gates and builds assets before testing', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $commands = implode("\n", array_column($workflow['jobs']['ci']['steps'], 'run'));
    $productionCommands = implode("\n", array_column($workflow['jobs']['production-stack']['steps'], 'run'));

    expect($commands)->toContain(
        'artisan migrate:fresh',
        'artisan test --compact tests/Feature',
        'artisan test --compact tests/Browser',
        'vendor/bin/pint --test',
        'vendor/bin/phpstan analyse',
        'npm run types:check',
        'npm run lint:check',
        'npm run format:check',
        'npm run build',
    )->and($productionCommands)->toContain('artisan test', 'ProductionStackTest.php', 'BackupRecoveryTest.php');

    expect(strpos($commands, 'artisan migrate:fresh'))->toBeLessThan(strpos($commands, 'npm run build'))
        ->and(strpos($commands, 'npm run build'))->toBeLessThan(strpos($commands, 'artisan test'));
});

test('CI runs directly on the runner against a healthy PostgreSQL service', function (string $job): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $steps = collect($workflow['jobs'][$job]['steps']);
    $commands = implode("\n", $steps->pluck('run')->all());
    $php = $steps->first(fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'shivammathur/setup-php@'));
    $database = $workflow['jobs'][$job]['services']['postgres'] ?? null;

    expect($database)->not->toBeNull();

    expect($commands)->not->toContain('vendor/bin/sail', 'docker build', '--no-scripts')
        ->toContain('php artisan test', 'php artisan key:generate')
        ->and(substr_count($commands, 'composer install'))->toBe(1)
        ->and($workflow['jobs'][$job])->not->toHaveKey('container')
        ->and($steps->pluck('uses')->implode("\n"))->not->toContain('docker/')
        ->and($php['with']['php-version'])->toBe('8.5')
        ->and($php['with']['extensions'])->toContain('pdo_pgsql')
        ->and($database['image'])->toBe('postgres:18-alpine')
        ->and($database['ports'])->toContain('5432:5432')
        ->and($database['options'])->toContain('--health-cmd', 'pg_isready')
        ->and($workflow['env']['DB_CONNECTION'])->toBe('pgsql')
        ->and($workflow['env']['DB_HOST'])->toBe('127.0.0.1')
        ->and($workflow['env']['DB_PORT'])->toBe('5432');

    foreach (['DB_DATABASE' => 'POSTGRES_DB', 'DB_USERNAME' => 'POSTGRES_USER', 'DB_PASSWORD' => 'POSTGRES_PASSWORD'] as $applicationKey => $serviceKey) {
        expect($workflow['env'][$applicationKey])->toBe($database['env'][$serviceKey]);
    }
})->with(['ci', 'production-stack']);

test('CI installs Node and Chromium before running browser tests', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $steps = collect($workflow['jobs']['ci']['steps']);
    $node = $steps->first(fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/setup-node@'));
    $dependencies = $steps->firstWhere('run', 'npm ci --no-audit --no-fund');
    $browser = $steps->firstWhere('run', 'npx playwright install --with-deps chromium');
    $tests = $steps->firstWhere('run', 'php artisan test --compact tests/Browser');

    expect($node)->not->toBeNull()
        ->and($dependencies)->not->toBeNull()
        ->and($browser)->not->toBeNull()
        ->and($tests)->not->toBeNull()
        ->and($node['with']['node-version'])->toBe('24')
        ->and($steps->search($node))->toBeLessThan($steps->search($dependencies))
        ->and($steps->search($dependencies))->toBeLessThan($steps->search($browser))
        ->and($steps->search($browser))->toBeLessThan($steps->search($tests));
});
