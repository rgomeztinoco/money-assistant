<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

test('required CI checks cover every workflow job', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $ruleset = json_decode(file_get_contents(base_path('.github/rulesets/protect-main.json')), true, flags: JSON_THROW_ON_ERROR);
    $requiredStatusChecks = collect($ruleset['rules'])
        ->firstWhere('type', 'required_status_checks')['parameters']['required_status_checks'];
    $requiredContexts = collect($requiredStatusChecks)->pluck('context')->all();
    $coveredJobs = collect($requiredContexts)->flatMap(fn (string $context): array => [
        $context, ...($workflow['jobs'][$context]['needs'] ?? []),
    ])->unique()->values()->all();

    expect($requiredContexts)->not->toBeEmpty()
        ->toContain('ci', 'production-stack')
        ->and($coveredJobs)
        ->toEqualCanonicalizing(array_keys($workflow['jobs']));
});

test('CI runs the quality gates and builds assets before testing', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $commands = implode("\n", array_column($workflow['jobs']['checks']['steps'], 'run'));
    $browserCommands = implode("\n", array_column($workflow['jobs']['browser']['steps'], 'run'));
    $productionCommands = implode("\n", array_column($workflow['jobs']['production-stack']['steps'], 'run'));

    expect($commands)->toContain(
        'artisan migrate:fresh',
        'artisan test --compact tests/Feature',
        'vendor/bin/pint --test',
        'vendor/bin/phpstan analyse',
        'pnpm run types:check',
        'pnpm run lint:check',
        'pnpm run format:check',
        'pnpm run build',
    )->not->toContain('tests/Browser', 'playwright install')
        ->and($browserCommands)->toContain('artisan test --compact --parallel --processes=2 tests/Browser', 'pnpm run build')
        ->not->toContain('tests/Feature')
        ->and($productionCommands)->toContain('artisan test', 'ProductionStackTest.php', 'BackupRecoveryTest.php');

    expect(strpos($commands, 'artisan migrate:fresh'))->toBeLessThan(strpos($commands, 'pnpm run build'))
        ->and(strpos($commands, 'pnpm run build'))->toBeLessThan(strpos($commands, 'artisan test'))
        ->and(strpos($browserCommands, 'pnpm run build'))->toBeLessThan(strpos($browserCommands, 'artisan test'));
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
})->with(['checks', 'browser', 'production-stack']);

test('CI installs headless Chromium before running browser tests with two workers', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $steps = collect($workflow['jobs']['browser']['steps']);
    $node = $steps->first(fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/setup-node@'));
    $dependencies = $steps->firstWhere('run', 'pnpm install --frozen-lockfile');
    $browser = $steps->firstWhere('run', 'pnpm exec playwright install --with-deps --only-shell chromium');
    $tests = $steps->firstWhere('run', 'php artisan test --compact --parallel --processes=2 tests/Browser');

    expect($node)->not->toBeNull()
        ->and($dependencies)->not->toBeNull()
        ->and($browser)->not->toBeNull()
        ->and($tests)->not->toBeNull()
        ->and($node['with']['node-version'])->toBe('24')
        ->and($steps->search($node))->toBeLessThan($steps->search($dependencies))
        ->and($steps->search($dependencies))->toBeLessThan($steps->search($browser))
        ->and($steps->search($browser))->toBeLessThan($steps->search($tests));
});

test('frontend CI jobs install the pinned pnpm before restoring its cache', function (string $job): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $steps = collect($workflow['jobs'][$job]['steps']);
    $pnpm = $steps->first(fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'pnpm/action-setup@'));
    $node = $steps->first(fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/setup-node@'));
    $dependencies = $steps->firstWhere('run', 'pnpm install --frozen-lockfile');
    $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($pnpm)->not->toBeNull()
        ->and($node)->not->toBeNull()
        ->and($dependencies)->not->toBeNull()
        ->and($package['packageManager'])->toMatch('/^pnpm@\d+\.\d+\.\d+$/')
        ->and($pnpm['with'] ?? [])->not->toHaveKey('version')
        ->and($node['with']['cache'])->toBe('pnpm')
        ->and($steps->search($pnpm))->toBeLessThan($steps->search($node))
        ->and($steps->search($node))->toBeLessThan($steps->search($dependencies))
        ->and(base_path('pnpm-lock.yaml'))->toBeFile()
        ->and(file_exists(base_path('package-lock.json')))->toBeFalse();
})->with(['checks', 'browser']);

test('CI waits for independent browser and quality jobs even when they fail', function (): void {
    $jobs = Yaml::parseFile(base_path('.github/workflows/tests.yml'))['jobs'];

    expect($jobs['checks'])->not->toHaveKeys(['needs', 'if', 'continue-on-error'])
        ->and($jobs['browser'])->not->toHaveKeys(['needs', 'if', 'continue-on-error'])
        ->and($jobs['ci']['needs'])->toEqualCanonicalizing(['checks', 'browser'])
        ->and($jobs['ci']['if'])->toBe('${{ always() }}')
        ->and($jobs['ci'])->not->toHaveKey('continue-on-error')
        ->and($jobs['ci']['steps'])->toHaveCount(1);

    $gate = $jobs['ci']['steps'][0];

    expect($gate)->not->toHaveKeys(['if', 'continue-on-error'])
        ->and($gate['env'])->toBe([
            'CHECKS_RESULT' => '${{ needs.checks.result }}',
            'BROWSER_RESULT' => '${{ needs.browser.result }}',
        ]);
});

test('the CI gate passes only when both jobs succeed', function (string $checks, string $browser): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $gate = $workflow['jobs']['ci']['steps'][0];
    $process = new Process(['bash', '-e', '-c', $gate['run']], base_path(), [
        'CHECKS_RESULT' => $checks,
        'BROWSER_RESULT' => $browser,
    ]);
    $process->run();

    expect($process->isSuccessful())->toBe($checks === 'success' && $browser === 'success');
})->with(['success', 'failure', 'cancelled', 'skipped'])
    ->with(['success', 'failure', 'cancelled', 'skipped']);
