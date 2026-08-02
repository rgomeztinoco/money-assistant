<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
});

test('CI installs the locked browser runtime before running the complete application checks', function (): void {
    $steps = collect($this->workflow['jobs']['ci']['steps']);

    $browserInstallation = $steps->search(
        fn (array $step): bool => ($step['run'] ?? null)
            === 'vendor/bin/sail npx playwright install --with-deps chromium',
    );
    $ciChecks = $steps->search(
        fn (array $step): bool => ($step['run'] ?? null) === 'vendor/bin/sail composer ci:check',
    );

    expect($browserInstallation)
        ->toBeInt()
        ->toBeLessThan($ciChecks);
});

test('the production rehearsal supplies isolated values for every required integration boundary', function (): void {
    $steps = collect($this->workflow['jobs']['production-stack']['steps']);
    $imageConfiguration = $steps->firstWhere('name', 'Build rehearsal application images')['run'];
    $rehearsalConfiguration = $steps->firstWhere('name', 'Create isolated rehearsal configuration')['run'];

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
    $steps = collect($this->workflow['jobs']['production-stack']['steps']);
    $rehearsalConfiguration = $steps->firstWhere('name', 'Create isolated rehearsal configuration')['run'];
    $rollbackRehearsal = $steps->firstWhere('name', 'Rehearse failed deployment rollback')['run'];
    $diagnostics = $steps->firstWhere('name', 'Report production rehearsal failure');

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
