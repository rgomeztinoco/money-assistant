<?php

use App\Models\GmailConnection;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('Gmail source health replaces the owner-facing parser builder', function () {
    $connection = GmailConnection::factory()->create([
        'gmail_account_identity' => 'receipts@example.test',
        'last_successful_check_at' => now(),
    ]);
    $this->actingAs($connection->owner);

    visit(route('data_sources.gmail'))
        ->assertSee('Gmail')
        ->assertSee('Healthy')
        ->assertDontSee('Statement history')
        ->assertDontSee('Parser Profile')
        ->assertDontSee('Validate a format from Gmail')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
