<?php

use App\Actions\NotificationIngestion\ProcessSpendingNotification;
use App\Actions\NotificationIngestion\SynchronizeParserProfileAlerts;
use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailMessage;
use App\Integrations\Gmail\GmailMessageSummary;
use App\Jobs\ProcessGmailMessage;
use App\Models\AiClassificationRequest;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
use App\Models\ParserProfileVersion;
use App\Models\Reminder;
use App\Models\SpendingNotificationFormat;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;
use App\SpendingNotificationFormatPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeGmail;

beforeEach(function () {
    config()->set('inertia.ssr.enabled', false);
});

test('Gmail message processing is rate limited without putting content in the job payload', function () {
    $job = new ProcessGmailMessage(44);
    $middleware = $job->middleware();

    expect($job->discoveryId)->toBe(44)
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(RateLimited::class)
        ->and($middleware[0]->releaseAfter)->toBe(60);
});

test('the owner can inspect a discovered Gmail message as a transient Parser Profile source', function () {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()->for($owner, 'owner')->create();
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-44']);
    $gmail = new FakeGmail;
    $gmail->messages['gmail-message-44'] = new GmailMessage(
        messageId: 'gmail-message-44',
        receivedAt: CarbonImmutable::parse('2026-07-30 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'Purchase approved',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'pass', 'domain' => 'bank.example'],
        ],
        textBody: "Purchase approved\nAmount: S/ 125.40\nDate: 30/07/2026\nMerchant: MARKET ONE",
        htmlBody: null,
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->get(route('parser_profiles.source_messages.show', $discovery))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('parser-profiles/create')
            ->where('source.message_id', 'gmail-message-44')
            ->where('source.from_address', 'alerts@bank.example')
            ->where('source.subject', 'Purchase approved')
            ->where('source.authentication.dmarc.result', 'pass')
            ->where('source.authentication.dmarc.aligned', true)
            ->where('source.mime_parts.text_plain', "Purchase approved\nAmount: S/ 125.40\nDate: 30/07/2026\nMerchant: MARKET ONE")
            ->where('source.mime_parts.text_html', null),
        );

    expect($gmail->messageCalls)->toBe([[
        'access_token' => $connection->access_token,
        'message_id' => 'gmail-message-44',
    ]]);

    $storedValues = collect(DB::select(
        'SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = current_schema()',
    ));

    expect($storedValues)->not->toBeEmpty()
        ->and(DB::table((new GmailMessageDiscovery)->getTable())->where('message_id', 'gmail-message-44')->value('processed_at'))->toBeNull();
});

