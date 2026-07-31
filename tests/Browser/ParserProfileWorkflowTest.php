<?php

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailMessage;
use App\Integrations\Gmail\GmailMessageSummary;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
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
