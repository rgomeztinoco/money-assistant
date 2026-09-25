<?php

use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner creates a Merchant Rule that categorizes a future Transaction', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $this->actingAs($owner);

    visit('/')
        ->click('[data-test="nav-merchant-rules"]')
        ->assertPathIs('/merchant-rules')
        ->assertSee('Existing Transactions never change.')
        ->press('New Merchant Rule')
        ->fill('#rule-merchant', 'CAFÉ—Central')
        ->assertSee('café central')
        ->select('#rule-category', $category->id)
        ->select('#rule-kind', 'spending')
        ->select('#rule-currency', 'PEN')
        ->press('Create Merchant Rule')
        ->assertSee('Merchant Rule created.')
        ->assertDontSee('café central')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    visit('/transactions')
        ->press('Add Transaction')
        ->fill('#manual-amount', '12.50')
        ->fill('#manual-description', "cafe\u{0301} central")
        ->select('#manual-currency', 'PEN')
        ->select('#manual-kind', 'spending')
        ->press('Record Transaction')
        ->assertSee('Transaction recorded.')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(MerchantRule::query()->sole()->merchant_key)->toBe('café central')
        ->and(Transaction::query()->sole())
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::MerchantRule);
});

test('clearing search restores the selected rule group', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $travel = Category::factory()->for($owner, 'owner')->create(['name' => 'Travel']);
    $cafes = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Cafés']);
    $taxis = Category::factory()->for($owner, 'owner')->for($travel, 'parent')->create(['name' => 'Taxis']);
    MerchantRule::factory()->for($owner, 'owner')->for($cafes)->create([
        'merchant' => 'Central Coffee',
        'merchant_key' => 'central coffee',
    ]);
    MerchantRule::factory()->for($owner, 'owner')->for($taxis)->disabled()->create([
        'merchant' => 'Airport Taxi',
        'merchant_key' => 'airport taxi',
    ]);
    $this->actingAs($owner);

    $page = visit(
        '/merchant-rules?category_id='.$cafes->id.'&search=airport',
    );

    $page
        ->assertSeeIn('@rule-table-title', 'All Rules')
        ->assertSee('Airport Taxi')
        ->assertScript(<<<'JS'
            (() => {
                const table = document.querySelector('[data-slot="table"]');
                const firstRow = table?.querySelector('tbody tr');
                const headers = Array.from(table?.querySelectorAll('thead th') ?? []);
                const firstSortButton = headers[0]?.querySelector('button');
                const tableContainer = table?.closest('[data-slot="table-container"]');

                if (
                    firstRow === null
                    || firstRow === undefined
                    || firstSortButton === null
                    || firstSortButton === undefined
                    || tableContainer === null
                    || tableContainer === undefined
                ) {
                    return false;
                }

                const columnsAlign = headers.every((header, index) => {
                    const button = header.querySelector('button');
                    const cell = firstRow.children[index];

                    if (button === null || !(cell instanceof HTMLElement)) {
                        return true;
                    }

                    const buttonBounds = button.getBoundingClientRect();
                    const cellBounds = cell.getBoundingClientRect();
                    const buttonStyle = getComputedStyle(button);
                    const cellStyle = getComputedStyle(cell);
                    const headerEdge = buttonBounds.left
                        + parseFloat(buttonStyle.paddingLeft);
                    const cellEdge = cellBounds.left
                        + parseFloat(cellStyle.paddingLeft);

                    return Math.abs(headerEdge - cellEdge) < 1;
                });

                return columnsAlign
                    && firstSortButton.getBoundingClientRect().left
                        - tableContainer.getBoundingClientRect().left >= 4;
            })()
            JS)
        ->fill(
            'input[type="search"][aria-label="Search Merchant Rules"]',
            '',
        )
        ->press('Search')
        ->assertSeeIn('@rule-table-title', 'Food > Cafés')
        ->assertSee('Central Coffee')
        ->assertDontSee('Airport Taxi')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the filter popover narrows rules and shows its active count', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $travel = Category::factory()->for($owner, 'owner')->create(['name' => 'Travel']);
    MerchantRule::factory()->for($owner, 'owner')->for($food)->create([
        'merchant' => 'Central Coffee',
        'merchant_key' => 'central coffee',
    ]);
    MerchantRule::factory()->for($owner, 'owner')->for($travel)->disabled()->create([
        'merchant' => 'Airport Taxi',
        'merchant_key' => 'airport taxi',
    ]);
    $this->actingAs($owner);

    $page = visit('/merchant-rules');

    $page
        ->click('@rule-browser-all')
        ->click('@rule-filters-trigger')
        ->select('status-filter', 'disabled')
        ->assertSee('Airport Taxi')
        ->assertDontSee('Central Coffee')
        ->assertSeeIn('@rule-filters-trigger', '1')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('row controls update status and confirm deletion', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Travel']);
    $airportTaxi = MerchantRule::factory()->for($owner, 'owner')->for($category)->disabled()->create([
        'merchant' => 'Airport Taxi',
        'merchant_key' => 'airport taxi',
    ]);
    $this->actingAs($owner);

    visit('/merchant-rules?status=disabled')
        ->click('[aria-label="Enable Airport Taxi"]')
        ->assertSee('Merchant Rule updated.')
        ->assertQueryStringHas('status', 'disabled')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($airportTaxi->fresh()->enabled)->toBeTrue();

    visit('/merchant-rules')
        ->assertSee('Airport Taxi')
        ->click('[aria-label="Actions for Airport Taxi"]')
        ->click('Delete')
        ->assertSee('Delete Airport Taxi?')
        ->press('Delete Merchant Rule')
        ->assertSee('Merchant Rule deleted.')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $this->assertSoftDeleted($airportTaxi);
});

test('the mobile rule selector navigates full Category paths', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $cafes = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Cafés']);
    MerchantRule::factory()->for($owner, 'owner')->for($cafes)->create([
        'merchant' => 'Central Coffee',
        'merchant_key' => 'central coffee',
    ]);
    $this->actingAs($owner);

    visit('/merchant-rules')
        ->on()
        ->mobile()
        ->assertSeeIn('@rule-table-title', 'All Rules')
        ->click('@mobile-rule-category-trigger')
        ->fill('[cmdk-input][aria-label="Search Categories"]', 'cafe')
        ->click('@mobile-rule-category-option-'.$cafes->id)
        ->assertSeeIn('@rule-table-title', 'Food > Cafés')
        ->assertSee('Central Coffee')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('a Transaction opens a Merchant Rule dialog with known values prefilled', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'description' => 'CAFÉ—Central',
        'kind' => 'spending',
        'currency' => 'PEN',
    ]);
    $this->actingAs($owner);

    visit('/transactions')
        ->click('[data-test="transaction-'.$transaction->id.'"]')
        ->press('Create Merchant Rule')
        ->assertPathIs('/merchant-rules')
        ->assertSee('Known values are prefilled from the Transaction.')
        ->assertValue('#rule-merchant', 'CAFÉ—Central')
        ->assertSelected('#rule-kind', 'spending')
        ->assertSelected('#rule-currency', 'PEN')
        ->select('#rule-category', $category->id)
        ->press('Create Merchant Rule')
        ->assertPathIs('/merchant-rules')
        ->assertSee('Merchant Rule created.')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect(MerchantRule::query()->sole())
        ->merchant_key->toBe('café central')
        ->category_id->toBe($category->id);
});