test('the Parser Profiles page identifies available Gmail source messages without retaining their metadata', function () {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()->for($owner, 'owner')->create();
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-summary']);
    $gmail = new FakeGmail;
    $gmail->messageSummaries[$discovery->message_id] = new GmailMessageSummary(
        messageId: $discovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-30 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'Purchase approved for your card',
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->get(route('parser_profiles.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('parser-profiles/index')
            ->has('source_messages', 1)
            ->where('source_messages.0.id', $discovery->id)
            ->where('source_messages.0.from_address', 'alerts@bank.example')
            ->where('source_messages.0.subject', 'Purchase approved for your card')
            ->where('source_messages.0.received_at', '2026-07-30T14:15:00+00:00'),
        );

    expect($gmail->messageSummaryCalls)->toBe([[
        'access_token' => $connection->access_token,
        'message_id' => $discovery->message_id,
    ]])
        ->and(array_keys($discovery->fresh()->getAttributes()))
        ->not->toContain('subject', 'from_address');
});

test('the owner previews candidate Transaction values before approving a Parser Profile', function () {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()->for($owner, 'owner')->create();
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-preview']);
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = parserProfileMessage(
        $discovery->message_id,
        'S/ 125.40',
        'MARKET ONE',
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->from(route('parser_profiles.source_messages.show', $discovery))
        ->post(
            route('parser_profile_previews.store'),
            parserProfilePayload($discovery),
        )
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('parser_profiles.source_messages.show', $discovery))
        ->assertInertiaFlash('parser_profile_preview.occurred_on', '2026-07-30')
        ->assertInertiaFlash('parser_profile_preview.amount_minor', '12540')
        ->assertInertiaFlash('parser_profile_preview.currency', 'PEN')
        ->assertInertiaFlash('parser_profile_preview.kind', 'purchase')
        ->assertInertiaFlash('parser_profile_preview.merchant_description', 'MARKET ONE')
        ->assertInertiaFlash('parser_profile_preview.provisional_fields', []);

    expect(ParserProfile::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0)
        ->and(SpendingNotificationReference::query()->count())->toBe(0)
        ->and($discovery->fresh()->processed_at)->toBeNull();
});

test('the owner can confirm a deterministic Parser Profile and process its source message', function () {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-profile-source']);
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = new GmailMessage(
        messageId: $discovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-30 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'Purchase approved for your card',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'pass', 'domain' => 'bank.example'],
        ],
        textBody: "Purchase approved\nAmount: S/ 125.40\nDate: 30/07/2026\nMerchant: MARKET ONE\nThank you",
        htmlBody: null,
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->post(route('parser_profiles.store'), [
            'source_message_discovery_id' => $discovery->id,
            'profile_name' => 'Bank card alerts',
            'format_name' => 'Card purchase',
            'format_purpose' => 'spending',
            'authentication_mechanism' => 'dmarc',
            'mime_source' => 'text_plain',
            'subject_marker' => 'Purchase approved',
            'body_marker' => 'Amount:',
            'amount_prefix' => 'Amount: ',
            'amount_suffix' => '\n',
            'decimal_separator' => '.',
            'grouping_separator' => 'none',
            'currency_position' => 'before',
            'currency_token' => 'S/',
            'currency' => 'PEN',
            'date_prefix' => 'Date: ',
            'date_suffix' => '\n',
            'date_format' => 'd/m/Y',
            'timezone' => 'America/Lima',
            'amount_semantics' => 'absolute',
            'kind_semantics' => 'fixed_purchase',
            'merchant_prefix' => 'Merchant: ',
            'merchant_suffix' => '\n',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('parser_profiles.index'));

    $profile = ParserProfile::query()->sole();
    $version = ParserProfileVersion::query()->sole();
    $format = SpendingNotificationFormat::query()->sole();
    $transaction = Transaction::query()->sole();
    $reference = SpendingNotificationReference::query()->sole();

    expect($profile->user_id)->toBe($owner->id)
        ->and($profile->name)->toBe('Bank card alerts')
        ->and($profile->current_version)->toBe(1)
        ->and($profile->enabled_at)->not->toBeNull()
        ->and($version->parser_profile_id)->toBe($profile->id)
        ->and($version->trusted_sender_address)->toBe('alerts@bank.example')
        ->and($version->authentication_mechanism)->toBe('dmarc')
        ->and($version->authenticated_domain)->toBe('bank.example')
        ->and($version->source_message_id)->toBe($discovery->message_id)
        ->and($format->parser_profile_version_id)->toBe($version->id)
        ->and($format->mime_source)->toBe('text_plain')
        ->and($format->definition['amount']['currency_mapping'])->toBe(['S/' => 'PEN'])
        ->and($format->definition['amount']['decimal_separator'])->toBe('.')
        ->and($format->definition['amount']['semantics'])->toBe('absolute')
        ->and($format->definition['date']['format'])->toBe('d/m/Y')
        ->and($format->definition['date']['timezone'])->toBe('America/Lima')
        ->and($format->definition['kind']['semantics'])->toBe('fixed_purchase')
        ->and($transaction->occurred_on->toDateString())->toBe('2026-07-30')
        ->and($transaction->amount_minor)->toBe(12540)
        ->and($transaction->currency->value)->toBe('PEN')
        ->and($transaction->kind->value)->toBe('purchase')
        ->and($transaction->merchant_description)->toBe('MARKET ONE')
        ->and($transaction->provisional_fields)->toBe([])
        ->and($reference->transaction_id)->toBe($transaction->id)
        ->and($reference->message_id)->toBe($discovery->message_id)
        ->and($reference->processing_outcome)->toBe('created')
        ->and($reference->spending_notification_format_id)->toBe($format->id)
        ->and($discovery->fresh()->processed_at)->not->toBeNull()
        ->and(AiClassificationRequest::query()->where('transaction_id', $transaction->id)->exists())->toBeTrue();

    expect(json_encode($format->getAttributes()))
        ->not->toContain($gmail->messages[$discovery->message_id]->textBody)
        ->not->toContain('Purchase approved for your card');
});

test('uncertain occurrence date and merchant create one grouped review while totals update immediately', function () {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-needs-review']);
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = new GmailMessage(
        messageId: $discovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-30 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'Purchase approved for your card',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'pass', 'domain' => 'bank.example'],
        ],
        textBody: "Purchase approved\nAmount: $ 42.75\nNo additional details",
        htmlBody: null,
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->post(route('parser_profiles.store'), parserProfilePayload($discovery, [
            'currency_token' => '$',
            'currency' => 'USD',
        ]))
        ->assertSessionHasNoErrors();

    $transaction = Transaction::query()->sole();

    expect($transaction->occurred_on->toDateString())->toBe('2026-07-30')
        ->and($transaction->merchant_description)->toBe('Unknown merchant')
        ->and($transaction->provisional_fields)->toBe([
            'occurred_on',
            'merchant_description',
        ])
        ->and(SpendingNotificationReference::query()->sole()->processing_outcome)
        ->toBe('created_with_review');

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_field_count', 2)
            ->has('transactions', 1)
            ->has('transactions.0.fields', 2)
            ->where('transactions.0.id', $transaction->id),
        );

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '4275')
            ->has('transactions', 1)
            ->where('transactions.0.id', $transaction->id),
        );
});

