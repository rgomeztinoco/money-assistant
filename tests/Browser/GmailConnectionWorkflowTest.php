<?php

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailMessageSummary;
use App\Integrations\Gmail\GmailRequestFailed;
use App\Jobs\ProcessGmailMessage;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\SpendingNotificationReference;
use App\Models\User;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Tests\Fakes\FakeGmail;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Vite::useHotFile(storage_path('framework/testing-vite-hot'));
});

test('the owner chooses the inbox import window before authorizing Gmail', function () {
    $this->actingAs(User::factory()->create());

    visit(route('data_sources.gmail'))
        ->assertVisible('[data-test="gmail-authorization-form"]')
        ->assertSeeIn('[data-test="gmail-authorization-form"]', 'Connect and import')
        ->assertValue('Import previous days', 30)
        ->assertAttribute('#gmail-import-days', 'min', 1)
        ->assertAttribute('#gmail-import-days', 'max', 365)
        ->fill('Import previous days', '90')
        ->assertValue('Import previous days', 90)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the owner sees a failed Gmail message and its retry action', function () {
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

    $page = visit(route('data_sources.gmail'));

    $page
        ->assertSee('Last successful import')
        ->assertSee('Processing failed')
        ->assertSee('Message processing stopped after repeated attempts.')
        ->assertSee('Retry email')
        ->press('Retry email')
        ->assertSee('Email queued for retry. Processing has not finished yet.')
        ->assertDontSee('Retry email')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($discovery->fresh())
        ->processing_failed_at->toBeNull()
        ->last_error_code->toBeNull()
        ->failed_job_uuid->toBeNull();
});

test('the owner sees and retries unsupported Gmail notifications', function () {
    $connection = GmailConnection::factory()->create([
        'last_successful_sync_at' => now()->subMinute(),
    ]);
    $discovery = GmailMessageDiscovery::factory()->for($connection)->create([
        'processed_at' => now()->subMinute(),
    ]);
    SpendingNotificationReference::factory()->create([
        'user_id' => $connection->user_id,
        'transaction_id' => null,
        'gmail_message_discovery_id' => $discovery->id,
        'gmail_account_identity' => $connection->gmail_account_identity,
        'message_id' => $discovery->message_id,
        'processing_outcome' => 'unsupported',
    ]);
    $this->actingAs($connection->owner);

    visit(route('data_sources.gmail'))
        ->assertSee('No supported format matched. The precise reason is unknown.')
        ->assertSee('Retry all unrecognized')
        ->press('Retry all unrecognized')
        ->assertSee('One unsupported Gmail notification was queued for retry.')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the owner can inspect, dismiss, and restore an unrecognized Gmail email', function () {
    $connection = GmailConnection::factory()->create([
        'last_successful_sync_at' => now()->subMinute(),
    ]);
    $discovery = GmailMessageDiscovery::factory()->for($connection)->create([
        'processed_at' => now(),
    ]);
    SpendingNotificationReference::factory()->create([
        'user_id' => $connection->user_id,
        'transaction_id' => null,
        'gmail_message_discovery_id' => $discovery->id,
        'gmail_account_identity' => $connection->gmail_account_identity,
        'message_id' => $discovery->message_id,
        'processing_outcome' => 'unsupported',
    ]);
    $gmail = new FakeGmail;
    $gmail->messageSummaries[$discovery->message_id] = new GmailMessageSummary(
        $discovery->message_id,
        'payment-alert-thread',
        now()->toImmutable(),
        'bank@example.test',
        'Payment alert',
    );
    app()->instance(Gmail::class, $gmail);
    $this->actingAs($connection->owner);

    visit(route('data_sources.gmail'))
        ->assertSee('Payment alert')
        ->assertSee('bank@example.test')
        ->assertSee('Open in Gmail')
        ->assertAttribute('a[href*="mail.google.com"]', 'target', '_blank')
        ->assertScript('document.querySelector(\'a[href*="mail.google.com"]\')?.href.includes("/#all/payment-alert-thread")')
        ->resize(390, 844)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->press('Dismiss')
        ->assertSee('No emails need attention')
        ->click('Dismissed 1')
        ->assertSee('Payment alert')
        ->press('Restore')
        ->assertSee('No dismissed emails')
        ->assertNoJavaScriptErrors();

    expect($discovery->fresh()->dismissed_at)->toBeNull();
});

test('the owner sees Gmail connection health without credentials reaching the page', function () {
    $connection = GmailConnection::factory()->create([
        'gmail_account_identity' => 'receipts@example.test',
        'access_token' => 'browser-hidden-access-token',
        'refresh_token' => 'browser-hidden-refresh-token',
    ]);
    $this->actingAs($connection->owner);

    $page = visit(route('data_sources.gmail'));

    $page
        ->assertSee('Gmail')
        ->assertSeeIn('#gmail [data-slot="badge"]', 'Connected')
        ->assertSee('receipts@example.test')
        ->assertSee('Read-only Gmail access')
        ->assertDontSee('browser-hidden-access-token')
        ->assertDontSee('browser-hidden-refresh-token')
        ->assertDontSee('Statement history')
        ->assertDontSee('Parser Profile')
        ->assertDontSee('Validate a format from Gmail')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('Gmail instants use the browser timezone and expose their exact value', function () {
    $connection = GmailConnection::factory()->create([
        'last_successful_sync_at' => '2026-09-18 02:30:00 UTC',
    ]);
    $this->actingAs($connection->owner);

    $page = visit(route('data_sources.gmail'))
        ->withLocale('en-US')
        ->withTimezone('America/Lima');

    $page
        ->assertSee('17 Sep 2026, 9:30 PM')
        ->assertScript(<<<'JS'
            (() => {
                const timestamp = document.querySelector(
                    '[data-test="gmail-last-successful-sync"] time',
                );

                return timestamp !== null
                    && timestamp.dateTime.startsWith('2026-09-18T02:30:00')
                    && timestamp.title.includes('America/Lima');
            })()
            JS);
    $page->wait(0.1);
    $page->script(<<<'JS'
        (() => {
            const nativeResolvedOptions =
                Intl.DateTimeFormat.prototype.resolvedOptions;
            Intl.DateTimeFormat.prototype.resolvedOptions = function () {
                return {
                    ...nativeResolvedOptions.call(this),
                    timeZone: 'Asia/Tokyo',
                };
            };
            globalThis.dispatchEvent(new Event('focus'));
        })()
        JS);
    $page
        ->waitForText('18 Sep 2026, 11:30 AM')
        ->assertSee('18 Sep 2026, 11:30 AM')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
