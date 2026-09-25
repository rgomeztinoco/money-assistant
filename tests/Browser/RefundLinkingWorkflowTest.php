<?php

use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner sees retained Refund links and review reasons in Transaction details', function () {
    $owner = User::factory()->create();
    $spending = Transaction::factory()
        ->for($owner, 'owner')
        ->spending()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-20',
            'amount_minor' => 10_000,
            'description' => 'Original spending',
        ]);
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-21',
            'amount_minor' => 12_000,
            'description' => 'Store Refund',
            'original_spending_id' => $spending->id,
            'refund_relationship_review_reasons' => [
                RefundRelationshipReviewReason::CumulativeRefundsExceedSpending->value,
            ],
        ]);
    $this->actingAs($owner);

    visit('/transactions?selected='.$refund->id)
        ->assertSee('Linked Refunds exceed the spending')
        ->press('Advanced details')
        ->assertSee('Original spending: Original spending')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    visit('/transactions?selected='.$spending->id)
        ->press('Advanced details')
        ->assertSee('Linked Refund: Store Refund')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
