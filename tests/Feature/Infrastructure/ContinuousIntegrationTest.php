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
        'bin pint',
        'bin phpstan analyse',
        'npm run types:check',
        'npm run lint:check',
        'npm run format:check',
        'npm run build',
    )->and($productionCommands)->toContain('artisan test', 'ProductionStackTest.php', 'BackupRecoveryTest.php');

    expect(strpos($commands, 'artisan migrate:fresh'))->toBeLessThan(strpos($commands, 'npm run build'))
        ->and(strpos($commands, 'npm run build'))->toBeLessThan(strpos($commands, 'artisan test'));
});
