<?php

use App\Actions\NotificationIngestion\ProcessSpendingNotification;
use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailMessage;
use App\Jobs\ProcessGmailMessage;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
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

    expect($job->discoveryId)->toBe(44)
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(RateLimited::class);
});

test('the owner can inspect transient Gmail content without persisting it', function () {
    [$owner, $connection, $discovery, $gmail] = parserProfileSource();
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->get(route('parser_profiles.source_messages.show', $discovery))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('parser-profiles/create')
            ->where('source.subject', 'Purchase approved for your card')
            ->where('source.mime_parts.text_plain', $gmail->messages[$discovery->message_id]->textBody)
            ->where('source.authentication.dmarc.aligned', true));

    expect(array_keys($discovery->fresh()->getAttributes()))
        ->not->toContain('subject', 'body', 'mime_content')
        ->and(DB::table((new GmailMessageDiscovery)->getTable())->value('processed_at'))
        ->toBeNull();
});

test('the owner creates a current Parser Profile only after transient validation succeeds', function () {
    [$owner, , $discovery, $gmail] = parserProfileSource();
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->post(route('parser_profiles.store'), parserProfilePayload($discovery))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('parser_profiles.index'));

    $profile = ParserProfile::query()->sole();
    $format = SpendingNotificationFormat::query()->sole();
    $transaction = Transaction::query()->sole();

    expect($profile->name)->toBe('Bank card alerts')
        ->and($profile->trusted_sender_address)->toBe('alerts@bank.example')
        ->and($profile->authentication_mechanism)->toBe('dmarc')
        ->and($profile->enabled_at)->not->toBeNull()
        ->and($format->profile->is($profile))->toBeTrue()
        ->and($format->enabled_at)->not->toBeNull()
        ->and($transaction->amount_minor)->toBe(12540)
        ->and($transaction->description)->toBe('MARKET ONE')
        ->and(ParserProfile::query()->first()->getAttributes())
        ->not->toHaveKeys(['current_version'])
        ->and(json_encode($profile->getAttributes()))
        ->not->toContain($gmail->messages[$discovery->message_id]->textBody)
        ->and(json_encode($format->getAttributes()))
        ->not->toContain('Purchase approved for your card');
});

test('invalid sender authentication or extraction never activates configuration', function (array $overrides) {
    [$owner, , $discovery, $gmail] = parserProfileSource();
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->post(route('parser_profiles.store'), parserProfilePayload($discovery, $overrides))
        ->assertSessionHasErrors('profile');

    expect(ParserProfile::query()->count())->toBe(0)
        ->and(SpendingNotificationFormat::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0)
        ->and($discovery->fresh()->processed_at)->toBeNull();
})->with([
    'unaligned authentication' => [['authentication_mechanism' => 'spf']],
    'missing format marker' => [['body_marker' => 'Not in the message']],
    'ambiguous extraction' => [['amount_suffix' => 'Missing suffix']],
]);

test('one current profile supports multiple formats without code changes', function () {
    [$owner, $connection, $firstDiscovery, $gmail] = parserProfileSource();
    $secondDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-usd']);
    $gmail->messages[$secondDiscovery->message_id] = parserProfileMessage(
        messageId: $secondDiscovery->message_id,
        subject: 'New purchase notification',
        body: "New purchase\nTotal: $ 20.00\nOn: 2026-07-31\nAt: MARKET TWO\nEnd",
    );
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->post(route('parser_profiles.store'), parserProfilePayload($firstDiscovery))
        ->assertSessionHasNoErrors();
    $profile = ParserProfile::query()->sole();

    $this->post(route('parser_profiles.formats.store', $profile), parserProfilePayload($secondDiscovery, [
        'parser_profile_id' => $profile->id,
        'profile_name' => null,
        'format_name' => 'USD purchase',
        'subject_marker' => 'New purchase',
        'body_marker' => 'Total:',
        'amount_prefix' => 'Total: ',
        'currency_token' => '$',
        'currency' => 'USD',
        'date_prefix' => 'On: ',
        'date_format' => 'Y-m-d',
        'merchant_prefix' => 'At: ',
    ]))->assertSessionHasNoErrors();

    expect($profile->formats()->count())->toBe(2)
        ->and(Transaction::query()->count())->toBe(1);

    $futureDiscovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-usd-future']);
    $gmail->messages[$futureDiscovery->message_id] = parserProfileMessage(
        messageId: $futureDiscovery->message_id,
        subject: 'New purchase notification',
        body: "New purchase\nTotal: $ 33.00\nOn: 2026-07-31\nAt: MARKET THREE\nEnd",
    );

    ProcessGmailMessage::dispatchSync($futureDiscovery->id);

    expect(Transaction::query()->latest('id')->value('amount_minor'))->toBe(3300)
        ->and(Transaction::query()->latest('id')->firstOrFail()->currency->value)->toBe('USD');
});

