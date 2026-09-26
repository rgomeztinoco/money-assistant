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

test('Transactions searches history and finds a Voided ID in the main search', function () {
    $owner = User::factory()->create();
    Transaction::factory()->count(51)->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-09-01',
        'description' => 'Other purchase',
    ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2025-01-01',
        'description' => 'Starbucks refund',
        'amount_minor' => 1250,
    ]);
    $voided = Transaction::factory()->for($owner, 'owner')->usd()->create([
        'occurred_on' => '2020-01-01',
        'description' => 'Old voided purchase',
        'voided_at' => now(),
    ]);
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->resize(1280, 720)
        ->assertPresent('a[href="/transactions"]')
        ->assertNotPresent('[data-test="period-controls"]')
        ->assertNotPresent('label[for="transaction-search"]')
        ->assertSeeIn('[data-test="transaction-matching-count"]', '52 matching Transactions')
        ->assertSee('Currency')
        ->assertScript(<<<'JS'
            (() => {
                const scroller = document.querySelector('[data-test="breakdown-transactions-scroll"]');
                const header = scroller?.querySelector('thead');
                const footer = scroller?.parentElement?.lastElementChild;

                if (scroller === null || header === null || header === undefined || footer === null || footer === undefined) {
                    return false;
                }

                const headerTop = header.getBoundingClientRect().top;
                const footerTop = footer.getBoundingClientRect().top;
                scroller.scrollTop = 800;

                return scroller.scrollTop > 0
                    && Math.abs(header.getBoundingClientRect().top - headerTop) < 1
                    && Math.abs(footer.getBoundingClientRect().top - footerTop) < 1;
            })()
            JS)
        ->fill('#transaction-search', 'not submitted')
        ->press('Filters')
        ->press('Apply')
        ->assertQueryStringMissing('search')
        ->assertSeeIn('[data-test="transaction-matching-count"]', '52 matching Transactions')
        ->fill('#transaction-search', 'STAR')
        ->click('[data-test="transaction-search-submit"]')
        ->assertQueryStringHas('search', 'STAR')
        ->assertSeeIn('[data-test="transaction-matching-count"]', '1 matching Transaction')
        ->assertSeeIn('[data-test="transaction-row-id-'.$refund->id.'"]', '#'.$refund->id)
        ->assertSee('Starbucks refund')
        ->press('Filters')
        ->fill('#filter-amount-min', '12.50')
        ->fill('#filter-amount-max', '12.50')
        ->press('Apply')
        ->assertSee('Select a currency to filter by amount.')
        ->select('#filter-currency', 'PEN')
        ->click('#filter-kind-spending')
        ->click('#filter-kind-income')
        ->click('#filter-kind-transfer')
        ->press('Apply')
        ->assertSee('Starbucks refund')
        ->assertQueryStringHas('amount_min', '12.50')
        ->fill('#transaction-search', (string) $voided->id)
        ->click('[data-test="transaction-search-submit"]')
        ->assertSee('Old voided purchase')
        ->click('[data-test="transaction-'.$voided->id.'"]')
        ->assertSee('Voided')
        ->press('Close')
        ->assertQueryStringHas('search', (string) $voided->id)
        ->fill('#transaction-search', 'STAR')
        ->click('[data-test="transaction-search-submit"]')
        ->assertSee('Starbucks refund')
        ->assertQueryStringHas('search', 'STAR')
        ->assertQueryStringHas('amount_min', '12.50')
        ->assertNoJavaScriptErrors();

});

test('Transactions can classify a row from the Category dropdown', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->for($owner, 'owner')->spending()->create([
        'description' => 'Corner store',
    ]);
    $this->actingAs($owner);

    visit('/transactions')
        ->assertSeeIn('[data-test="transaction-row-id-'.$transaction->id.'"]', '#'.$transaction->id)
        ->click('[aria-label="Category for Corner store"]')
        ->click('@category-'.$transaction->id.'-option-'.$category->id)
        ->click('[data-test="apply-category-once-'.$transaction->id.'"]')
        ->assertSee('Groceries')
        ->assertNoJavaScriptErrors();

    expect($transaction->fresh()->category_id)->toBe($category->id);
});

