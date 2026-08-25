<?php

use App\Integrations\Gmail\GmailMessage;
use App\Models\User;
use App\NotificationIngestion\SupportedSpendingNotificationRegistry;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

test('owner-facing Parser Profile and notification format routes are unavailable', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get('/parser-profiles')
        ->assertNotFound();
    $this->get('/parser-profiles/create')->assertNotFound();
    $this->post('/parser-profiles')->assertNotFound();
    $this->post('/parser-profiles/1/formats')->assertNotFound();

    expect(Schema::hasTable('parser_profiles'))->toBeFalse()
        ->and(Schema::hasTable('spending_notification_formats'))->toBeFalse();
});

test('Gmail exposes source health without a parser builder', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('data_sources.gmail'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('data-sources/gmail')
            ->has('gmail')
            ->missing('statement_imports')
            ->missing('parser_profiles')
            ->missing('spending_notification_formats'));
});

test('Gmail ingestion formats are application-owned and fixture verified', function () {
    expect(app(SupportedSpendingNotificationRegistry::class)->verifyFixtures())
        ->each->toBeTrue();
});

test('Interbank Plin card notifications are spending regardless of destination', function () {
    $fixture = json_decode(
        (string) file_get_contents(resource_path('notification-formats/interbank-plin-card-spending.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $message = $fixture['message'];
    $notification = app(SupportedSpendingNotificationRegistry::class)->match(new GmailMessage(
        messageId: $message['message_id'],
        receivedAt: CarbonImmutable::parse($message['received_at']),
        fromAddress: $message['from_address'],
        subject: $message['subject'],
        authentication: $message['authentication'],
        textBody: null,
        htmlBody: str_replace('Destino: PLIN', 'Destino: OTRO', $message['html_body']),
    ));

    expect($notification)
        ->not->toBeNull()
        ->formatIdentifier->toBe('interbank.plin_card_spending')
        ->and($notification->extraction->kind)->toBe(TransactionKind::Spending);
});

test('Interbank Constancia de pago notifications are unsupported', function () {
    $notification = app(SupportedSpendingNotificationRegistry::class)->match(new GmailMessage(
        messageId: 'interbank-card-payment',
        receivedAt: CarbonImmutable::parse('2026-08-15T20:00:00Z'),
        fromAddress: 'servicioalcliente@netinterbank.com.pe',
        subject: 'Constancia de pago',
        authentication: [
            'spf' => ['result' => 'pass', 'domain' => null],
            'dkim' => ['result' => 'pass', 'domain' => null],
            'dmarc' => ['result' => 'pass', 'domain' => null],
        ],
        textBody: null,
        htmlBody: '<p>Constancia de pago</p>',
    ));

    expect($notification)->toBeNull();
});
