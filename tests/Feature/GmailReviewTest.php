<?php

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailMessageSummary;
use App\Integrations\Gmail\GmailRequestFailed;
use App\Jobs\ProcessGmailMessage;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeGmail;

function referenceForReview(GmailConnection $connection, GmailMessageDiscovery $discovery, string $outcome = 'unsupported', ?int $transactionId = null): SpendingNotificationReference
{
    return SpendingNotificationReference::factory()->create([
        'user_id' => $connection->user_id,
        'gmail_account_identity' => $connection->gmail_account_identity,
        'gmail_message_discovery_id' => $discovery->id,
        'message_id' => $discovery->message_id,
        'transaction_id' => $transactionId,
        'processing_outcome' => $outcome,
    ]);
}

test('the owner sees separate unresolved outcomes and only current page Gmail summaries', function () {
    $connection = GmailConnection::factory()->create();
    $unrecognized = GmailMessageDiscovery::factory()->for($connection)->create(['processed_at' => now()]);
    referenceForReview($connection, $unrecognized);
    $failed = GmailMessageDiscovery::factory()->for($connection)->create([
        'processing_failed_at' => now(),
        'last_error_code' => 'gmail_message_processing_failed',
    ]);
    $parsedFailure = GmailMessageDiscovery::factory()->for($connection)->create(['processed_at' => now()]);
    referenceForReview($connection, $parsedFailure, 'failed');
    $ignored = GmailMessageDiscovery::factory()->for($connection)->create(['processed_at' => now()]);
    referenceForReview($connection, $ignored, 'ignored');
    $imported = GmailMessageDiscovery::factory()->for($connection)->create(['processed_at' => now()]);
    referenceForReview($connection, $imported, 'created', Transaction::factory()->create(['user_id' => $connection->user_id])->id);
    GmailMessageDiscovery::factory()->for($connection)->create();

    $gmail = new FakeGmail;
    $gmail->messageSummaries[$unrecognized->message_id] = new GmailMessageSummary(
        $unrecognized->message_id,
        now()->toImmutable(),
        'bank@example.test',
        'A spending notification',
    );
    $gmail->messageSummaries[$parsedFailure->message_id] = GmailRequestFailed::messageSummary();
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($connection->owner)
        ->get(route('data_sources.gmail'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review.attention_count', 3)
            ->where('review.unrecognized_count', 1)
            ->where('review.failed_count', 2)
            ->where('review.items.total', 3)
            ->where('review.items.data.0.summary_state', 'unavailable')
            ->where('review.items.data.1.outcome', 'failed')
            ->where('review.items.data.2.sender', 'bank@example.test')
            ->where('review.items.data.2.subject', 'A spending notification')
            ->where('review.items.data.2.explanation', 'No supported format matched. The precise reason is unknown.')
            ->where('review.items.data.2.gmail_url', 'https://mail.google.com/mail/u/'.rawurlencode($connection->gmail_account_identity).'/#all/'.rawurlencode($unrecognized->message_id)));

    expect($gmail->messageSummaryCalls)->toHaveCount(3);
});

test('review pagination bounds Gmail metadata requests and keeps counts across pages', function () {
    $connection = GmailConnection::factory()->create();
    $gmail = new FakeGmail;
    app()->instance(Gmail::class, $gmail);

    foreach (range(1, 21) as $number) {
        $discovery = GmailMessageDiscovery::factory()->for($connection)->create(['processed_at' => now()]);
        referenceForReview($connection, $discovery);
    }

    $this->actingAs($connection->owner)
        ->get(route('data_sources.gmail'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review.attention_count', 21)
            ->where('review.items.total', 21)
            ->where('review.items.last_page', 2)
            ->has('review.items.data', 20));

    expect($gmail->messageSummaryCalls)->toHaveCount(20);

    $this->get(route('data_sources.gmail', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review.items.current_page', 2)
            ->has('review.items.data', 1));

    expect($gmail->messageSummaryCalls)->toHaveCount(21);
});

test('dismissal and restoration affect only one message and preserve the mailbox', function () {
    $connection = GmailConnection::factory()->create();
    $first = GmailMessageDiscovery::factory()->for($connection)->create(['processed_at' => now()]);
    $second = GmailMessageDiscovery::factory()->for($connection)->create(['processed_at' => now()]);
    referenceForReview($connection, $first);
    referenceForReview($connection, $second);
    $gmail = new FakeGmail;
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($connection->owner)
        ->post(route('gmail.messages.dismiss', $first))
        ->assertRedirect();

    expect($first->fresh()->dismissed_at)->not->toBeNull()
        ->and($second->fresh()->dismissed_at)->toBeNull();

    $this->get(route('data_sources.gmail'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review.attention_count', 1)
            ->where('review.dismissed_count', 1)
            ->where('review.items.data.0.id', $second->id));

    $this->get(route('data_sources.gmail', ['view' => 'dismissed']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review.items.data.0.id', $first->id));

    GmailMessageDiscovery::query()->firstOrCreate([
        'gmail_connection_id' => $connection->id,
        'message_id' => $first->message_id,
    ]);

    expect($first->fresh()->dismissed_at)->not->toBeNull();

    $this->delete(route('gmail.messages.restore', $first))->assertRedirect();

    $this->get(route('data_sources.gmail'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review.attention_count', 2)
            ->where('review.dismissed_count', 0));

    expect($gmail->operations)->toContain('message_summary');
    expect(array_unique($gmail->operations))->toBe(['message_summary']);
});

test('dismissed resolved email can be restored without returning to attention', function () {
    $connection = GmailConnection::factory()->create();
    $discovery = GmailMessageDiscovery::factory()->for($connection)->create([
        'processed_at' => now(),
        'dismissed_at' => now(),
    ]);
    $reference = referenceForReview($connection, $discovery);
    $reference->update([
        'transaction_id' => Transaction::factory()->create(['user_id' => $connection->user_id])->id,
        'processing_outcome' => 'created',
    ]);
    app()->instance(Gmail::class, new FakeGmail);

    $this->actingAs($connection->owner)
        ->delete(route('gmail.messages.restore', $discovery))
        ->assertRedirect();

    $this->get(route('data_sources.gmail'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review.attention_count', 0)
            ->where('review.dismissed_count', 0));
});

test('the owner can queue one unsupported email but cannot retry dismissed or foreign email', function () {
    Queue::fake([ProcessGmailMessage::class]);
    $connection = GmailConnection::factory()->create();
    $discovery = GmailMessageDiscovery::factory()->for($connection)->create(['processed_at' => now()]);
    referenceForReview($connection, $discovery);
    $otherConnection = GmailConnection::factory()->create();
    $foreign = GmailMessageDiscovery::factory()->for($otherConnection)->create(['processed_at' => now()]);
    referenceForReview($otherConnection, $foreign);

    $this->actingAs($connection->owner)
        ->post(route('gmail.messages.retry', $discovery))
        ->assertRedirect();

    Queue::assertPushed(ProcessGmailMessage::class, fn (ProcessGmailMessage $job): bool => $job->discoveryId === $discovery->id && $job->retryUnsupported);

    $this->post(route('gmail.messages.dismiss', $discovery))->assertRedirect();
    $this->post(route('gmail.messages.retry', $discovery))->assertRedirect();
    $this->post(route('gmail.messages.retry', $foreign))->assertNotFound();
    $this->post(route('gmail.messages.dismiss', $foreign))->assertNotFound();
    $this->delete(route('gmail.messages.restore', $foreign))->assertNotFound();

    Queue::assertPushed(ProcessGmailMessage::class, 1);
    expect($foreign->fresh()->dismissed_at)->toBeNull();
});

test('reauthorization keeps local review available without fetching Gmail metadata', function () {
    $connection = GmailConnection::factory()->reauthorizationRequired()->create();
    $discovery = GmailMessageDiscovery::factory()->for($connection)->create(['processed_at' => now()]);
    referenceForReview($connection, $discovery);
    $gmail = new FakeGmail;
    app()->instance(Gmail::class, $gmail);

    $this->actingAs($connection->owner)
        ->get(route('data_sources.gmail'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review.attention_count', 1)
            ->where('review.items.data.0.summary_state', 'reauthorization_required')
            ->where('review.items.data.0.retryable', false));

    expect($gmail->messageSummaryCalls)->toBeEmpty();
});
