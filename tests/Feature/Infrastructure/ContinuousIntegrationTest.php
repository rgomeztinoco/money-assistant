<?php

use Symfony\Component\Yaml\Yaml;

test('CI runs the retained quality gates against fresh PostgreSQL', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $ciSteps = collect($workflow['jobs']['ci']['steps']);
    $steps = $ciSteps->pluck('run', 'name');
    $frontendBuildPosition = $ciSteps->search(fn (array $step): bool => $step['name'] === 'Build frontend assets');
    $pestPosition = $ciSteps->search(fn (array $step): bool => $step['name'] === 'Run targeted Pest suites');

    expect($steps->get('Generate application key'))
        ->toBe('vendor/bin/sail artisan key:generate --force --no-interaction')
        ->and($steps->get('Run fresh PostgreSQL migrations'))
        ->toBe('vendor/bin/sail artisan migrate:fresh --force --no-interaction')
        ->and($steps->get('Run targeted Pest suites'))
        ->toContain('vendor/bin/sail artisan test --compact')
        ->and($steps->get('Run Pint'))
        ->toContain('vendor/bin/sail bin pint')
        ->and($steps->get('Run Larastan'))
        ->toContain('vendor/bin/sail bin phpstan analyse')
        ->and($steps->get('Run TypeScript checks'))
        ->toBe('vendor/bin/sail npm run types:check')
        ->and($steps->get('Run ESLint'))
        ->toBe('vendor/bin/sail npm run lint:check')
        ->and($steps->get('Run Prettier'))
        ->toBe('vendor/bin/sail npm run format:check')
        ->and($steps->get('Build frontend assets'))
        ->toBe('vendor/bin/sail npm run build')
        ->and($frontendBuildPosition)
        ->toBeInt()
        ->toBeLessThan($pestPosition);
});

test('CI keeps one focused infrastructure job without release publication or rehearsals', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $workflowContents = file_get_contents(base_path('.github/workflows/tests.yml'));
    $productionStackSteps = collect($workflow['jobs']['production-stack']['steps'])->pluck('run', 'name');
    $ruleset = json_decode(file_get_contents(base_path('.github/rulesets/protect-main.json')), true, flags: JSON_THROW_ON_ERROR);
    $requiredStatusChecks = collect($ruleset['rules'])
        ->firstWhere('type', 'required_status_checks')['parameters']['required_status_checks'];
    $requiredContexts = collect($requiredStatusChecks)->pluck('context')->all();

    expect(array_keys($workflow['jobs']))->toBe(['ci', 'production-stack'])
        ->and(array_keys($workflow['jobs']))
        ->toBe($requiredContexts)
        ->and($productionStackSteps->get('Run focused infrastructure tests'))
        ->toContain('ProductionStackTest.php', 'BackupRecoveryTest.php')
        ->and($workflowContents)
        ->not->toContain(
            'publish-release',
            'record-release',
            'operational-bundle',
            'rehearsal',
            'activate-production-release',
        );
});
