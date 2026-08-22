<?php

use App\Currency;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot visit currency reports', function () {
    $this->get(route('reports.show', Currency::Pen))
        ->assertRedirect(route('login'));
});

test('PEN and USD reports expose independent currency-only datasets', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-12 15:00:00 UTC'));
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-02',
        'amount_minor' => 5_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-08-03',
        'amount_minor' => 7_000,
    ]);
    $penResponse = $this->actingAs($owner)
        ->get(route('reports.show', [
            'currency' => Currency::Pen,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-12',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/show')
            ->where('currency', Currency::Pen->value)
            ->where('period.date_from', '2026-08-01')
            ->where('period.date_to', '2026-08-12')
            ->where('period.total_minor', '5000')
            ->has('monthly_history', 1)
            ->where('monthly_history.0.total_minor', '5000'));

    expect(json_encode($penResponse->inertiaProps(), JSON_THROW_ON_ERROR))
        ->not->toContain('USD');

    $usdResponse = $this->get(route('reports.show', [
        'currency' => Currency::Usd,
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-12',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/show')
            ->where('currency', Currency::Usd->value)
            ->where('period.total_minor', '7000')
            ->where('monthly_history.0.total_minor', '7000'));

    expect(json_encode($usdResponse->inertiaProps(), JSON_THROW_ON_ERROR))
        ->not->toContain('PEN');
});