test('the owner directly edits toggles and deletes current profiles and formats', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    $format = SpendingNotificationFormat::factory()->for($profile, 'profile')->create();

    $this->actingAs($owner)
        ->patch(route('parser_profiles.update', $profile), ['name' => 'Renamed alerts'])
        ->assertSessionHasNoErrors();
    $this->delete(route('parser_profiles.formats.activation.destroy', [$profile, $format]))
        ->assertSessionHasNoErrors();
    $this->delete(route('parser_profiles.activation.destroy', $profile))
        ->assertSessionHasNoErrors();

    expect($profile->fresh()->name)->toBe('Renamed alerts')
        ->and($profile->fresh()->enabled_at)->toBeNull()
        ->and($format->fresh()->enabled_at)->toBeNull();

    $this->post(route('parser_profiles.activation.store', $profile))->assertSessionHasNoErrors();
    $this->post(route('parser_profiles.formats.activation.store', [$profile, $format]))
        ->assertSessionHasNoErrors();
    $this->delete(route('parser_profiles.formats.destroy', [$profile, $format]))
        ->assertSessionHasNoErrors();

    expect($profile->fresh()->enabled_at)->not->toBeNull()
        ->and(SpendingNotificationFormat::query()->count())->toBe(0);

    $this->delete(route('parser_profiles.destroy', $profile))->assertSessionHasNoErrors();
    expect(ParserProfile::query()->count())->toBe(0);
});

test('editing a format replaces its current definition only after transient validation', function () {
    [$owner, , $discovery, $gmail] = parserProfileSource();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    $format = SpendingNotificationFormat::factory()->for($profile, 'profile')->create([
        'name' => 'Old format',
        'enabled_at' => null,
    ]);
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($owner)
        ->put(
            route('parser_profiles.formats.update', [$profile, $format]),
            parserProfilePayload($discovery, [
                'parser_profile_id' => $profile->id,
                'profile_name' => null,
                'format_name' => 'Current card purchase',
            ]),
        )
        ->assertSessionHasNoErrors();

    expect($format->fresh()->name)->toBe('Current card purchase')
        ->and($format->fresh()->enabled_at)->not->toBeNull()
        ->and($profile->formats()->count())->toBe(1);

    $this->put(
        route('parser_profiles.formats.update', [$profile, $format]),
        parserProfilePayload($discovery, [
            'parser_profile_id' => $profile->id,
            'profile_name' => null,
            'format_name' => 'Invalid replacement',
            'body_marker' => 'Does not match',
        ]),
    )->assertSessionHasErrors('profile');

    expect($format->fresh()->name)->toBe('Current card purchase');
});

test('disabled definitions are ignored and unsupported messages are marked processed', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    SpendingNotificationFormat::factory()->for($profile, 'profile')->create(['enabled_at' => null]);
    $connection = GmailConnection::factory()->for($owner, 'owner')->create();
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'unsupported-message']);
    $message = parserProfileMessage($discovery->message_id);

    $reference = app(ProcessSpendingNotification::class)->handle($owner, $discovery, $message);

    expect($reference?->processing_outcome)->toBe('unsupported')
        ->and($reference?->transaction_id)->toBeNull()
        ->and($discovery->fresh()->processed_at)->not->toBeNull()
        ->and(Transaction::query()->count())->toBe(0);
});

test('authentication failures fail closed with only sanitized processing state', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    SpendingNotificationFormat::factory()->for($profile, 'profile')->create();
    $connection = GmailConnection::factory()->for($owner, 'owner')->create();
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'authentication-failure']);
    $message = parserProfileMessage($discovery->message_id, authenticationResult: 'fail');

    $reference = app(ProcessSpendingNotification::class)->handle($owner, $discovery, $message);

    expect($reference?->processing_outcome)->toBe('authentication_failed')
        ->and($reference?->transaction_id)->toBeNull()
        ->and(Transaction::query()->count())->toBe(0)
        ->and(json_encode(SpendingNotificationReference::query()->sole()->getAttributes()))
        ->not->toContain($message->subject, $message->textBody);
});

/**
 * @return array{User, GmailConnection, GmailMessageDiscovery, FakeGmail}
 */
function parserProfileSource(): array
{
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()->for($owner, 'owner')->create();
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'gmail-message-source']);
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = parserProfileMessage($discovery->message_id);

    return [$owner, $connection, $discovery, $gmail];
}

/** @param array<string, mixed> $overrides */
function parserProfilePayload(GmailMessageDiscovery $discovery, array $overrides = []): array
{
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
        'amount_suffix' => '\\n',
        'decimal_separator' => '.',
        'grouping_separator' => 'none',
        'currency_position' => 'before',
        'currency_token' => 'S/',
        'currency' => 'PEN',
        'date_prefix' => 'Date: ',
        'date_suffix' => '\\n',
        'date_format' => 'd/m/Y',
        'timezone' => 'America/Lima',
        'amount_semantics' => 'absolute',
        'kind_semantics' => 'fixed_purchase',
        'merchant_prefix' => 'Merchant: ',
        'merchant_suffix' => '\\n',
        ...$overrides,
    ];
}

function parserProfileMessage(
    string $messageId,
    string $subject = 'Purchase approved for your card',
    string $body = "Purchase approved\nAmount: S/ 125.40\nDate: 30/07/2026\nMerchant: MARKET ONE\nThank you",
    string $authenticationResult = 'pass',
): GmailMessage {
    return new GmailMessage(
        messageId: $messageId,
        receivedAt: CarbonImmutable::parse('2026-07-30 14:15:00 UTC'),
        fromAddress: 'alerts@bank.example',
        subject: $subject,
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => 'other.example'],
            'dkim' => ['result' => 'pass', 'domain' => 'bank.example'],
            'dmarc' => ['result' => $authenticationResult, 'domain' => 'bank.example'],
        ],
        textBody: $body,
        htmlBody: null,
    );
}
