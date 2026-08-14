<?php

use App\Integrations\Gmail\GmailRequestFailed;
use App\Jobs\ProcessGmailMessage;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner sees the latest failed Gmail message and its retry action', function () {
    $connection = GmailConnection::factory()->create([
        'last_successful_sync_at' => now()->subMinute(),
    ]);
    $discovery = GmailMessageDiscovery::factory()->for($connection)->create();
    $payload = json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => ProcessGmailMessage::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => ProcessGmailMessage::class,
            'command' => serialize(new ProcessGmailMessage($discovery->id)),
        ],
    ], JSON_THROW_ON_ERROR);
    $failedJobUuid = app('queue.failer')->log(
        config('queue.default'),
        'default',
        $payload,
        GmailRequestFailed::messageIdentity(),
    );
    $discovery->update([
        'processing_failed_at' => now(),
        'last_error_code' => 'gmail_message_processing_failed',
        'failed_job_uuid' => $failedJobUuid,
    ]);
    $this->actingAs($connection->owner);

    $page = visit(route('connections.edit'));

    $page
        ->assertSee('Last successful synchronization')
        ->assertSee('Message processing failed')
        ->assertSee('gmail_message_processing_failed')
        ->assertSee('Retry message')
        ->press('Retry message')
        ->assertSee('The failed Gmail message was queued for retry.')
        ->assertDontSee('Retry message')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($discovery->fresh())
        ->processing_failed_at->toBeNull()
        ->last_error_code->toBeNull()
        ->failed_job_uuid->toBeNull();
});

test('the owner sees Gmail connection health without credentials reaching the page', function () {
    $connection = GmailConnection::factory()->create([
        'gmail_account_identity' => 'receipts@example.test',
        'access_token' => 'browser-hidden-access-token',
        'refresh_token' => 'browser-hidden-refresh-token',
    ]);
    $this->actingAs($connection->owner);

    $page = visit(route('connections.edit'));

    $page
        ->assertSee('Gmail')
        ->assertSee('Healthy')
        ->assertSee('receipts@example.test')
        ->assertSee('Read-only Gmail access')
        ->assertDontSee('browser-hidden-access-token')
        ->assertDontSee('browser-hidden-refresh-token')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