test('reprocessing the same Gmail message returns its original outcome without another Transaction', function () {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-idempotent']);
    $message = new GmailMessage(
        messageId: $discovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-30 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'Purchase approved for your card',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'pass', 'domain' => 'bank.example'],
        ],
        textBody: "Purchase approved\nAmount: S/ 125.40\nDate: 30/07/2026\nMerchant: MARKET ONE\nThank you",
        htmlBody: null,
    );
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = $message;
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->post(route('parser_profiles.store'), parserProfilePayload($discovery))
        ->assertSessionHasNoErrors();

    $originalReference = SpendingNotificationReference::query()->sole();
    $replayedReference = app(ProcessSpendingNotification::class)->handle(
        owner: $owner,
        discovery: $discovery,
        message: $message,
    );

    expect($replayedReference->is($originalReference))->toBeTrue()
        ->and($replayedReference->transaction_id)->toBe($originalReference->transaction_id)
        ->and(SpendingNotificationReference::query()->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(1)
        ->and(AiClassificationRequest::query()->count())->toBe(1);
});

test('a queued discovery uses an enabled Parser Profile for a future message', function () {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $sourceDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-profile-source']);
    $futureDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-future']);
    $gmail = new FakeGmail;
    $gmail->messages[$sourceDiscovery->message_id] = parserProfileMessage(
        $sourceDiscovery->message_id,
        'S/ 125.40',
        'MARKET ONE',
    );
    $gmail->messages[$futureDiscovery->message_id] = parserProfileMessage(
        $futureDiscovery->message_id,
        'S/ 54.32',
        'MARKET TWO',
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->post(route('parser_profiles.store'), parserProfilePayload($sourceDiscovery))
        ->assertSessionHasNoErrors();

    ProcessGmailMessage::dispatchSync($futureDiscovery->id);

    expect(Transaction::query()->count())->toBe(2)
        ->and(Transaction::query()->latest('id')->value('amount_minor'))->toBe(5432)
        ->and(Transaction::query()->latest('id')->value('merchant_description'))->toBe('MARKET TWO')
        ->and(SpendingNotificationReference::query()->count())->toBe(2)
        ->and($futureDiscovery->fresh()->processed_at)->not->toBeNull();
});

test('authentication failures retain only a sanitized outcome and raise one grouped security alert', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Bank card alerts']);
    $version = ParserProfileVersion::factory()->for($profile, 'profile')->create();
    SpendingNotificationFormat::factory()->for($version, 'profileVersion')->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-authentication-failure']);
    $secondDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-authentication-failure-two']);
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = new GmailMessage(
        messageId: $discovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-31 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'Purchase approved for your card',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'fail', 'domain' => 'bank.example'],
        ],
        textBody: "Purchase approved\nAmount: S/ 55.00\nDate: 31/07/2026\nMerchant: MARKET TWO\nThank you",
        htmlBody: null,
    );
    $gmail->messages[$secondDiscovery->message_id] = new GmailMessage(
        messageId: $secondDiscovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-31 14:20:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'Purchase approved for your card',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'fail', 'domain' => 'bank.example'],
        ],
        textBody: "Purchase approved\nAmount: S/ 25.00\nDate: 31/07/2026\nMerchant: MARKET THREE\nThank you",
        htmlBody: null,
    );
    app()->instance(Gmail::class, $gmail);

    ProcessGmailMessage::dispatchSync($discovery->id);
    ProcessGmailMessage::dispatchSync($secondDiscovery->id);

    $reference = SpendingNotificationReference::query()
        ->where('message_id', $discovery->message_id)
        ->sole();

    expect(Transaction::query()->count())->toBe(0)
        ->and(Reminder::query()->count())->toBe(1)
        ->and(Reminder::query()->sole()->subject)
        ->toBe('Review grouped security failures for Bank card alerts')
        ->and($reference->transaction_id)->toBeNull()
        ->and($reference->processing_outcome)->toBe('authentication_failed')
        ->and($reference->parser_profile_version_id)->toBe($version->id)
        ->and($reference->spending_notification_format_id)->toBeNull()
        ->and($reference->gmail_message_discovery_id)->toBe($discovery->id)
        ->and($discovery->fresh()->processed_at)->not->toBeNull()
        ->and($secondDiscovery->fresh()->processed_at)->not->toBeNull()
        ->and(array_keys($reference->getAttributes()))
        ->not->toContain('subject', 'body', 'raw_mime', 'headers');

    $this->actingAs($owner)
        ->get(route('parser_profiles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profiles.0.health.state', 'degraded')
            ->where('profiles.0.health.counts.failed', 2)
            ->where('profiles.0.health.counts.created', 0)
            ->where('profiles.0.health.oldest_unresolved_failure', $reference->created_at?->toIso8601String())
            ->has('alerts', 1)
            ->where('alerts.0.kind', 'security')
            ->where('alerts.0.profile_id', $profile->id)
            ->where('alerts.0.count', 2)
            ->has('alerts.0.references', 2)
            ->where('alerts.0.references.0.id', $reference->id)
            ->where('alerts.0.references.0.outcome', 'authentication_failed'),
        );
});

