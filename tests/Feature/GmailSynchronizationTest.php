<?php

use App\Actions\NotificationIngestion\DispatchGmailSynchronizations;
use App\Actions\NotificationIngestion\SynchronizeGmailConnection;
use App\Contracts\Gmail;
use App\GmailSynchronizationType;
use App\Integrations\Gmail\GmailHistoryExpired;
use App\Integrations\Gmail\GmailHistoryPage;
use App\Integrations\Gmail\GmailMessage;
use App\Integrations\Gmail\GmailMessageIdentity;
use App\Integrations\Gmail\GmailMessagePage;
use App\Integrations\Gmail\GmailProfile;
use App\Integrations\Gmail\GmailRequestFailed;
use App\Jobs\ProcessGmailMessage;
use App\Jobs\SynchronizeGmail;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Tests\Fakes\FakeGmail;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('the initial synchronization imports the selected lookback window with an overlap', function () {
    CarbonImmutable::setTestNow('2026-07-28 18:05:00 UTC');
    Queue::fake();
    $connection = GmailConnection::factory()->create([
        'access_token' => 'current-access-token',
        'access_token_expires_at' => now()->addHour(),
        'connected_at' => '2026-07-28 18:00:00 UTC',
        'initial_sync_starts_at' => '2026-07-28 17:00:00 UTC',
        'history_id' => '100',
        'initial_sync_completed_at' => null,
        'last_successful_sync_at' => null,
    ]);
    $gmail = new FakeGmail;
    $gmail->messagePages = [
        new GmailMessagePage(
            messageIds: ['before-import-window', 'existing-message-1'],
            nextPageToken: 'second-page',
        ),
        new GmailMessagePage(
            messageIds: ['existing-message-1', 'new-message-2'],
            nextPageToken: null,
        ),
    ];
    $gmail->messageIdentities = [
        'before-import-window' => new GmailMessageIdentity(
            messageId: 'before-import-window',
            receivedAt: CarbonImmutable::parse('2026-07-28 16:59:59 UTC'),
        ),
        'existing-message-1' => new GmailMessageIdentity(
            messageId: 'existing-message-1',
            receivedAt: CarbonImmutable::parse('2026-07-28 17:00:00 UTC'),
        ),
        'new-message-2' => new GmailMessageIdentity(
            messageId: 'new-message-2',
            receivedAt: CarbonImmutable::parse('2026-07-28 18:04:00 UTC'),
        ),
    ];
    app()->instance(Gmail::class, $gmail);

    app(SynchronizeGmailConnection::class)->handle(
        $connection->id,
        GmailSynchronizationType::Incremental,
    );

    $connection->refresh();

    expect($gmail->messagesAfterCalls)->toBe([
        [
            'access_token' => 'current-access-token',
            'after_epoch_seconds' => CarbonImmutable::parse('2026-07-28 16:55:00 UTC')->getTimestamp(),
            'page_token' => null,
        ],
        [
            'access_token' => 'current-access-token',
            'after_epoch_seconds' => CarbonImmutable::parse('2026-07-28 16:55:00 UTC')->getTimestamp(),
            'page_token' => 'second-page',
        ],
    ])
        ->and($gmail->messageIdentityCalls)->toHaveCount(3)
        ->and($gmail->historyCalls)->toBeEmpty()
        ->and(GmailMessageDiscovery::query()
            ->orderBy('message_id')
            ->pluck('message_id')
            ->all())->toBe(['existing-message-1', 'new-message-2'])
        ->and(GmailMessageDiscovery::query()
            ->whereNull('processed_at')
            ->count())->toBe(2)
        ->and($connection->history_id)->toBe('100')
        ->and($connection->initial_sync_completed_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($connection->last_successful_sync_at?->toIso8601String())->toBe(now()->toIso8601String());

    Queue::assertPushed(ProcessGmailMessage::class, 2);
    Queue::assertPushed(
        ProcessGmailMessage::class,
        fn (ProcessGmailMessage $job): bool => $job->discoveryId === GmailMessageDiscovery::query()
            ->where('message_id', 'existing-message-1')
            ->value('id'),
    );
});

test('an uninitialized connection captures its current history cursor before the initial scan', function () {
    CarbonImmutable::setTestNow('2026-07-28 18:30:00 UTC');
    $connection = GmailConnection::factory()->create([
        'gmail_account_identity' => 'receipts@example.test',
        'access_token' => 'current-access-token',
        'access_token_expires_at' => now()->addHour(),
        'connected_at' => now(),
        'initial_sync_starts_at' => now()->subDays(30),
        'history_id' => null,
        'initial_sync_completed_at' => null,
        'last_successful_sync_at' => null,
    ]);
    $gmail = new FakeGmail;
    $gmail->profile = new GmailProfile(
        accountIdentity: 'receipts@example.test',
        historyId: 'captured-history-700',
    );
    $gmail->messagePages = [
        new GmailMessagePage(
            messageIds: ['new-message'],
            nextPageToken: null,
        ),
    ];
    $gmail->messageIdentities = [
        'new-message' => new GmailMessageIdentity(
            messageId: 'new-message',
            receivedAt: now()->subDay(),
        ),
    ];
    app()->instance(Gmail::class, $gmail);

    app(SynchronizeGmailConnection::class)->handle(
        $connection->id,
        GmailSynchronizationType::Incremental,
    );

    $connection->refresh();

    expect($gmail->operations)->toBe([
        'profile',
        'messages_after',
        'message_identity',
    ])
        ->and($connection->history_id)->toBe('captured-history-700')
        ->and($connection->initial_sync_completed_at)->not->toBeNull()
        ->and(GmailMessageDiscovery::query()->sole()->message_id)->toBe('new-message');
});

test('an existing supported inbox message in the selected window creates a Transaction', function () {
    CarbonImmutable::setTestNow('2026-08-01 12:00:00 UTC');
    Queue::fake();
    $connection = GmailConnection::factory()->create([
        'access_token' => 'current-access-token',
        'access_token_expires_at' => now()->addHour(),
        'connected_at' => now(),
        'initial_sync_starts_at' => now()->subDays(7),
        'history_id' => '100',
        'initial_sync_completed_at' => null,
        'last_successful_sync_at' => null,
    ]);
    $fixture = json_decode(
        (string) file_get_contents(resource_path('notification-formats/bcp-debit-card-spending.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $message = $fixture['message'];
    $gmailMessage = new GmailMessage(
        messageId: $message['message_id'],
        receivedAt: CarbonImmutable::parse($message['received_at']),
        fromAddress: $message['from_address'],
        subject: $message['subject'],
        authentication: $message['authentication'],
        textBody: null,
        htmlBody: $message['html_body'],
    );
    $gmail = new FakeGmail;
    $gmail->messagePages = [new GmailMessagePage(
        messageIds: [$gmailMessage->messageId],
        nextPageToken: null,
    )];
    $gmail->messageIdentities = [
        $gmailMessage->messageId => new GmailMessageIdentity(
            messageId: $gmailMessage->messageId,
            receivedAt: $gmailMessage->receivedAt,
        ),
    ];
    $gmail->messages = [$gmailMessage->messageId => $gmailMessage];
    app()->instance(Gmail::class, $gmail);

    app(SynchronizeGmailConnection::class)->handle(
        $connection->id,
        GmailSynchronizationType::Incremental,
    );

    $discovery = GmailMessageDiscovery::query()->sole();
    app()->call([(new ProcessGmailMessage($discovery->id)), 'handle']);

    expect(Transaction::query()->sole())
        ->description->toBe('SAMPLE MARKET')
        ->amount_minor->toBe(4590)
        ->and(SpendingNotificationReference::query()->sole())
        ->processing_outcome->toBe('created')
        ->and($discovery->fresh()->processed_at)->not->toBeNull();
});

test('incremental synchronization paginates added messages and advances to the final cursor idempotently', function () {
    CarbonImmutable::setTestNow('2026-07-28 19:00:00 UTC');
    $connection = GmailConnection::factory()->create([
        'access_token' => 'current-access-token',
        'access_token_expires_at' => now()->addHour(),
        'history_id' => '100',
        'initial_sync_completed_at' => now()->subHour(),
        'last_successful_sync_at' => now()->subMinute(),
    ]);
    GmailMessageDiscovery::factory()->for($connection, 'gmailConnection')->create([
        'message_id' => 'existing-message',
        'processed_at' => now()->subMinute(),
    ]);
    $gmail = new FakeGmail;
    $gmail->historyPages = [
        new GmailHistoryPage(
            messageIds: ['new-message-1', 'existing-message'],
            historyId: '150',
            nextPageToken: 'second-history-page',
        ),
        new GmailHistoryPage(
            messageIds: ['new-message-1', 'new-message-2'],
            historyId: '160',
            nextPageToken: null,
        ),
    ];
    app()->instance(Gmail::class, $gmail);

    app(SynchronizeGmailConnection::class)->handle(
        $connection->id,
        GmailSynchronizationType::Incremental,
    );

    $connection->refresh();

    expect($gmail->historyCalls)->toBe([
        [
            'access_token' => 'current-access-token',
            'start_history_id' => '100',
            'page_token' => null,
        ],
        [
            'access_token' => 'current-access-token',
            'start_history_id' => '100',
            'page_token' => 'second-history-page',
        ],
    ])
        ->and($gmail->messagesAfterCalls)->toBeEmpty()
        ->and(GmailMessageDiscovery::query()
            ->orderBy('message_id')
            ->pluck('message_id')
            ->all())->toBe(['existing-message', 'new-message-1', 'new-message-2'])
        ->and(GmailMessageDiscovery::query()
            ->where('message_id', 'existing-message')
            ->sole()
            ->processed_at)->not->toBeNull()
        ->and($connection->history_id)->toBe('160')
        ->and($connection->last_successful_sync_at?->toIso8601String())->toBe(now()->toIso8601String());
});

test('discovered work and its cursor advance roll back atomically when persistence fails', function () {
    CarbonImmutable::setTestNow('2026-07-28 19:30:00 UTC');
    $connection = GmailConnection::factory()->create([
        'access_token' => 'current-access-token',
        'access_token_expires_at' => now()->addHour(),
        'history_id' => '100',
        'initial_sync_completed_at' => now()->subHour(),
        'last_successful_sync_at' => now()->subMinute(),
    ]);
    $previousSuccessfulSync = $connection->last_successful_sync_at?->toIso8601String();
    $gmail = new FakeGmail;
    $gmail->historyPages = [
        new GmailHistoryPage(
            messageIds: ['valid-message', str_repeat('x', 256)],
            historyId: '200',
            nextPageToken: null,
        ),
    ];
    app()->instance(Gmail::class, $gmail);

    expect(fn () => app(SynchronizeGmailConnection::class)->handle(
        $connection->id,
        GmailSynchronizationType::Incremental,
    ))->toThrow(QueryException::class);

    $connection->refresh();

    expect(GmailMessageDiscovery::query()->count())->toBe(0)
        ->and($connection->history_id)->toBe('100')
        ->and($connection->last_successful_sync_at?->toIso8601String())->toBe($previousSuccessfulSync);
});

test('an expired cursor captures a fresh cursor before a bounded idempotent recovery scan', function () {
    CarbonImmutable::setTestNow('2026-07-28 20:00:00 UTC');
    $connection = GmailConnection::factory()->create([
        'gmail_account_identity' => 'receipts@example.test',
        'access_token' => 'current-access-token',
        'access_token_expires_at' => now()->addHour(),
        'connected_at' => now()->subDays(10),
        'initial_sync_starts_at' => now()->subDays(30),
        'history_id' => 'expired-history-100',
        'initial_sync_completed_at' => now()->subDays(10),
        'last_successful_sync_at' => '2026-07-28 19:50:00 UTC',
    ]);
    GmailMessageDiscovery::factory()->for($connection, 'gmailConnection')->create([
        'message_id' => 'already-discovered',
        'processed_at' => now()->subMinute(),
    ]);
    $gmail = new FakeGmail;
    $gmail->historyFailure = new GmailHistoryExpired;
    $gmail->profile = new GmailProfile(
        accountIdentity: 'receipts@example.test',
        historyId: 'fresh-history-500',
    );
    $gmail->messagePages = [
        new GmailMessagePage(
            messageIds: ['already-discovered', 'recovered-message-1'],
            nextPageToken: 'recovery-page-2',
        ),
        new GmailMessagePage(
            messageIds: ['recovered-message-2'],
            nextPageToken: null,
        ),
    ];
    app()->instance(Gmail::class, $gmail);

    app(SynchronizeGmailConnection::class)->handle(
        $connection->id,
        GmailSynchronizationType::Incremental,
    );

    $connection->refresh();

    expect($gmail->operations)->toBe([
        'history',
        'profile',
        'messages_after',
        'messages_after',
    ])
        ->and($gmail->messagesAfterCalls)->toBe([
            [
                'access_token' => 'current-access-token',
                'after_epoch_seconds' => 1785267900,
                'page_token' => null,
            ],
            [
                'access_token' => 'current-access-token',
                'after_epoch_seconds' => 1785267900,
                'page_token' => 'recovery-page-2',
            ],
        ])
        ->and(GmailMessageDiscovery::query()
            ->orderBy('message_id')
            ->pluck('message_id')
            ->all())->toBe([
                'already-discovered',
                'recovered-message-1',
                'recovered-message-2',
            ])
        ->and($connection->history_id)->toBe('fresh-history-500')
        ->and($connection->last_successful_sync_at?->toIso8601String())->toBe(now()->toIso8601String());
});

test('reconciliation scans an overlapping seven-day window without duplicating discovered work', function () {
    CarbonImmutable::setTestNow('2026-07-29 12:00:00 UTC');
    $connection = GmailConnection::factory()->create([
        'access_token' => 'current-access-token',
        'access_token_expires_at' => now()->addHour(),
        'connected_at' => now()->subMonth(),
        'initial_sync_starts_at' => now()->subMonths(2),
        'history_id' => 'current-history-600',
        'initial_sync_completed_at' => now()->subMonth(),
        'last_successful_sync_at' => now()->subMinute(),
    ]);
    GmailMessageDiscovery::factory()->for($connection, 'gmailConnection')->create([
        'message_id' => 'already-discovered',
        'processed_at' => now()->subMinute(),
    ]);
    $gmail = new FakeGmail;
    $gmail->messagePages = [
        new GmailMessagePage(
            messageIds: ['already-discovered', 'reconciled-message'],
            nextPageToken: null,
        ),
    ];
    app()->instance(Gmail::class, $gmail);

    app(SynchronizeGmailConnection::class)->handle(
        $connection->id,
        GmailSynchronizationType::Reconciliation,
    );

    $connection->refresh();

    expect($gmail->messagesAfterCalls)->toBe([
        [
            'access_token' => 'current-access-token',
            'after_epoch_seconds' => 1784721600,
            'page_token' => null,
        ],
    ])
        ->and($gmail->historyCalls)->toBeEmpty()
        ->and(GmailMessageDiscovery::query()
            ->orderBy('message_id')
            ->pluck('message_id')
            ->all())->toBe(['already-discovered', 'reconciled-message'])
        ->and($connection->history_id)->toBe('current-history-600')
        ->and($connection->last_successful_sync_at?->toIso8601String())->toBe(now()->toIso8601String());
});

test('reconciliation does not scan before the selected initial import window', function () {
    CarbonImmutable::setTestNow('2026-07-29 12:00:00 UTC');
    $connection = GmailConnection::factory()->create([
        'access_token' => 'current-access-token',
        'access_token_expires_at' => now()->addHour(),
        'connected_at' => now()->subDay(),
        'initial_sync_starts_at' => now()->subDays(2),
        'history_id' => 'current-history-700',
        'initial_sync_completed_at' => now()->subDay(),
    ]);
    $gmail = new FakeGmail;
    $gmail->messagePages = [new GmailMessagePage(messageIds: [], nextPageToken: null)];
    app()->instance(Gmail::class, $gmail);

    app(SynchronizeGmailConnection::class)->handle(
        $connection->id,
        GmailSynchronizationType::Reconciliation,
    );

    expect($gmail->messagesAfterCalls)->toBe([[
        'access_token' => 'current-access-token',
        'after_epoch_seconds' => now()->subDays(2)->getTimestamp(),
        'page_token' => null,
    ]]);
});

test('synchronization dispatch queues active connections with content-free unique jobs', function () {
    config()->set('cache.default', 'array');
    Queue::fake();
    $activeConnection = GmailConnection::factory()->create();
    $dispatch = app(DispatchGmailSynchronizations::class);

    $dispatch->handle(GmailSynchronizationType::Incremental);
    $dispatch->handle(GmailSynchronizationType::Reconciliation);
    $activeConnection->forceFill(['reauthorization_required_at' => now()])->save();
    $dispatch->handle(GmailSynchronizationType::Incremental);

    Queue::assertPushed(SynchronizeGmail::class, 2);
    Queue::assertPushed(
        SynchronizeGmail::class,
        function (SynchronizeGmail $job) use ($activeConnection): bool {
            expect(array_keys(get_object_vars($job)))
                ->not->toContain('subject', 'body', 'rawMime', 'messageId');

            return $job->connectionId === $activeConnection->id
                && $job->type === GmailSynchronizationType::Incremental
                && $job->uniqueId() === "{$activeConnection->id}:incremental";
        },
    );
    Queue::assertPushed(
        SynchronizeGmail::class,
        fn (SynchronizeGmail $job): bool => $job->connectionId === $activeConnection->id
            && $job->type === GmailSynchronizationType::Reconciliation,
    );
});

test('the scheduler queues minute polling and daily seven-day reconciliation', function () {
    CarbonImmutable::setTestNow('2026-07-30 00:00:00 UTC');
    config()->set('cache.default', 'array');
    Queue::fake();
    Schedule::useCache('array');
    $connection = GmailConnection::factory()->create();

    $this->artisan('schedule:run')->assertSuccessful();

    Queue::assertPushed(
        SynchronizeGmail::class,
        fn (SynchronizeGmail $job): bool => $job->connectionId === $connection->id
            && $job->type === GmailSynchronizationType::Incremental,
    );
    Queue::assertPushed(
        SynchronizeGmail::class,
        fn (SynchronizeGmail $job): bool => $job->connectionId === $connection->id
            && $job->type === GmailSynchronizationType::Reconciliation,
    );
});

test('Gmail jobs use bounded Laravel queue retries and backoff', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00:00 UTC'));
    $connection = GmailConnection::factory()->create([
        'access_token_expires_at' => now()->addDays(2),
        'history_id' => 'history-42',
        'initial_sync_completed_at' => now()->subHour(),
    ]);
    $gmail = new FakeGmail;
    $gmail->historyFailure = GmailRequestFailed::history();
    app()->instance(Gmail::class, $gmail);
    $job = new SynchronizeGmail(
        $connection->id,
        GmailSynchronizationType::Incremental,
    );

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([60, 300, 900])
        ->and((new ProcessGmailMessage(42))->tries)->toBe(5)
        ->and((new ProcessGmailMessage(42))->backoff())->toBe([60, 300, 900]);

    expect(fn () => app()->call([$job, 'handle']))
        ->toThrow(GmailRequestFailed::class);
});

test('failed Gmail jobs retain focused failure state', function () {
    $connection = GmailConnection::factory()->create();
    $discovery = GmailMessageDiscovery::factory()->for($connection)->create();

    (new SynchronizeGmail(
        $connection->id,
        GmailSynchronizationType::Incremental,
    ))->failed(GmailRequestFailed::history());
    (new ProcessGmailMessage($discovery->id))->failed(GmailRequestFailed::messageIdentity());

    $connection->refresh();
    $discovery->refresh();

    expect($connection->last_synchronization_failed_at)->not->toBeNull()
        ->and($connection->last_synchronization_error_code)->toBe('gmail_synchronization_failed')
        ->and($discovery->processing_failed_at)->not->toBeNull()
        ->and($discovery->last_error_code)->toBe('gmail_message_processing_failed');
});
