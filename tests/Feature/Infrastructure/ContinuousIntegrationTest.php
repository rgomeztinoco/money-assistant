<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $this->productionSteps = collect($this->workflow['jobs']['production-stack']['steps']);
    $this->productionScripts = $this->productionSteps->pluck('run', 'name');
});

test('CI installs the locked browser runtime before running the complete application checks', function (): void {
    $steps = collect($this->workflow['jobs']['ci']['steps']);

    $browserDependencyInstallation = $steps->search(
        fn (array $step): bool => ($step['run'] ?? null)
            === "vendor/bin/sail root-shell -c 'npx playwright install-deps chromium'",
    );
    $browserInstallation = $steps->search(
        fn (array $step): bool => ($step['run'] ?? null)
            === 'vendor/bin/sail npx playwright install chromium',
    );
    $ciChecks = $steps->search(
        fn (array $step): bool => ($step['run'] ?? null) === 'vendor/bin/sail composer ci:check',
    );

    expect($browserDependencyInstallation)
        ->toBeInt()
        ->toBeLessThan($browserInstallation)
        ->and($browserInstallation)
        ->toBeInt()
        ->toBeLessThan($ciChecks);
});

test('the rollback rehearsal lets each candidate environment select its application image', function (): void {
    $imageConfiguration = $this->productionScripts->get('Build rehearsal application images');
    $rehearsalConfiguration = $this->productionScripts->get('Create isolated rehearsal configuration');

    expect($imageConfiguration)
        ->not->toContain('echo "APP_IMAGE_REPOSITORY=')
        ->not->toContain('echo "APP_IMAGE_DIGEST=')
        ->and($rehearsalConfiguration)
        ->toContain('healthy_repository="${GOOD_APP_IMAGE%@*}"')
        ->toContain('healthy_digest="${GOOD_APP_IMAGE##*@}"');
});

test('the production rehearsal supplies isolated values for every required integration boundary', function (): void {
    $imageConfiguration = $this->productionScripts->get('Build rehearsal application images');
    $rehearsalConfiguration = $this->productionScripts->get('Create isolated rehearsal configuration');

    expect($imageConfiguration)
        ->toContain('GOOGLE_GMAIL_CLIENT_SECRET_FILE=')
        ->toContain('OPENCLAW_CAPABILITY_AGENT_ID=')
        ->toContain('OPENCLAW_CAPABILITY_ACCOUNT_ID=')
        ->toContain('OPENCLAW_CAPABILITY_CONVERSATION_ID=')
        ->toContain('OPENCLAW_CAPABILITY_OWNER_SENDER_ID=')
        ->and($rehearsalConfiguration)
        ->toContain('GOOGLE_GMAIL_CLIENT_ID=')
        ->toContain('GOOGLE_GMAIL_CLIENT_SECRET_FILE=')
        ->toContain('OPENCLAW_CAPABILITY_AGENT_ID=')
        ->toContain('OPENCLAW_CAPABILITY_ACCOUNT_ID=')
        ->toContain('OPENCLAW_CAPABILITY_CONVERSATION_ID=')
        ->toContain('OPENCLAW_CAPABILITY_OWNER_SENDER_ID=');
});

test('production rehearsal failures report masked diagnostics', function (): void {
    $rehearsalConfiguration = $this->productionScripts->get('Create isolated rehearsal configuration');
    $rollbackRehearsal = $this->productionScripts->get('Rehearse failed migration restoration');
    $secretIsolation = $this->productionScripts->get('Verify production rehearsal secret isolation');
    $diagnostics = $this->productionSteps->firstWhere('name', 'Report production rehearsal failure');

    expect($rehearsalConfiguration)
        ->toContain('::add-mask::')
        ->toContain('sudo chown root:root')
        ->toContain('$GOOGLE_GMAIL_CLIENT_SECRET_FILE')
        ->and($rollbackRehearsal)
        ->toContain('app:financial-state:fingerprint')
        ->and($secretIsolation)
        ->toContain('$GOOGLE_GMAIL_CLIENT_SECRET_FILE')
        ->and($diagnostics['if'])
        ->toBe('failure()')
        ->and($diagnostics['run'])
        ->toContain('sanitized_diagnostics')
        ->toContain('sed --in-place')
        ->toContain('APP_KEY_FILE')
        ->toContain('DB_PASSWORD_FILE')
        ->toContain('GOOGLE_GMAIL_CLIENT_SECRET_FILE')
        ->toContain('OPENCLAW_CAPABILITY_PUBLIC_KEY_FILE')
        ->toContain('OPENCLAW_HOOK_TOKEN_FILE')
        ->not->toContain('cat "$REHEARSAL_OUTPUT"')
        ->toContain('ps --all')
        ->toContain('logs --no-color');
});

test('production CI rehearses the transactional release outcomes as black boxes', function (): void {
    $transactionalRehearsalSteps = [
        'Rehearse successful transactional deployment',
        'Rehearse failed migration restoration',
        'Rehearse failed health restoration',
        'Rehearse concurrent deployment exclusion',
        'Rehearse interrupted bundle activation convergence',
    ];

    expect($this->productionScripts->keys()->all())
        ->toContain(...$transactionalRehearsalSteps);

    foreach ($transactionalRehearsalSteps as $stepName) {
        expect((string) $this->productionScripts->get($stepName))
            ->toContain('./activate-production-release deploy')
            ->toContain('--source-revision "$GITHUB_SHA"')
            ->toContain('--bundle-checksum "$OPERATIONAL_BUNDLE_CHECKSUM"');
    }

    expect((string) $this->productionScripts->get('Deploy healthy production stack'))
        ->toContain('activate-production-release validate')
        ->toContain('mismatched operational bundle checksum')
        ->and((string) $this->productionScripts->get('Rehearse interrupted bundle activation convergence'))
        ->toContain('kill -KILL')
        ->toContain('transaction.state');
});

test('rollback rehearsals compare domain fingerprints and durable work after restoration', function (): void {
    foreach ([
        'Rehearse failed migration restoration',
        'Rehearse failed health restoration',
    ] as $stepName) {
        expect((string) $this->productionScripts->get($stepName))
            ->toContain('app:financial-state:fingerprint')
            ->toContain('app:deployment-rehearsal:verify')
            ->toContain('rolled_back');
    }
});

test('production rehearsal proves maintenance timing alerts and secret isolation', function (): void {
    $successfulDeployment = (string) $this->productionScripts->get('Rehearse successful transactional deployment');
    $failedMigration = (string) $this->productionScripts->get('Rehearse failed migration restoration');
    $failedHealth = (string) $this->productionScripts->get('Rehearse failed health restoration');

    expect($successfulDeployment)
        ->toContain('maintenance_seconds')
        ->toContain('-lt 300')
        ->and($failedMigration)
        ->toContain('Production deployment failed')
        ->toContain('Production deployment recovered')
        ->and($failedHealth)
        ->toContain('Production deployment failed')
        ->toContain('Production deployment recovered');

    foreach ($this->productionScripts as $script) {
        expect((string) $script)->not->toContain('set -x');
    }
});
