<?php

use App\Actions\OpenClaw\PrepareFinancialDeletion;
use App\Actions\OpenClaw\PrepareFinancialExport;
use App\Http\Middleware\RequirePasskeyConfirmation;
use App\Models\Category;
use App\Models\CategoryTarget;
use App\Models\CategoryTargetRevision;
use App\Models\GmailConnection;
use App\Models\OpenClawConfirmationGrant;
use App\Models\OpenClawPendingOperation;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

function prepareFinancialExport(User $owner): OpenClawPendingOperation
{
    return app(PrepareFinancialExport::class)->handle(
        owner: $owner,
        serviceKeyId: 'openclaw-service-2026-07',
        schemaVersion: 1,
        conversationId: 'telegram-owner-123',
        preparationInteractionDigest: str_repeat('a', 64),
        preparationOccurredAt: CarbonImmutable::parse('2026-08-01T12:00:00Z'),
        idempotencyKey: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
    );
}

function prepareFinancialDeletion(User $owner, Category $category): OpenClawPendingOperation
{
    return app(PrepareFinancialDeletion::class)->handle(
        owner: $owner,
        serviceKeyId: 'openclaw-service-2026-07',
        schemaVersion: 1,
        conversationId: 'telegram-owner-123',
        preparationInteractionDigest: str_repeat('b', 64),
        preparationOccurredAt: CarbonImmutable::parse('2026-08-01T12:00:00Z'),
        input: [
            'idempotency_key' => '01983d79-a780-72f0-bb34-9b4f3f0cf391',
            'resource_type' => 'category',
            'resource_id' => $category->id,
            'expected_revision' => $category->revision,
        ],
    );
}

function prepareReceiptBreakdownDeletion(
    User $owner,
    ReceiptBreakdown $breakdown,
): OpenClawPendingOperation {
    return app(PrepareFinancialDeletion::class)->handle(
        owner: $owner,
        serviceKeyId: 'openclaw-service-2026-07',
        schemaVersion: 1,
        conversationId: 'telegram-owner-123',
        preparationInteractionDigest: str_repeat('c', 64),
        preparationOccurredAt: CarbonImmutable::parse('2026-08-01T12:00:00Z'),
        input: [
            'idempotency_key' => '01983d79-a780-72f0-bb34-9b4f3f0cf392',
            'resource_type' => 'receipt_breakdown',
            'resource_id' => $breakdown->id,
            'expected_revision' => $breakdown->revision,
        ],
    );
}

test('the owner can review an unexpired financial export web continuation', function () {
    Date::setTestNow('2026-08-01T12:00:00Z');
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->create();
    $operation = prepareFinancialExport($owner);

    $this->get(route('high_impact_operations.show', $operation->operation_id))
        ->assertRedirect(route('login'));

    $this->actingAs($owner)
        ->get(route('high_impact_operations.show', $operation->operation_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/high-impact-operation')
            ->where('operation.id', $operation->operation_id)
            ->where('operation.kind', 'financial_export')
            ->where('operation.effect_summary', $operation->effect_summary)
            ->where('operation.expires_at', $operation->expires_at->toIso8601String())
            ->missing('operation.payload'));
});

test('fresh passkey authentication delivers the complete export through the web once', function () {
    Date::setTestNow('2026-08-01T12:00:00Z');
    $owner = User::factory()->create([
        'name' => 'Export Owner',
        'email' => 'export-owner@example.test',
        'password' => 'not-exported-password',
        'two_factor_secret' => 'not-exported-secret',
        'two_factor_recovery_codes' => 'not-exported-recovery-codes',
    ]);
    Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Private export merchant',
    ]);
    $category = Category::factory()->for($owner, 'owner')->create();
    $target = CategoryTarget::factory()->for($owner, 'owner')->for($category)->create();
    $targetRevision = CategoryTargetRevision::factory()->for($target)->create();
    GmailConnection::factory()->for($owner, 'owner')->create([
        'gmail_account_identity' => 'export-source@example.test',
        'access_token' => 'not-exported-access-token',
        'refresh_token' => 'not-exported-refresh-token',
    ]);
    $operation = prepareFinancialExport($owner);

    $this->actingAs($owner)
        ->post(route('high_impact_operations.complete', $operation->operation_id), [
            'expected_revision' => 1,
            'payload_digest' => $operation->payload_digest,
        ])
        ->assertRedirect(route('passkey.confirmation'));

    $response = $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->post(route('high_impact_operations.complete', $operation->operation_id), [
            'expected_revision' => 1,
            'payload_digest' => $operation->payload_digest,
        ])
        ->assertSuccessful()
        ->assertDownload();
    $contents = $response->streamedContent();
    $decodedExport = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    expect($contents)
        ->toContain('Private export merchant')
        ->toContain('export-owner@example.test')
        ->toContain('export-source@example.test')
        ->not->toContain('not-exported-password')
        ->not->toContain('not-exported-secret')
        ->not->toContain('not-exported-recovery-codes')
        ->not->toContain('not-exported-access-token')
        ->not->toContain('not-exported-refresh-token')
        ->and($decodedExport['schema_version'])->toBe(1)
        ->and($decodedExport['transactions'])->toHaveCount(1)
        ->and($decodedExport['category_targets'][0]['applicable_revision_id'])->toBe($targetRevision->id)
        ->and($decodedExport['exported_at'])->toBeString()
        ->and($operation->fresh()->confirmed_at)->not->toBeNull()
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(1);

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->post(route('high_impact_operations.complete', $operation->operation_id), [
            'expected_revision' => 1,
            'payload_digest' => $operation->payload_digest,
        ])
        ->assertConflict();
});

