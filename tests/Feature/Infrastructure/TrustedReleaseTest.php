<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function (): void {
    $this->workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    $this->release = $this->workflow['jobs']['publish-release'];
    $this->releaseRecord = $this->workflow['jobs']['record-release'];
    $this->releaseSteps = collect($this->release['steps']);
    $this->ruleset = json_decode(
        file_get_contents(base_path('.github/rulesets/protect-main.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
});

test('pull requests and main pushes run every release gate before publication', function (): void {
    expect($this->workflow['on'])->toHaveKey('pull_request')
        ->and($this->workflow['on']['push']['branches'])->toBe(['main'])
        ->and($this->release['needs'])->toBe(['ci', 'production-stack'])
        ->and($this->release['if'])
        ->toBe("github.event_name == 'push' && github.ref == 'refs/heads/main'")
        ->and($this->releaseRecord['needs'])->toBe(['publish-release']);
});

test('a successful main run publishes a revisioned image and operational bundle with provenance', function (): void {
    $stepsByName = $this->releaseSteps->keyBy('name');
    $imagePublicationStep = $stepsByName->get('Build and publish application image');
    $bundleBuildStep = $stepsByName->get('Build operational bundle');
    $releaseMetadataStep = $stepsByName->get('Record immutable release metadata');
    $releasePublication = collect($this->releaseRecord['steps'])->firstWhere('name', 'Publish immutable GitHub release');

    expect($imagePublicationStep['with']['push'])->toBeTrue()
        ->and($imagePublicationStep['with']['tags'])->toContain('ghcr.io/${{ github.repository }}:${{ github.sha }}')
        ->and($imagePublicationStep['with']['labels'])->toContain('org.opencontainers.image.revision=${{ github.sha }}')
        ->and($bundleBuildStep['run'])
        ->toContain('./build-operational-bundle "$GITHUB_SHA"')
        ->toContain('sha256sum')
        ->and($releaseMetadataStep['run'])
        ->toContain('ghcr.io/${GITHUB_REPOSITORY}@${IMAGE_DIGEST}')
        ->and($releasePublication['run'])
        ->toContain('operational-bundle-${GITHUB_SHA}.tar')
        ->toContain('gh release create')
        ->toContain('gh release upload')
        ->toContain('gh release edit --draft=false');

    expect($this->releaseSteps->pluck('uses')->filter()->all())
        ->each->toMatch('/@[a-f0-9]{40}(?:\s+#\s+v?[^\s]+)?$/');

    expect($this->releaseSteps->where('name', 'Attest application image')->first()['with'])
        ->toMatchArray([
            'subject-name' => 'ghcr.io/${{ github.repository }}',
            'subject-digest' => '${{ steps.publish-image.outputs.digest }}',
            'push-to-registry' => true,
        ])
        ->and($this->releaseSteps->where('name', 'Attest operational bundle')->first()['with']['subject-path'])
        ->toContain('operational-bundle-${{ github.sha }}.tar')
        ->toContain('operational-bundle-${{ github.sha }}.tar.sha256');
});

test('release authority is isolated to the gated publication job', function (): void {
    expect($this->workflow['permissions'])->toBe([])
        ->and($this->workflow['jobs']['ci']['permissions'])->toBe(['contents' => 'read'])
        ->and($this->workflow['jobs']['production-stack']['permissions'])->toBe(['contents' => 'read'])
        ->and($this->release['permissions'])->toBe([
            'contents' => 'read',
            'packages' => 'write',
            'id-token' => 'write',
            'attestations' => 'write',
        ])
        ->and($this->releaseRecord['permissions'])->toBe(['contents' => 'write'])
        ->and(Yaml::dump($this->workflow))->not->toContain('tailscale')
        ->not->toContain('deploy-production deploy');
});

test('the main ruleset requires every release gate without approval or bypass', function (): void {
    $rules = collect($this->ruleset['rules'])->keyBy('type');
    $pullRequest = $rules->get('pull_request')['parameters'];
    $statusChecks = $rules->get('required_status_checks')['parameters'];

    expect($this->ruleset)
        ->toMatchArray([
            'target' => 'branch',
            'enforcement' => 'active',
            'bypass_actors' => [],
        ])
        ->and($this->ruleset['conditions']['ref_name']['include'])->toBe(['refs/heads/main'])
        ->and($rules->keys()->all())->toContain('pull_request', 'required_status_checks', 'deletion', 'non_fast_forward')
        ->and($pullRequest['required_approving_review_count'])->toBe(0)
        ->and($pullRequest['require_last_push_approval'])->toBeFalse()
        ->and($statusChecks['strict_required_status_checks_policy'])->toBeTrue()
        ->and($statusChecks['required_status_checks'])->toBe([
            ['context' => 'ci'],
            ['context' => 'production-stack'],
        ]);
});
