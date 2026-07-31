<?php

use App\Actions\NotificationIngestion\ProcessSpendingNotification;
use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailMessage;
use App\Integrations\Gmail\GmailMessageSummary;
use App\Jobs\ProcessGmailMessage;
use App\Models\AiClassificationRequest;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
use App\Models\ParserProfileVersion;
use App\Models\SpendingNotificationFormat;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;
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

test('future lookalikes create no Transaction and remain available as profile sources', function (
    string $fromAddress,
    string $subject,
    string $body,
    string $dmarcResult,
    string $dmarcDomain,
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

    expect(Transaction::query()->count())->toBe(1)
        ->and(SpendingNotificationReference::query()
            ->where('message_id', $lookalikeDiscovery->message_id)
            ->exists())->toBeFalse()
        ->and($lookalikeDiscovery->fresh()->processed_at)->toBeNull();

    $this->get(route('parser_profiles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('source_messages', 1)
            ->where('source_messages.0.id', $lookalikeDiscovery->id),
        );
})->with([
    'sender is not allowlisted' => [
        'alerts@lookalike.example',
        'Purchase approved for your card',
        "Purchase approved\nAmount: S/ 55.00\nDate: 30/07/2026\nMerchant: MARKET TWO\nThank you",
        'pass',
        'lookalike.example',
    ],
    'authentication does not pass' => [
        'alerts@bank.example',
        'Purchase approved for your card',
        "Purchase approved\nAmount: S/ 55.00\nDate: 30/07/2026\nMerchant: MARKET TWO\nThank you",
        'fail',
        'bank.example',
    ],
    'subject marker does not match' => [
        'alerts@bank.example',
        'Account notice',
        "Purchase approved\nAmount: S/ 55.00\nDate: 30/07/2026\nMerchant: MARKET TWO\nThank you",
        'pass',
        'bank.example',
    ],
    'body marker does not match' => [
        'alerts@bank.example',
        'Purchase approved for your card',
        "Purchase approved\nTotal: S/ 55.00\nDate: 30/07/2026\nMerchant: MARKET TWO\nThank you",
        'pass',
        'bank.example',
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
        ->and($newFormat->profileVersion->version)->toBe(2)
        ->and($newTransaction->amount_minor)->toBe(2000)
        ->and($newTransaction->currency->value)->toBe('USD')
        ->and($originalTransaction->fresh()->amount_minor)->toBe(12540)
        ->and($originalTransaction->fresh()->currency->value)->toBe('PEN')
        ->and($originalTransaction->spendingNotificationReferences()->sole()->spending_notification_format_id)
        ->toBe($originalFormat->id);
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