test('date-only values stay fixed while instants follow the browser timezone', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-07-20',
        'confirmed_at' => CarbonImmutable::parse('2026-07-20 02:30:00 UTC'),
        'description' => 'Timezone boundary purchase',
    ]);
    $this->actingAs($owner);
    $dateSelector = '[data-slot="sheet-content"] [data-test="transaction-'.$transaction->id.'-occurred-on"]';

    $limaPage = visit(route('transactions.index', [
        'selected' => $transaction->id,
    ]))
        ->withLocale('en-US')
        ->withTimezone('America/Lima');

    $limaPage
        ->assertSeeIn(
            $dateSelector,
            '20 Jul 2026',
        )
        ->assertSeeIn(
            '[data-test="transaction-confirmed-at"]',
            '19 Jul 2026, 9:30 PM',
        )
        ->assertScript(
            'document.querySelector(\''.$dateSelector.' time\')?.dateTime === \'2026-07-20\'',
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
            $dateSelector,
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
    $matching = Transaction::factory()
        ->for($owner, 'owner')
        ->pen()
        ->provisional([ReviewableTransactionField::Description])
        ->create([
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
            'description' => 'Neighborhood market',
            'occurred_on' => '2026-07-20',
            'amount_minor' => 1250,
        ]);
    Transaction::factory()->for($owner, 'owner')->pen()->create([
        'description' => 'Unrelated pharmacy',
        'occurred_on' => '2026-07-21',
        'amount_minor' => 2500,
    ]);
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->fill('#transaction-search', 'Neighborhood')
        ->click('[data-test="transaction-search-submit"]')
        ->press('Filters')
        ->select('#filter-currency', 'PEN')
        ->fill('#filter-amount-max', '12.50')
        ->press('Apply')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertQueryStringHas('amount_max', '12.50')
        ->assertSee('Neighborhood market')
        ->assertDontSee('Unrelated pharmacy');

    $page->script(
        "document.body.style.minHeight = '2500px'; window.scrollTo(0, document.body.scrollHeight)",
    );

    $page->assertScript('window.scrollY > 0');

    $page->script("document.querySelector('[data-test=\"transaction-{$matching->id}\"]').click()");

    $page
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertQueryStringHas('amount_max', '12.50')
        ->assertQueryStringHas('selected')
        ->assertSee('Edit current Transaction')
        ->assertSee('Included in Net Spending')
        ->press('Advanced details')
        ->assertSee('Provenance')
        ->fill('Edit description', 'Neighborhood market Lima')
        ->press('Save Transaction')
        ->assertSee('Transaction updated.')
        ->assertQueryStringHas('search', 'Neighborhood')
        ->assertQueryStringHas('amount_max', '12.50')
        ->assertSee('Neighborhood market Lima')
        ->assertSee('Review clear')
        ->press('Close')
        ->assertScript('window.scrollY > 0')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the Transaction workspace stays actionable without horizontal scrolling on mobile', function () {
    $owner = User::factory()->create();
    $current = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'description' => 'Mobile market',
        'amount_minor' => 1_250,
        'occurred_on' => '2026-08-21',
    ]);
    Transaction::factory()->count(51)->for($owner, 'owner')->spending()->usd()->create([
        'description' => 'Earlier mobile market',
        'occurred_on' => '2026-08-20',
    ]);
    $this->actingAs($owner);

    $page = visit('/transactions')->on()->iPhone14Pro();

    $page
        ->assertSee('Mobile market')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->click('[data-test="transaction-'.$current->id.'"]')
        ->assertSee('Transaction summary')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->press('Close')
        ->press('Next')
        ->assertQueryStringHas('page', '2')
        ->assertSee('Earlier mobile market')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->press('Previous')
        ->assertSee('Mobile market')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('unmatched search terms and IDs leave the Transactions list empty', function () {
    $owner = User::factory()->create();
    Transaction::factory()->for($owner, 'owner')->create(['description' => 'Visible purchase']);
    $this->actingAs($owner);

    $page = visit('/transactions');

    $page
        ->fill('#transaction-search', 'oops')
        ->click('[data-test="transaction-search-submit"]')
        ->assertSee('No matching Transactions')
        ->assertQueryStringMissing('selected')
        ->fill('#transaction-search', '999999999')
        ->click('[data-test="transaction-search-submit"]')
        ->assertSee('No matching Transactions')
        ->assertQueryStringMissing('selected')
        ->fill('#transaction-search', '')
        ->click('[data-test="transaction-search-submit"]')
        ->assertSee('Visible purchase')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
