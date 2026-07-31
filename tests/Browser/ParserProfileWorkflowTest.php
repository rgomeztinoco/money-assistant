<?php

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailMessage;
use App\Integrations\Gmail\GmailMessageSummary;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
use App\Models\ParserProfileVersion;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeGmail;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner creates a deterministic Parser Profile from a Gmail message preview', function () {
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()->for($owner, 'owner')->create();
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'browser-profile-source']);
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
    $gmail->messageSummaries[$discovery->message_id] = new GmailMessageSummary(
        messageId: $message->messageId,
        receivedAt: $message->receivedAt,
        fromAddress: $message->fromAddress,
        subject: $message->subject,
    );
    $gmail->messages[$discovery->message_id] = $message;
    app()->instance(Gmail::class, $gmail);
    $this->actingAs($owner);

    $page = visit(route('parser_profiles.index'));

    $page
        ->assertSee('Purchase approved for your card')
        ->assertSee('alerts@bank.example')
        ->click('Review')
        ->assertSee('Transient preview')
        ->assertSee('Amount: S/ 125.40')
        ->fill('New profile name', 'Bank card alerts')
        ->fill('Format name', 'Card purchase')
        ->fill('Exact subject marker', 'Purchase approved')
        ->fill('Exact body marker', 'Amount:')
        ->fill('Text immediately before amount', 'Amount: ')
        ->fill('Text immediately after amount', '\n')
        ->fill('Exact currency token', 'S/')
        ->fill('Text immediately before date', 'Date: ')
        ->fill('Text immediately after date', '\n')
        ->fill('#merchant_prefix', 'Merchant: ')
        ->fill('#merchant_suffix', '\n')
        ->assertValue('#merchant_prefix', 'Merchant: ');

    $page->submit()->wait(1);

    $page
        ->assertPathIs("/parser-profile-source-messages/{$discovery->id}")
        ->assertSee('Candidate Transaction')
        ->assertSee('S/ 125.40')
        ->assertSee('MARKET ONE')
        ->fill('Exact currency token', '$')
        ->assertMissing('[data-testid="candidate-transaction"]')
        ->assertDontSee('Enable profile and create Transaction')
        ->fill('Exact currency token', 'S/');

    $page->submit()->wait(1);

    $page
        ->assertSee('Candidate Transaction')
        ->press('Enable profile and create Transaction')
        ->assertPathIs('/parser-profiles')
        ->assertSee('Parser Profile created and source message processed.')
        ->assertSee('Bank card alerts')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(ParserProfile::query()->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(1);
});

test('the owner recovers an unsupported Spending Notification from the grouped drift alert', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    $version = ParserProfileVersion::factory()->for($profile, 'profile')->create();
    $connection = GmailConnection::factory()
        ->for($owner, 'owner')
        ->create(['gmail_account_identity' => 'owner@gmail.example']);
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['processed_at' => now()]);
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
    $this->actingAs($owner);

    $page = visit(route('parser_profiles.index'));

    $page
        ->assertSee('Parser drift detected')
        ->assertSee('Degraded')
        ->assertSee('1 unsupported')
        ->assertSee('Retry current profile')
        ->fill("#recovery-{$reference->id}-occurred-on", '2026-07-31')
        ->fill("#recovery-{$reference->id}-amount", '4590')
        ->select("#recovery-{$reference->id}-currency", 'PEN')
        ->select("#recovery-{$reference->id}-kind", 'purchase')
        ->fill("#recovery-{$reference->id}-merchant", 'Neighborhood market')
        ->press('Record and link Transaction')
        ->assertSee('Unsupported Spending Notification recorded and linked.')
        ->assertSee('Healthy')
        ->assertDontSee('Parser drift detected')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($reference->fresh()->transaction_id)->toBe(Transaction::query()->sole()->id);
});

test('a failed extraction offers manual recovery without profile retry', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    $version = ParserProfileVersion::factory()->for($profile, 'profile')->create();
    SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($version, 'profileVersion')
        ->create([
            'transaction_id' => null,
            'processing_outcome' => 'failed',
        ]);
    $this->actingAs($owner);

    visit(route('parser_profiles.index'))
        ->assertSee('Parser drift detected')
        ->assertSee('Record and link Transaction')
        ->assertDontSee('Retry current profile')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the owner explicitly approves a known non-spending format to suppress review noise', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->for($owner, 'owner')->create();
    ParserProfileVersion::factory()->for($profile, 'profile')->create();
    $connection = GmailConnection::factory()->for($owner, 'owner')->create();
    $discovery = GmailMessageDiscovery::factory()
        ->for($connection, 'gmailConnection')
        ->create(['message_id' => 'browser-ignored-format']);
    $message = new GmailMessage(
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
    $gmail = new FakeGmail;
    $gmail->messages[$discovery->message_id] = $message;
    app()->instance(Gmail::class, $gmail);
    $this->actingAs($owner);

    $page = visit(route('parser_profiles.source_messages.show', $discovery));

    $page
        ->select('#parser_profile_id', (string) $profile->id)
        ->fill('Format name', 'Monthly statement')
        ->select('#format_purpose', 'ignore')
        ->fill('Exact subject marker', 'Statement ready')
        ->fill('Exact body marker', 'View your statement')
        ->press('Preview ignored format')
        ->assertSee('Known non-spending format')
        ->press('Approve ignored format')
        ->assertPathIs('/parser-profiles')
        ->assertSee('1 ignored')
        ->assertSee('Healthy')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(Transaction::query()->count())->toBe(0)
        ->and(SpendingNotificationReference::query()->sole()->processing_outcome)
        ->toBe('ignored');
});
