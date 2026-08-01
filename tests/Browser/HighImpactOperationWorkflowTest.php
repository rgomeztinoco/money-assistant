<?php

use App\Actions\OpenClaw\PrepareFinancialExport;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner reviews a safe export summary in the protected web continuation', function () {
    Date::setTestNow('2026-08-01T12:00:00Z');
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Never render this private merchant',
    ]);
    $operation = app(PrepareFinancialExport::class)->handle(
        owner: $owner,
        serviceKeyId: 'openclaw-service-2026-07',
        schemaVersion: 1,
        conversationId: 'telegram-owner-123',
        preparationInteractionDigest: str_repeat('a', 64),
        preparationOccurredAt: CarbonImmutable::parse('2026-08-01T12:00:00Z'),
        idempotencyKey: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
    );
    $this->actingAs($owner);

    $page = visit(route('high_impact_operations.show', $operation->operation_id));

    $page
        ->assertSee('Download financial data')
        ->assertSee('Fresh passkey required')
        ->assertSee('Prepare a complete financial data export containing 1 Transaction.')
        ->assertSee('Confirm and download export')
        ->assertDontSee('Never render this private merchant')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