test('reports subtract Refunds and exclude Voided Transactions within their currency', function () {
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-02',
        'amount_minor' => 5_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2026-08-03',
        'amount_minor' => 1_200,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-04',
        'amount_minor' => 9_000,
        'voided_at' => now(),
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->usd()->create([
        'occurred_on' => '2026-08-05',
        'amount_minor' => 3_000,
    ]);

    $this->actingAs($owner)
        ->get(route('reports.show', [
            'currency' => Currency::Pen,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-12',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.total_minor', '3800')
            ->where('monthly_history.0.total_minor', '3800'));
});

test('reports compare the selected range with the preceding range of equal length', function () {
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 3_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-06',
        'amount_minor' => 2_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-03',
        'amount_minor' => 50_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-16',
        'amount_minor' => 70_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 90_000,
    ]);

    $this->actingAs($owner)
        ->get(route('reports.show', [
            'currency' => Currency::Pen,
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-15',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('comparison.period.date_from', '2026-08-04')
            ->where('comparison.period.date_to', '2026-08-09')
            ->where('comparison.current_total_minor', '3000')
            ->where('comparison.previous_total_minor', '2000')
            ->where('comparison.change_minor', '1000')
            ->where('comparison.percentage_change', '50')
            ->where('comparison.direction', 'increased'));
});

test('reports preserve small decreases and previous-only comparisons without rounding them away', function () {
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 9_999,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-06',
        'amount_minor' => 10_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-08-06',
        'amount_minor' => 1_000,
    ]);
    $this->actingAs($owner);

    $this->get(route('reports.show', [
        'currency' => Currency::Pen,
        'date_from' => '2026-08-10',
        'date_to' => '2026-08-15',
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('comparison.change_minor', '-1')
        ->where('comparison.percentage_change', '-0.01')
        ->where('comparison.direction', 'decreased'));

    $this->get(route('reports.show', [
        'currency' => Currency::Usd,
        'date_from' => '2026-08-10',
        'date_to' => '2026-08-15',
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('comparison.current_total_minor', '0')
        ->where('comparison.previous_total_minor', '1000')
        ->where('comparison.change_minor', '-1000')
        ->where('comparison.percentage_change', '-100')
        ->where('comparison.direction', 'decreased'));
});

test('reports explain empty comparison periods without a percentage', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('reports.show', [
            'currency' => Currency::Usd,
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-15',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('comparison.current_total_minor', '0')
            ->where('comparison.previous_total_minor', '0')
            ->where('comparison.percentage_change', null)
            ->where('comparison.direction', 'no_activity'));
});

test('reports retain net-zero Transaction activity instead of describing it as empty', function () {
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 500,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2026-08-11',
        'amount_minor' => 500,
    ]);

    $this->actingAs($owner)
        ->get(route('reports.show', [
            'currency' => Currency::Pen,
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-15',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.total_minor', '0')
            ->where('comparison.current_total_minor', '0')
            ->where('comparison.previous_total_minor', '0')
            ->where('comparison.direction', 'unchanged')
            ->where('monthly_history.0.total_minor', '0')
            ->where('monthly_history.0.transaction_count', 2));
});

test('reports preserve nonzero percentage changes below one hundredth of a percent', function () {
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 99_999,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-06',
        'amount_minor' => 100_000,
    ]);

    $this->actingAs($owner)
        ->get(route('reports.show', [
            'currency' => Currency::Pen,
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-15',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('comparison.change_minor', '-1')
            ->where('comparison.percentage_change', '-0.001')
            ->where('comparison.direction', 'decreased'));
});

test('reports provide continuous monthly history through the selected period', function () {
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-01-10',
        'amount_minor' => 1_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-03-05',
        'amount_minor' => 3_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->usd()->create([
        'occurred_on' => '2026-03-12',
        'amount_minor' => 500,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-03-20',
        'amount_minor' => 8_000,
    ]);

    $this->actingAs($owner)
        ->get(route('reports.show', [
            'currency' => Currency::Usd,
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-15',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.total_minor', '2500')
            ->has('monthly_history', 3)
            ->where('monthly_history.0.month', '2026-01')
            ->where('monthly_history.0.total_minor', '1000')
            ->where('monthly_history.1.month', '2026-02')
            ->where('monthly_history.1.total_minor', '0')
            ->where('monthly_history.2.month', '2026-03')
            ->where('monthly_history.2.date_to', '2026-03-15')
            ->where('monthly_history.2.total_minor', '2500'));
});

test('Category groups roll children up once and current Receipt Breakdowns replace Transaction Categories', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->archived()->create([
        'name' => 'Dining',
    ]);
    $shopping = Category::factory()->for($owner, 'owner')->create(['name' => 'Shopping']);

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-02',
        'amount_minor' => 2_000,
        'category_id' => $dining->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-03',
        'amount_minor' => 1_000,
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2026-08-04',
        'amount_minor' => 500,
        'category_id' => $dining->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-05',
        'amount_minor' => 9_000,
        'category_id' => $dining->id,
        'voided_at' => now(),
    ]);

    $itemizedTransaction = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-06',
        'amount_minor' => 3_000,
        'category_id' => $shopping->id,
    ]);
    $receiptBreakdown = ReceiptBreakdown::factory()
        ->recycle($owner)
        ->for($itemizedTransaction)
        ->create();
    LineItem::factory()->for($receiptBreakdown)->create([
        'description' => 'Lunch',
        'line_total_minor' => 1_200,
        'category_id' => $dining->id,
    ]);
    LineItem::factory()->for($receiptBreakdown)->create([
        'description' => 'Uncategorized item',
        'line_total_minor' => 1_800,
        'category_id' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('reports.show', [
            'currency' => Currency::Pen,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-12',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.total_minor', '5500')
            ->has('category_groups', 2)
            ->where('category_groups.0.category.id', $food->id)
            ->where('category_groups.0.category.name', 'Food')
            ->where('category_groups.0.amount_minor', '3700')
            ->has('category_groups.0.children', 1)
            ->where('category_groups.0.children.0.category.id', $dining->id)
            ->where('category_groups.0.children.0.category.archived', true)
            ->where('category_groups.0.children.0.amount_minor', '2700')
            ->where('category_groups.1.category.id', null)
            ->where('category_groups.1.category.name', 'Uncategorized')
            ->where('category_groups.1.amount_minor', '1800')
            ->where('category_groups.1.children', []));
});

test('report filters are validated', function (array $query, string $field) {
    $this->actingAs(User::factory()->create())
        ->get(route('reports.show', ['currency' => Currency::Pen, ...$query]))
        ->assertSessionHasErrors($field);
})->with([
    'invalid start date' => [['date_from' => 'August 1'], 'date_from'],
    'future end date' => [['date_to' => '2030-01-01'], 'date_to'],
    'range ends before it starts' => [[
        'date_from' => '2026-08-10',
        'date_to' => '2026-08-01',
    ], 'date_to'],
]);

test('unsupported report currencies return not found', function () {
    $this->actingAs(User::factory()->create())
        ->get('/reports/EUR')
        ->assertNotFound();
});
