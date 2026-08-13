<?php

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailMessage;
use App\Integrations\Gmail\GmailMessageSummary;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
use App\Models\SpendingNotificationFormat;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeGmail;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner creates a deterministic Parser Profile from a transient Gmail preview', function () {
    [$owner, $discovery, $gmail] = browserParserSource();
    app()->instance(Gmail::class, $gmail);
    $this->actingAs($owner);

    $page = visit(route('parser_profiles.index'));

    $page
        ->assertSee('Validate a format from Gmail')
        ->assertSee('Purchase approved for your card')
        ->click('Validate format')
        ->assertSee('Transient preview')
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
        ->press('Preview candidate Transaction')
        ->assertSee('Candidate Transaction')
        ->assertSee('S/ 125.40')
        ->assertSee('MARKET ONE')
        ->press('Enable profile and create Transaction')
        ->assertPathIs('/parser-profiles')
        ->assertSee('Bank card alerts')
        ->assertSee('Card purchase')
        ->assertSee('Creates Transactions')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(ParserProfile::query()->count())->toBe(1)
        ->and(SpendingNotificationFormat::query()->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(1);
});

test('the owner manages current profiles and formats from the web interface', function () {
    $owner = User::factory()->create();
    $profile = ParserProfile::factory()->create([
        'name' => 'Bank card alerts',
    ]);
    SpendingNotificationFormat::factory()->for($profile, 'profile')->create([
        'name' => 'Card purchase',
    ]);
    $this->actingAs($owner);

    $page = visit(route('parser_profiles.index'));

    $page
        ->assertSee('Current profiles')
        ->fill('input[name="name"]', 'Renamed bank alerts')
        ->press('Rename')
        ->assertSee('Renamed bank alerts')
        ->press('Disable')
        ->assertSee('Disabled')
        ->press('Disable profile')
        ->assertSee('Enable profile')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($profile->fresh()->name)->toBe('Renamed bank alerts')
        ->and($profile->fresh()->enabled_at)->toBeNull()
        ->and($profile->formats()->sole()->enabled_at)->toBeNull();
});

/** @return array{User, GmailMessageDiscovery, FakeGmail} */
function browserParserSource(): array
{
    $owner = User::factory()->create();
    $connection = GmailConnection::factory()->create();
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

    return [$owner, $discovery, $gmail];
}