test('a message matching more than one sender is attributed to the oldest approved profile deterministically', function () {
    $owner = User::factory()->create();
    $oldestProfile = ParserProfile::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'First approved alerts']);
    $oldestVersion = ParserProfileVersion::factory()
        ->for($oldestProfile, 'profile')
        ->create();
    $newestProfile = ParserProfile::factory()
        ->for($owner, 'owner')
        ->create(['name' => 'Second approved alerts']);
    ParserProfileVersion::factory()
        ->for($newestProfile, 'profile')
        ->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-shared-sender']);
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = new GmailMessage(
        messageId: $discovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-31 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'Purchase approved for your card',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'fail', 'domain' => 'bank.example'],
        ],
        textBody: 'Authentication failure content is not retained.',
        htmlBody: null,
    );
    app()->instance(Gmail::class, $gmail);

    ProcessGmailMessage::dispatchSync($discovery->id);

    expect(SpendingNotificationReference::query()->sole()->parser_profile_version_id)
        ->toBe($oldestVersion->id)
        ->and(Reminder::query()->sole()->subject)
        ->toBe('Review grouped security failures for First approved alerts');
});

test('an authenticated unknown format raises a grouped drift alert without creating a Transaction', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    $version = ParserProfileVersion::factory()->for($profile, 'profile')->create();
    SpendingNotificationFormat::factory()->for($version, 'profileVersion')->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-unknown-format']);
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = new GmailMessage(
        messageId: $discovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-31 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'Your monthly card statement is ready',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'pass', 'domain' => 'bank.example'],
        ],
        textBody: 'Review your monthly statement in online banking.',
        htmlBody: null,
    );
    app()->instance(Gmail::class, $gmail);

    ProcessGmailMessage::dispatchSync($discovery->id);

    $reference = SpendingNotificationReference::query()->sole();

    expect(Transaction::query()->count())->toBe(0)
        ->and($reference->processing_outcome)->toBe('unsupported')
        ->and($reference->parser_profile_version_id)->toBe($version->id)
        ->and($reference->spending_notification_format_id)->toBeNull()
        ->and($discovery->fresh()->processed_at)->not->toBeNull();

    $this->actingAs($owner)
        ->get(route('parser_profiles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profiles.0.health.counts.unsupported', 1)
            ->where('profiles.0.health.state', 'degraded')
            ->has('alerts', 1)
            ->where('alerts.0.kind', 'drift')
            ->where('alerts.0.count', 1)
            ->where('alerts.0.references.0.outcome', 'unsupported'),
        );
});

test('a gating extraction failure raises drift without creating a Transaction', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    $version = ParserProfileVersion::factory()->for($profile, 'profile')->create();
    $format = SpendingNotificationFormat::factory()
        ->for($version, 'profileVersion')
        ->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-extraction-failure']);
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = new GmailMessage(
        messageId: $discovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-31 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'Purchase approved for your card',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'pass', 'domain' => 'bank.example'],
        ],
        textBody: "Purchase approved\nAmount: unavailable\nDate: 31/07/2026\nMerchant: MARKET TWO\nThank you",
        htmlBody: null,
    );
    app()->instance(Gmail::class, $gmail);

    ProcessGmailMessage::dispatchSync($discovery->id);

    $reference = SpendingNotificationReference::query()->sole();

    expect(Transaction::query()->count())->toBe(0)
        ->and($reference->processing_outcome)->toBe('failed')
        ->and($reference->parser_profile_version_id)->toBe($version->id)
        ->and($reference->spending_notification_format_id)->toBe($format->id)
        ->and($discovery->fresh()->processed_at)->not->toBeNull();

    $this->actingAs($owner)
        ->get(route('parser_profiles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profiles.0.health.counts.failed', 1)
            ->where('alerts.0.kind', 'drift')
            ->where('alerts.0.references.0.outcome', 'failed'),
        );
});

test('an explicitly approved non-spending format is ignored without review noise', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    $version = ParserProfileVersion::factory()->for($profile, 'profile')->create();
    SpendingNotificationFormat::factory()
        ->for($version, 'profileVersion')
        ->create([
            'purpose' => 'ignore',
            'definition' => [
                'subject_marker' => 'Statement ready',
                'body_marker' => 'View your statement',
            ],
        ]);
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-known-non-spending']);
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = new GmailMessage(
        messageId: $discovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-31 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'Statement ready for July',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'pass', 'domain' => 'bank.example'],
        ],
        textBody: 'View your statement in online banking.',
        htmlBody: null,
    );
    app()->instance(Gmail::class, $gmail);

    ProcessGmailMessage::dispatchSync($discovery->id);

    expect(Transaction::query()->count())->toBe(0)
        ->and(SpendingNotificationReference::query()->sole()->processing_outcome)
        ->toBe('ignored')
        ->and($discovery->fresh()->processed_at)->not->toBeNull();

    $this->actingAs($owner)
        ->get(route('parser_profiles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profiles.0.health.state', 'healthy')
            ->where('profiles.0.health.counts.ignored', 1)
            ->has('alerts', 0),
        );
});

