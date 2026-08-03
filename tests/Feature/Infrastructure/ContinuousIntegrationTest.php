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
    $rollbackRehearsal = $this->productionScripts->get('Rehearse failed deployment rollback');
    $diagnostics = $this->productionSteps->firstWhere('name', 'Report production rehearsal failure');

    expect($rehearsalConfiguration)
        ->toContain('::add-mask::')
        ->toContain('sudo chown root:root')
        ->toContain('$GOOGLE_GMAIL_CLIENT_SECRET_FILE')
        ->and($rollbackRehearsal)
        ->toContain('$GOOGLE_GMAIL_CLIENT_SECRET_FILE')
        ->and($diagnostics['if'])
        ->toBe('failure()')
        ->and($diagnostics['run'])
        ->toContain('cat "$REHEARSAL_OUTPUT"')
        ->toContain('ps --all')
        ->toContain('logs --no-color');
});
