<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('date-only values stay fixed while instants follow the browser timezone', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-07-20',
        'confirmed_at' => CarbonImmutable::parse('2026-07-20 02:30:00 UTC'),
        'description' => 'Timezone boundary purchase',
    ]);
    $this->actingAs($owner);

    $limaPage = visit(route('transactions.index', [
        'selected' => $transaction->id,
    ]))
        ->withLocale('en-US')
        ->withTimezone('America/Lima');

    $limaPage
        ->assertSeeIn(
            '[data-test="transaction-'.$transaction->id.'-occurred-on"]',
            '20 Jul 2026',
        )
        ->assertSeeIn(
            '[data-test="transaction-confirmed-at"]',
            '19 Jul 2026, 9:30 PM',
        )
        ->assertScript(
            'document.querySelector(\'[data-test="transaction-'.$transaction->id.'-occurred-on"] time\')?.dateTime === \'2026-07-20\'',
        )
        ->assertScript(
            'document.querySelector(\'[data-test="transaction-confirmed-at"] time\')?.dateTime.startsWith(\'2026-07-20T02:30:00\')',
        );

    $tokyoPage = visit(route('transactions.index', [
        'selected' => $transaction->id,
    ]))
        ->withLocale('en-US')
        ->withTimezone('Asia/Tokyo');

    $tokyoPage
        ->assertSeeIn(
            '[data-test="transaction-'.$transaction->id.'-occurred-on"]',
            '20 Jul 2026',
        )
        ->assertSeeIn(
            '[data-test="transaction-confirmed-at"]',
            '20 Jul 2026, 11:30 AM',
        )
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('filters, selection, and scroll context persist while directly editing a Transaction in the inspector', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::Description])
        ->create([
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
            'description' => 'Neighborhood market',
            'occurred_on' => '2026-07-20',
        ]);
    Transaction::factory()->for($owner, 'owner')->create([
        'description' => 'Unrelated pharmacy',
        'occurred_on' => '2026-07-21',
    ]);
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->fill('Merchant or description', 'Neighborhood')
        ->press('Advanced filters')
        ->select('Filter review state', 'outstanding')
        ->press('Apply filters')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertQueryStringHas('review_state', 'outstanding')
        ->assertSee('Neighborhood market')
        ->assertDontSee('Unrelated pharmacy');

    $page->script(
        "document.body.style.minHeight = '2500px'; window.scrollTo(0, document.body.scrollHeight)",
    );

    $page->assertScript('window.scrollY > 0');

    $page
        ->press('Inspect')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertQueryStringHas('review_state', 'outstanding')
        ->assertQueryStringHas('selected')
        ->assertSee('Edit current Transaction')
        ->assertSee('Included in Net Spending')
        ->press('Advanced details')
        ->assertSee('Provenance')
        ->fill('Edit description', 'Neighborhood market Lima')
        ->press('Save Transaction')
        ->assertSee('Transaction updated.')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertQueryStringHas('review_state', 'outstanding')
        ->assertSee('Neighborhood market Lima')
        ->assertSee('Review clear')
        ->press('Close')
        ->assertScript('window.scrollY > 0')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the Transaction workspace stays actionable without horizontal scrolling on mobile', function () {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'description' => 'Mobile market',
        'amount_minor' => 1_250,
        'occurred_on' => '2026-08-21',
    ]);
    Transaction::factory()->count(25)->for($owner, 'owner')->spending()->usd()->create([
        'description' => 'Earlier mobile market',
        'occurred_on' => '2026-08-20',
    ]);
    $this->actingAs($owner);

    $page = visit('/transactions')->on()->iPhone14Pro();

    $page
        ->assertSee('Mobile market')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->press('Inspect')
        ->assertSee('Transaction summary')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->press('Close')
        ->press('Void')
        ->assertSee('Transaction voided.')
        ->press('Next')
        ->assertQueryStringHas('page', '2')
        ->assertSee('Earlier mobile market')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->press('Previous')
        ->assertSee('Mobile market')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the Review Queue inspector can be dismissed without immediately reopening', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::Description])
        ->create(['description' => 'Review me']);
    $this->actingAs($owner);

    $page = visit("/review-queue?item=transaction:{$transaction->id}&selected={$transaction->id}");

    $page
        ->assertSee('Edit current Transaction')
        ->press('Close')
        ->assertQueryStringMissing('selected')
        ->assertSee('Review me')
        ->assertDontSee('Edit current Transaction')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