test('the owner approves an ignored format without changing other unsupported messages', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    $version = ParserProfileVersion::factory()->for($profile, 'profile')->create();
    SpendingNotificationFormat::factory()->for($version, 'profileVersion')->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $selectedDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-statement-selected']);
    $unchangedDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-statement-unchanged']);
    $gmail = new FakeGmail;

    foreach ([$selectedDiscovery, $unchangedDiscovery] as $discovery) {
        $gmail->messages[$discovery->message_id] = new GmailMessage(
            messageId: $discovery->message_id,
            receivedAt: CarbonImmutable::parse('2026-07-31 14:15:00 UTC'),
            fromAddress: 'alerts@bank.example',
            subject: 'Statement ready for July',
            authentication: [
                'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
                'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
                'dmarc' => ['result' => 'pass', 'domain' => 'bank.example'],
            ],
            textBody: 'View your statement in online banking.',
            htmlBody: null,
        );
    }

    app()->instance(Gmail::class, $gmail);
    ProcessGmailMessage::dispatchSync($selectedDiscovery->id);
    ProcessGmailMessage::dispatchSync($unchangedDiscovery->id);

    $this->actingAs($owner)
        ->post(route('parser_profiles.store'), parserProfilePayload($selectedDiscovery, [
            'parser_profile_id' => $profile->id,
            'profile_name' => null,
            'format_name' => 'Monthly statement',
            'format_purpose' => 'ignore',
            'subject_marker' => 'Statement ready',
            'body_marker' => 'View your statement',
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('parser_profiles.index'));

    $profile->refresh();
    $selectedReference = SpendingNotificationReference::query()
        ->where('message_id', $selectedDiscovery->message_id)
        ->sole();
    $unchangedReference = SpendingNotificationReference::query()
        ->where('message_id', $unchangedDiscovery->message_id)
        ->sole();

    expect($profile->current_version)->toBe(2)
        ->and($profile->versions()->where('version', 2)->sole()->formats()->count())
        ->toBe(2)
        ->and($selectedReference->processing_outcome)->toBe('ignored')
        ->and($selectedReference->attempt_count)->toBe(2)
        ->and($selectedReference->format?->purpose)->toBe(SpendingNotificationFormatPurpose::Ignore)
        ->and($unchangedReference->processing_outcome)->toBe('unsupported')
        ->and($unchangedReference->attempt_count)->toBe(1)
        ->and(Transaction::query()->count())->toBe(0);
});

test('the owner manually records an unsupported purchase linked to its original notification reference', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    $version = ParserProfileVersion::factory()->for($profile, 'profile')->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create([
            'message_id' => 'gmail-manual-recovery',
            'processed_at' => now(),
        ]);
    $reference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($version, 'profileVersion')
        ->for($discovery, 'discovery')
        ->create([
            'transaction_id' => null,
            'gmail_account_identity' => $connection->gmail_account_identity,
            'message_id' => $discovery->message_id,
            'processing_outcome' => 'unsupported',
        ]);
    app(SynchronizeParserProfileAlerts::class)->handle($owner, $profile->id);
    $reminder = Reminder::query()->sole();

    $this->actingAs($owner)
        ->post(route('spending_notification_references.recovery.store', $reference), [
            'occurred_on' => '2026-07-31',
            'amount_minor' => 4590,
            'currency' => 'PEN',
            'kind' => 'purchase',
            'merchant_description' => 'Neighborhood market',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('parser_profiles.index'));

    $transaction = Transaction::query()->sole();
    $reference->refresh();

    expect($reference->transaction_id)->toBe($transaction->id)
        ->and($reference->processing_outcome)->toBe('created')
        ->and($reference->attempt_count)->toBe(1)
        ->and($transaction->amount_minor)->toBe(4590)
        ->and($transaction->merchant_description)->toBe('Neighborhood market')
        ->and($transaction->spendingNotificationReferences()->sole()->is($reference))
        ->toBeTrue()
        ->and($reminder->fresh()->resolved_at)->not->toBeNull();

    $this->actingAs($owner)
        ->post(route('spending_notification_references.recovery.store', $reference), [
            'occurred_on' => '2026-07-31',
            'amount_minor' => 4590,
            'currency' => 'PEN',
            'kind' => 'purchase',
            'merchant_description' => 'Neighborhood market',
        ])
        ->assertSessionHasErrors('recovery');

    expect(Transaction::query()->count())->toBe(1)
        ->and($reference->fresh()->transaction_id)->toBe($transaction->id);

    $this->get(route('parser_profiles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profiles.0.health.state', 'healthy')
            ->where('profiles.0.health.counts.created', 1)
            ->where(
                'profiles.0.health.last_success',
                $reference->last_attempted_at?->toIso8601String(),
            )
            ->where('profiles.0.health.oldest_unresolved_failure', null)
            ->has('alerts', 0),
        );
});

test('an authentication failure cannot be converted into a manual Transaction', function () {
    $owner = User::factory()->create();
    $reference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->create([
            'transaction_id' => null,
            'processing_outcome' => 'authentication_failed',
        ]);

    $this->actingAs($owner)
        ->post(route('spending_notification_references.recovery.store', $reference), [
            'occurred_on' => '2026-07-31',
            'amount_minor' => 4590,
            'currency' => 'PEN',
            'kind' => 'purchase',
            'merchant_description' => 'Untrusted message',
        ])
        ->assertSessionHasErrors('recovery');

    expect(Transaction::query()->count())->toBe(0)
        ->and($reference->fresh()->processing_outcome)
        ->toBe('authentication_failed');
});

test('a gating extraction failure cannot be retried through a profile change', function () {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create([
            'message_id' => 'gmail-failed-retry',
            'processed_at' => now(),
        ]);
    $reference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($discovery, 'discovery')
        ->create([
            'transaction_id' => null,
            'gmail_account_identity' => $connection->gmail_account_identity,
            'message_id' => $discovery->message_id,
            'processing_outcome' => 'failed',
        ]);
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = parserProfileMessage(
        $discovery->message_id,
        'S/ 72.10',
        'MARKET THREE',
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->post(route('spending_notification_references.retry.store', $reference))
        ->assertSessionHasErrors('retry');

    expect($gmail->messageCalls)->toBe([])
        ->and($reference->fresh()->processing_outcome)->toBe('failed')
        ->and($reference->attempt_count)->toBe(1)
        ->and(Transaction::query()->count())->toBe(0);
});

test('an owner-approved profile change affects an unsupported message only after explicit retry', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    ParserProfileVersion::factory()
        ->for($profile, 'profile')
        ->create(['version' => 1]);
    $currentVersion = ParserProfileVersion::factory()
        ->for($profile, 'profile')
        ->create(['version' => 2]);
    $format = SpendingNotificationFormat::factory()
        ->for($currentVersion, 'profileVersion')
        ->create();
    $profile->forceFill(['current_version' => 2])->save();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create([
            'message_id' => 'gmail-explicit-retry',
            'processed_at' => now(),
        ]);
    $reference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($discovery, 'discovery')
        ->create([
            'transaction_id' => null,
            'parser_profile_version_id' => $profile->versions()->where('version', 1)->value('id'),
            'spending_notification_format_id' => null,
            'gmail_account_identity' => $connection->gmail_account_identity,
            'message_id' => $discovery->message_id,
            'processing_outcome' => 'unsupported',
        ]);
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = parserProfileMessage(
        $discovery->message_id,
        'S/ 72.10',
        'MARKET THREE',
    );
    app()->instance(Gmail::class, $gmail);

    expect($reference->fresh()->processing_outcome)->toBe('unsupported')
        ->and(Transaction::query()->count())->toBe(0);

    $this->actingAs($owner)
        ->post(route('spending_notification_references.retry.store', $reference))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('parser_profiles.index'));

    $reference->refresh();

    expect($reference->processing_outcome)->toBe('created')
        ->and($reference->attempt_count)->toBe(2)
        ->and($reference->parser_profile_version_id)->toBe($currentVersion->id)
        ->and($reference->spending_notification_format_id)->toBe($format->id)
        ->and($reference->transaction_id)->toBe(Transaction::query()->sole()->id)
        ->and(Transaction::query()->sole()->amount_minor)->toBe(7210);
});