test('changed or expired financial export preparation cannot be delivered', function (string $change) {
    Date::setTestNow('2026-08-01T12:00:00Z');
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $operation = prepareFinancialExport($owner);
    $preparedPayloadDigest = $operation->payload_digest;

    if ($change === 'owner data') {
        $transaction->update(['merchant_description' => 'Changed after preparation']);
    } elseif ($change === 'operation revision') {
        $operation->update(['revision' => 2]);
    } elseif ($change === 'payload') {
        $operation->update(['payload_digest' => str_repeat('0', 64)]);
    } elseif ($change === 'stored payload') {
        $payload = $operation->payload;
        $payload['transaction_count'] = 99;
        $operation->update(['payload' => $payload]);
    } else {
        Date::setTestNow(Date::now()->addMinutes(31));
    }

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->post(route('high_impact_operations.complete', $operation->operation_id), [
            'expected_revision' => 1,
            'payload_digest' => $preparedPayloadDigest,
        ])
        ->assertConflict();

    expect($operation->fresh()->confirmed_at)->toBeNull()
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(0);
})->with(['owner data', 'operation revision', 'payload', 'stored payload', 'expired']);

test('high-impact completion requires a passkey confirmed within the previous minute', function () {
    Date::setTestNow('2026-08-01T12:00:00Z');
    $owner = User::factory()->create();
    $operation = prepareFinancialExport($owner);

    $this->actingAs($owner)
        ->withSession([
            RequirePasskeyConfirmation::SESSION_KEY => Date::now()->subSeconds(61)->unix(),
        ])
        ->from(route('high_impact_operations.show', $operation->operation_id))
        ->post(route('high_impact_operations.complete', $operation->operation_id), [
            'expected_revision' => 1,
            'payload_digest' => $operation->payload_digest,
        ])
        ->assertRedirect(route('passkey.confirmation'));

    expect($operation->fresh()->confirmed_at)->toBeNull();
});

test('a superseded high-impact preparation cannot be reviewed or completed', function () {
    Date::setTestNow('2026-08-01T12:00:00Z');
    $owner = User::factory()->create();
    $operation = prepareFinancialExport($owner);
    $category = Category::factory()->for($owner, 'owner')->create();

    prepareFinancialDeletion($owner, $category);

    $this->actingAs($owner)
        ->get(route('high_impact_operations.show', $operation->operation_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('operation.status', 'canceled'));

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->post(route('high_impact_operations.complete', $operation->operation_id), [
            'expected_revision' => 1,
            'payload_digest' => $operation->payload_digest,
        ])
        ->assertConflict();
});

test('fresh passkey approval sends a prepared deletion through recoverable trash', function () {
    Date::setTestNow('2026-08-01T12:00:00Z');
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Retention-backed deletion',
    ]);
    $operation = prepareFinancialDeletion($owner, $category);

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->post(route('high_impact_operations.complete', $operation->operation_id), [
            'expected_revision' => 1,
            'payload_digest' => $operation->payload_digest,
        ])
        ->assertRedirect(route('categories.index'));

    $trashedCategory = Category::onlyTrashed()->findOrFail($category->id);

    expect($trashedCategory)
        ->deletion_id->not->toBeNull()
        ->purge_after->toEqual(Date::now()->addDays(30))
        ->and($operation->fresh()->confirmed_at)->not->toBeNull()
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(1);
});

test('a changed deletion target cannot be approved', function () {
    Date::setTestNow('2026-08-01T12:00:00Z');
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $operation = prepareFinancialDeletion($owner, $category);
    $category->update(['revision' => 2]);

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->post(route('high_impact_operations.complete', $operation->operation_id), [
            'expected_revision' => 1,
            'payload_digest' => $operation->payload_digest,
        ])
        ->assertConflict();

    expect($category->fresh()->deleted_at)->toBeNull()
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(0);
});

test('a changed stored deletion payload cannot broaden the approved target', function () {
    Date::setTestNow('2026-08-01T12:00:00Z');
    $owner = User::factory()->create();
    $approvedCategory = Category::factory()->for($owner, 'owner')->create();
    $differentCategory = Category::factory()->for($owner, 'owner')->create();
    $operation = prepareFinancialDeletion($owner, $approvedCategory);
    $payload = $operation->payload;
    $payload['resource_id'] = $differentCategory->id;
    $operation->update(['payload' => $payload]);

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->post(route('high_impact_operations.complete', $operation->operation_id), [
            'expected_revision' => 1,
            'payload_digest' => $operation->payload_digest,
        ])
        ->assertConflict();

    expect($approvedCategory->fresh()->deleted_at)->toBeNull()
        ->and($differentCategory->fresh()->deleted_at)->toBeNull()
        ->and(OpenClawConfirmationGrant::query()->count())->toBe(0);
});

test('prepared Receipt Breakdown deletion uses the same trash retention domain action', function () {
    Date::setTestNow('2026-08-01T12:00:00Z');
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $breakdown = ReceiptBreakdown::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->draft()
        ->create();
    $operation = prepareReceiptBreakdownDeletion($owner, $breakdown);

    $this->actingAs($owner)
        ->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->post(route('high_impact_operations.complete', $operation->operation_id), [
            'expected_revision' => 1,
            'payload_digest' => $operation->payload_digest,
        ])
        ->assertRedirect(route('transactions.index'));

    $trashedBreakdown = ReceiptBreakdown::onlyTrashed()->findOrFail($breakdown->id);

    expect($trashedBreakdown->purge_after)->toEqual(Date::now()->addDays(30))
        ->and($operation->fresh()->confirmed_at)->not->toBeNull();
});
