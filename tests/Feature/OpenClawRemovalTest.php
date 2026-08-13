<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

test('OpenClaw application routes and owner-facing data are absent', function () {
    $owner = User::factory()->create();

    expect(Route::has('openclaw.transport'))->toBeFalse()
        ->and(Route::has('high_impact_operations.show'))->toBeFalse()
        ->and(Route::has('high_impact_operations.complete'))->toBeFalse();

    $this->postJson('/api/openclaw/v1/transport')->assertNotFound();

    $this->actingAs($owner)
        ->get('/settings/high-impact-operations/removed')
        ->assertNotFound();

    $this->get(route('connections.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/connections')
            ->missing('openclaw'));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->missing('openclaw')
            ->missing('operating.summary.openclaw'));
});

test('OpenClaw and Receipt Proposal persistence is absent', function () {
    foreach ([
        'open_claw_audit_events',
        'open_claw_confirmation_grants',
        'open_claw_pending_operations',
        'open_claw_request_nonces',
        'receipt_proposals',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Schema::hasColumn('receipt_breakdowns', 'receipt_proposal_id'))->toBeFalse()
        ->and(Schema::hasTable('financial_data_tombstones'))->toBeFalse();
});

test('OpenClaw runtime assets and operational integration are absent', function (): void {
    foreach ([
        'openclaw',
        'deliver-openclaw-monitor-alert',
        'read-running-openclaw-version',
        'rehearse-openclaw-upgrade',
    ] as $removedPath) {
        expect(file_exists(base_path($removedPath)))->toBeFalse();
    }

    foreach ([
        '.env.example',
        '.env.production.example',
        '.github/workflows/tests.yml',
        'compose.production.yaml',
        'deploy-production',
        'docker-entrypoint.production',
        'export-production-backup',
        'rehearse-independent-alert',
        'restore-production-backup',
        'verify-private-ingress',
    ] as $retainedPath) {
        expect(file_get_contents(base_path($retainedPath)))
            ->not->toContain('OPENCLAW_', 'OpenClaw', 'openclaw');
    }
});