test('profile confirmation fails closed when a gating trust or extraction value is invalid', function (
    string $subject,
    string $body,
    string $authenticationResult,
    string $authenticationDomain,
) {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()->for($owner, 'owner')->create();
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create();
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = new GmailMessage(
        messageId: $discovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-30 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: $subject,
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => [
                'result' => $authenticationResult,
                'domain' => $authenticationDomain,
            ],
        ],
        textBody: $body,
        htmlBody: null,
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->from(route('parser_profiles.source_messages.show', $discovery))
        ->post(route('parser_profiles.store'), parserProfilePayload($discovery))
        ->assertSessionHasErrors('profile');

    expect(ParserProfile::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0)
        ->and(SpendingNotificationReference::query()->count())->toBe(0)
        ->and($discovery->fresh()->processed_at)->toBeNull();
})->with([
    'authentication is not aligned' => [
        'Purchase approved for your card',
        "Purchase approved\nAmount: S/ 125.40\nDate: 30/07/2026\nMerchant: MARKET ONE\nThank you",
        'pass',
        'lookalike.example',
    ],
    'authentication did not pass' => [
        'Purchase approved for your card',
        "Purchase approved\nAmount: S/ 125.40\nDate: 30/07/2026\nMerchant: MARKET ONE\nThank you",
        'fail',
        'bank.example',
    ],
    'subject marker is absent' => [
        'Account notice',
        "Purchase approved\nAmount: S/ 125.40\nDate: 30/07/2026\nMerchant: MARKET ONE\nThank you",
        'pass',
        'bank.example',
    ],
    'more than one amount is extracted' => [
        'Purchase approved for your card',
        "Purchase approved\nAmount: S/ 125.40\nAmount: S/ 20.00\nDate: 30/07/2026\nMerchant: MARKET ONE\nThank you",
        'pass',
        'bank.example',
    ],
    'zero amount is invalid' => [
        'Purchase approved for your card',
        "Purchase approved\nAmount: S/ 0.00\nDate: 30/07/2026\nMerchant: MARKET ONE\nThank you",
        'pass',
        'bank.example',
    ],
    'unmapped currency is invalid' => [
        'Purchase approved for your card',
        "Purchase approved\nAmount: EUR 125.40\nDate: 30/07/2026\nMerchant: MARKET ONE\nThank you",
        'pass',
        'bank.example',
    ],
]);

