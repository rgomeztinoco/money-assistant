<?php

use App\Actions\Ledger\MarkSuspectedDuplicate;
use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the inspector shows both records and the exact duplicate resolution effect before confirmation', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $notificationTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-20',
            'amount_minor' => 2_500,
            'merchant_description' => 'Market notification',
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $receiptTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-20',
            'amount_minor' => 2_500,
            'merchant_description' => 'Market receipt',
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($notificationTransaction)
        ->create();
    SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($receiptTransaction)
        ->create();
    app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $notificationTransaction,
        secondTransaction: $receiptTransaction,
    );
    $this->actingAs($owner);

    $page = visit('/review-queue');

    $page
        ->assertSee('Suspected Duplicate')
        ->press('Inspect pair')
        ->assertSee('Market notification')
        ->assertSee('Market receipt')
        ->click('Keep Market receipt')
        ->assertSee('Keep Market receipt active.')
        ->assertSee('Move 1 source reference from Market notification to Market receipt.')
        ->assertSee('Void Market notification and remove $ 25.00 from USD net spending.')
        ->assertSee('Remove $ 25.00 from Groceries Category spending.')
        ->press('Confirm resolution')
        ->assertSee('Suspected Duplicate resolved.')
        ->assertSee('Review Queue is clear')
        ->click('Transactions')
        ->assertSee('Reopen pair')
        ->press('Reopen pair')
        ->assertSee('Suspected Duplicate reopened.')
        ->click('Review Queue')
        ->assertSee('Suspected Duplicate')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