test('profile confirmation rolls back when the source overlaps another enabled profile', function () {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $firstDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-overlap-one']);
    $secondDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-overlap-two']);
    $gmail = new FakeGmail;
    $gmail->messages[$firstDiscovery->message_id] = parserProfileMessage(
        $firstDiscovery->message_id,
        'S/ 125.40',
        'MARKET ONE',
    );
    $gmail->messages[$secondDiscovery->message_id] = parserProfileMessage(
        $secondDiscovery->message_id,
        'S/ 25.00',
        'MARKET TWO',
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->post(route('parser_profiles.store'), parserProfilePayload($firstDiscovery))
        ->assertSessionHasNoErrors();

    $this->from(route('parser_profiles.source_messages.show', $secondDiscovery))
        ->post(route('parser_profiles.store'), parserProfilePayload($secondDiscovery, [
            'profile_name' => 'Overlapping alerts',
        ]))
        ->assertSessionHasErrors('profile');

    expect(ParserProfile::query()->count())->toBe(1)
        ->and(ParserProfileVersion::query()->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(1)
        ->and(SpendingNotificationReference::query()->count())->toBe(1)
        ->and($secondDiscovery->fresh()->processed_at)->toBeNull();
});

test('future lookalikes fail closed with the appropriate decided outcome', function (
    string $fromAddress,
    string $subject,
    string $body,
    string $dmarcResult,
    string $dmarcDomain,
    ?string $expectedOutcome,
    ?string $expectedAlertKind,
) {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $sourceDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-profile-source']);
    $lookalikeDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-lookalike']);
    $gmail = new FakeGmail;
    $gmail->messages[$sourceDiscovery->message_id] = parserProfileMessage(
        $sourceDiscovery->message_id,
        'S/ 125.40',
        'MARKET ONE',
    );
    $gmail->messages[$lookalikeDiscovery->message_id] = new GmailMessage(
        messageId: $lookalikeDiscovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-30 14:15:00 UTC'),
        fromAddress: $fromAddress,
        subject: $subject,
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => $dmarcDomain],
            'dkim' => ['result' => 'pass', 'domain' => $dmarcDomain],
            'dmarc' => ['result' => $dmarcResult, 'domain' => $dmarcDomain],
        ],
        textBody: $body,
        htmlBody: null,
    );
    $gmail->messageSummaries[$lookalikeDiscovery->message_id] = new GmailMessageSummary(
        messageId: $lookalikeDiscovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-30 14:15:00 UTC'),
        fromAddress: $fromAddress,
        subject: $subject,
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->post(route('parser_profiles.store'), parserProfilePayload($sourceDiscovery))
        ->assertSessionHasNoErrors();

    ProcessGmailMessage::dispatchSync($lookalikeDiscovery->id);

    $reference = SpendingNotificationReference::query()
        ->where('message_id', $lookalikeDiscovery->message_id)
        ->first();

    expect(Transaction::query()->count())->toBe(1)
        ->and($reference?->processing_outcome)->toBe($expectedOutcome);

    if ($expectedOutcome === null) {
        expect($lookalikeDiscovery->fresh()->processed_at)->toBeNull();

        $this->get(route('parser_profiles.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('source_messages', 1)
                ->where('source_messages.0.id', $lookalikeDiscovery->id)
                ->has('alerts', 0),
            );
    } else {
        expect($lookalikeDiscovery->fresh()->processed_at)->not->toBeNull();

        $this->get(route('parser_profiles.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('source_messages', 0)
                ->has('alerts', 1)
                ->where('alerts.0.kind', $expectedAlertKind)
                ->where('alerts.0.references.0.outcome', $expectedOutcome),
            );
    }
})->with([
    'sender is not allowlisted' => [
        'alerts@lookalike.example',
        'Purchase approved for your card',
        "Purchase approved\nAmount: S/ 55.00\nDate: 30/07/2026\nMerchant: MARKET TWO\nThank you",
        'pass',
        'lookalike.example',
        null,
        null,
    ],
    'authentication does not pass' => [
        'alerts@bank.example',
        'Purchase approved for your card',
        "Purchase approved\nAmount: S/ 55.00\nDate: 30/07/2026\nMerchant: MARKET TWO\nThank you",
        'fail',
        'bank.example',
        'authentication_failed',
        'security',
    ],
    'subject marker does not match' => [
        'alerts@bank.example',
        'Account notice',
        "Purchase approved\nAmount: S/ 55.00\nDate: 30/07/2026\nMerchant: MARKET TWO\nThank you",
        'pass',
        'bank.example',
        'unsupported',
        'drift',
    ],
    'body marker does not match' => [
        'alerts@bank.example',
        'Purchase approved for your card',
        "Purchase approved\nTotal: S/ 55.00\nDate: 30/07/2026\nMerchant: MARKET TWO\nThank you",
        'pass',
        'bank.example',
        'unsupported',
        'drift',
    ],
]);

test('approving a profile change creates a new version without reparsing an existing Transaction', function () {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $firstDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-version-one']);
    $secondDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-version-two']);
    $gmail = new FakeGmail;
    $gmail->messages[$firstDiscovery->message_id] = parserProfileMessage(
        $firstDiscovery->message_id,
        'S/ 125.40',
        'MARKET ONE',
    );
    $gmail->messages[$secondDiscovery->message_id] = new GmailMessage(
        messageId: $secondDiscovery->message_id,
        receivedAt: CarbonImmutable::parse('2026-07-31 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'New purchase notification',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'pass', 'domain' => 'bank.example'],
        ],
        textBody: "New purchase\nTotal: $ 20.00\nOn: 2026-07-31\nAt: MARKET TWO\nEnd",
        htmlBody: null,
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->post(route('parser_profiles.store'), parserProfilePayload($firstDiscovery))
        ->assertSessionHasNoErrors();

    $profile = ParserProfile::query()->sole();
    $originalTransaction = Transaction::query()->sole();
    $originalFormat = SpendingNotificationFormat::query()->sole();

    $this->post(route('parser_profiles.store'), parserProfilePayload($secondDiscovery, [
        'parser_profile_id' => $profile->id,
        'profile_name' => null,
        'format_name' => 'New card purchase',
        'subject_marker' => 'New purchase',
        'body_marker' => 'Total:',
        'amount_prefix' => 'Total: ',
        'currency_token' => '$',
        'currency' => 'USD',
        'date_prefix' => 'On: ',
        'date_format' => 'Y-m-d',
        'merchant_prefix' => 'At: ',
    ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('parser_profiles.index'));

    $profile->refresh();
    $newTransaction = Transaction::query()->latest('id')->firstOrFail();
    $newFormat = SpendingNotificationFormat::query()->latest('id')->firstOrFail();

    expect($profile->current_version)->toBe(2)
        ->and(ParserProfileVersion::query()->whereBelongsTo($profile, 'profile')->count())->toBe(2)
        ->and($newFormat->profileVersion->formats()->count())->toBe(2)
        ->and($newFormat->profileVersion->version)->toBe(2)
        ->and($newTransaction->amount_minor)->toBe(2000)
        ->and($newTransaction->currency->value)->toBe('USD')
        ->and($originalTransaction->fresh()->amount_minor)->toBe(12540)
        ->and($originalTransaction->fresh()->currency->value)->toBe('PEN')
        ->and($originalTransaction->spendingNotificationReferences()->sole()->spending_notification_format_id)
        ->toBe($originalFormat->id);

    $futureDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-original-format-future']);
    $gmail->messages[$futureDiscovery->message_id] = parserProfileMessage(
        $futureDiscovery->message_id,
        'S/ 33.33',
        'MARKET THREE',
    );

    ProcessGmailMessage::dispatchSync($futureDiscovery->id);

    $futureTransaction = Transaction::query()->latest('id')->firstOrFail();
    $futureReference = $futureTransaction->spendingNotificationReferences()->sole();

    expect($futureTransaction->amount_minor)->toBe(3333)
        ->and($futureReference->format?->name)->toBe('Card purchase')
        ->and($futureReference->format?->profileVersion->version)->toBe(2);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function parserProfilePayload(
    GmailMessageDiscovery $discovery,
    array $overrides = [],
): array {
    return [
        'source_message_discovery_id' => $discovery->id,
        'profile_name' => 'Bank card alerts',
        'format_name' => 'Card purchase',
        'format_purpose' => 'spending',
        'authentication_mechanism' => 'dmarc',
        'mime_source' => 'text_plain',
        'subject_marker' => 'Purchase approved',
        'body_marker' => 'Amount:',
        'amount_prefix' => 'Amount: ',
        'amount_suffix' => '\n',
        'decimal_separator' => '.',
        'grouping_separator' => 'none',
        'currency_position' => 'before',
        'currency_token' => 'S/',
        'currency' => 'PEN',
        'date_prefix' => 'Date: ',
        'date_suffix' => '\n',
        'date_format' => 'd/m/Y',
        'timezone' => 'America/Lima',
        'amount_semantics' => 'absolute',
        'kind_semantics' => 'fixed_purchase',
        'merchant_prefix' => 'Merchant: ',
        'merchant_suffix' => '\n',
        ...$overrides,
    ];
}

function parserProfileMessage(
    string $messageId,
    string $amount,
    string $merchant,
): GmailMessage {
    return new GmailMessage(
        messageId: $messageId,
        receivedAt: CarbonImmutable::parse('2026-07-30 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: 'Purchase approved for your card',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => 'pass', 'domain' => 'bank.example'],
        ],
        textBody: "Purchase approved\nAmount: {$amount}\nDate: 30/07/2026\nMerchant: {$merchant}\nThank you",
        htmlBody: null,
    );
}
