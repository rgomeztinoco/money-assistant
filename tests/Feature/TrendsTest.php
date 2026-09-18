<?php

use App\Models\Category;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('Trends compares month to date with six equivalent months and ranks financial impact', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);

    foreach (['2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10', '2026-07-10'] as $occurredOn) {
        Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
            'occurred_on' => $occurredOn,
            'amount_minor' => 1_000,
            'description' => 'Central Market',
            'category_id' => $food->id,
        ]);
        Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
            'occurred_on' => $occurredOn,
            'amount_minor' => 500,
            'description' => 'City Bus',
            'category_id' => $transport->id,
        ]);
    }

    $unusualTransaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-08',
        'amount_minor' => 4_000,
        'description' => 'Central Market',
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-12',
        'amount_minor' => 3_000,
        'description' => 'Central Market',
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-14',
        'amount_minor' => 1_000,
        'description' => 'City Bus',
        'category_id' => $transport->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-29',
        'amount_minor' => 999_999,
        'description' => 'Future purchase',
        'category_id' => $food->id,
    ]);

    $response = $this->actingAs($owner)
        ->get(route('trends.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('trends/index')
            ->where('currency_filter', null)
            ->where('currency', 'PEN')
            ->where('period.unit', 'month')
            ->where('period.anchor', '2026-08-01')
            ->where('period.date_from', '2026-08-01')
            ->where('period.date_to', '2026-08-31')
            ->where('today', '2026-08-22')
            ->has('comparison_periods', 6)
            ->where('comparison_periods.0.date_from', '2026-07-01')
            ->where('comparison_periods.0.date_to', '2026-07-22')
            ->where('comparison_periods.5.date_from', '2026-02-01')
            ->where('comparison_periods.5.date_to', '2026-02-22')
            ->where('summary.net_spending_minor', '8000')
            ->has('monthly_context', 7)
            ->where('monthly_context.0.month', '2026-02')
            ->where('monthly_context.0.total_minor', '1500')
            ->where('monthly_context.6.month', '2026-08')
            ->where('monthly_context.6.date_to', '2026-08-22')
            ->where('monthly_context.6.total_minor', '8000')
            ->where('findings.0.kind', 'category')
            ->where('findings.0.category.id', $food->id)
            ->where('findings.0.current_total_minor', '7000')
            ->where('findings.0.typical_total_minor', '1000')
            ->where('findings.0.change_minor', '6000')
            ->where('findings.0.period_totals_minor', ['1000', '1000', '1000', '1000', '1000', '1000', '7000'])
            ->where('findings.0.current_transaction_count', 2)
            ->where('findings.0.typical_transaction_count', 1)
            ->where('findings.0.unusual_transaction.id', $unusualTransaction->id)
            ->where('findings.0.unusual_transaction.amount_minor', '4000')
            ->where('findings.0.scenario.difference_minor', '6000')
            ->where('findings.1.kind', 'merchant')
            ->where('findings.1.merchant', 'Central Market')
            ->where('findings.1.current_total_minor', '7000')
            ->where('findings.1.typical_total_minor', '1000')
            ->where('findings.1.change_minor', '6000')
            ->where('findings.1.period_totals_minor', ['1000', '1000', '1000', '1000', '1000', '1000', '7000'])
            ->where('findings.1.current_transaction_count', 2)
            ->where('findings.1.typical_transaction_count', 1)
            ->where('findings.1.unusual_transaction.id', $unusualTransaction->id)
            ->where('secondary.currency', 'USD')
            ->where('secondary.summary', null)
            ->where('secondary.findings', [])
            ->where('secondary.monthly_context', [])
            ->missing('comparison_builder'));

    expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))
        ->not->toContain('999999')
        ->not->toContain('Future purchase');
});

test('Trends keeps currencies separate and selects USD through a persistent filter', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 90_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-07-10',
        'amount_minor' => 1_000,
        'description' => 'Bookshop',
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 2_500,
        'description' => 'Bookshop',
    ]);

    $response = $this->actingAs($owner)
        ->get(route('trends.index', ['currency' => 'USD']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currency_filter', 'USD')
            ->where('currency', 'USD')
            ->missing('available_currencies')
            ->where('summary.net_spending_minor', '2500')
            ->where('findings.0.currency', 'USD')
            ->where('secondary', null));

    expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))
        ->not->toContain('90000');
});

test('Trends uses receipt splits instead of the transaction category for findings', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Dining']);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);

    foreach (['2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10', '2026-07-10'] as $occurredOn) {
        $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
            'occurred_on' => $occurredOn,
            'amount_minor' => 2_000,
            'description' => 'Department Store',
            'category_id' => $transport->id,
        ]);
        $receiptBreakdown = ReceiptBreakdown::factory()->for($transaction)->create();
        LineItem::factory()->for($receiptBreakdown)->create([
            'category_id' => $dining->id,
            'line_total_minor' => 1_000,
        ]);
        LineItem::factory()->for($receiptBreakdown)->create([
            'category_id' => null,
            'line_total_minor' => 1_000,
        ]);
    }

    $currentTransaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 4_000,
        'description' => 'Department Store',
        'category_id' => $transport->id,
    ]);
    $currentBreakdown = ReceiptBreakdown::factory()->for($currentTransaction)->create();
    LineItem::factory()->for($currentBreakdown)->create([
        'category_id' => $dining->id,
        'line_total_minor' => 3_000,
    ]);
    LineItem::factory()->for($currentBreakdown)->create([
        'category_id' => null,
        'line_total_minor' => 1_000,
    ]);

    $response = $this->actingAs($owner)
        ->get(route('trends.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.net_spending_minor', '4000')
            ->where('findings.0.kind', 'category')
            ->where('findings.0.category.id', $food->id)
            ->where('findings.0.category.name', 'Food')
            ->where('findings.0.current_total_minor', '3000')
            ->where('findings.0.typical_total_minor', '1000')
            ->where('findings.0.change_minor', '2000'));

    expect(collect($response->inertiaProps('findings'))
        ->where('kind', 'category')
        ->pluck('category.id'))
        ->not->toContain($transport->id);
});

test('Trends keeps historical currency context without inventing current or empty-month totals', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 90_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-05-10',
        'amount_minor' => 1_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->usd()->create([
        'occurred_on' => '2026-06-10',
        'amount_minor' => 500,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-06-11',
        'amount_minor' => 500,
    ]);

    $this->actingAs($owner)
        ->get(route('trends.index', ['currency' => 'USD']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currency', 'USD')
            ->where('summary', null)
            ->where('findings', [])
            ->has('monthly_context', 7)
            ->where('monthly_context.0.month', '2026-02')
            ->where('monthly_context.0.total_minor', null)
            ->where('monthly_context.3.month', '2026-05')
            ->where('monthly_context.3.total_minor', '1000')
            ->where('monthly_context.4.month', '2026-06')
            ->where('monthly_context.4.total_minor', '0')
            ->where('monthly_context.6.month', '2026-08')
            ->where('monthly_context.6.total_minor', null));
});

test('Trends returns empty context for both currencies when all are selected', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));

    $this->actingAs(User::factory()->create())
        ->get(route('trends.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary', null)
            ->where('findings', [])
            ->where('monthly_context', [])
            ->where('secondary.currency', 'USD')
            ->where('secondary.summary', null)
            ->where('secondary.findings', [])
            ->where('secondary.monthly_context', []));
});

test('Trends keeps currencies separate when all currencies are selected', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 90_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 2_500,
    ]);

    $this->actingAs($owner)
        ->get(route('trends.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currency_filter', null)
            ->where('currency', 'PEN')
            ->where('summary.net_spending_minor', '90000')
            ->where('secondary.currency', 'USD')
            ->where('secondary.summary.net_spending_minor', '2500'));
});

test('Trends rejects unsupported currency filters', function () {
    $this->actingAs(User::factory()->create())
        ->get('/trends?currency=EUR')
        ->assertSessionHasErrors('currency');
});

test('Trends resolves calendar period selections and their equivalent comparisons', function (
    string $unit,
    string $anchor,
    string $dateFrom,
    string $dateTo,
    string $comparisonDateFrom,
    string $comparisonDateTo,
) {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));

    $this->actingAs(User::factory()->create())
        ->get(route('trends.index', ['period' => $unit, 'anchor' => $anchor]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.unit', $unit)
            ->where('period.anchor', $dateFrom)
            ->where('period.date_from', $dateFrom)
            ->where('period.date_to', $dateTo)
            ->where('comparison_periods.0.date_from', $comparisonDateFrom)
            ->where('comparison_periods.0.date_to', $comparisonDateTo));
})->with([
    'week' => ['week', '2026-08-12', '2026-08-10', '2026-08-16', '2026-08-03', '2026-08-09'],
    'month' => ['month', '2026-07-12', '2026-07-01', '2026-07-31', '2026-06-01', '2026-06-30'],
    'quarter' => ['quarter', '2026-05-12', '2026-04-01', '2026-06-30', '2026-01-01', '2026-03-31'],
    'year' => ['year', '2025-05-12', '2025-01-01', '2025-12-31', '2024-01-01', '2024-12-31'],
    'current week' => ['week', '2026-08-19', '2026-08-17', '2026-08-23', '2026-08-10', '2026-08-15'],
    'current month' => ['month', '2026-08-12', '2026-08-01', '2026-08-31', '2026-07-01', '2026-07-22'],
    'current quarter' => ['quarter', '2026-08-12', '2026-07-01', '2026-09-30', '2026-04-01', '2026-05-23'],
    'current year' => ['year', '2026-08-12', '2026-01-01', '2026-12-31', '2025-01-01', '2025-08-22'],
]);

test('Trends compares custom ranges with the six immediately preceding equal ranges', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('trends.index', [
            'period' => 'custom',
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-12',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.unit', 'custom')
            ->where('period.date_from', '2026-08-10')
            ->where('period.date_to', '2026-08-12')
            ->where('comparison_periods.0.date_from', '2026-08-07')
            ->where('comparison_periods.0.date_to', '2026-08-09')
            ->has('comparison_periods', 6)
            ->where('comparison_periods.5.date_from', '2026-07-23')
            ->where('comparison_periods.5.date_to', '2026-07-25'));
});

test('Trends preserves a custom range beyond today while analysis stops today', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-21',
        'amount_minor' => 1_000,
        'description' => 'Observed purchase',
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-29',
        'amount_minor' => 900_000,
        'description' => 'Future purchase',
    ]);

    $response = $this->actingAs($owner)
        ->get(route('trends.index', [
            'period' => 'custom',
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-31',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.unit', 'custom')
            ->where('period.date_from', '2026-08-20')
            ->where('period.date_to', '2026-08-31')
            ->where('comparison_periods.0.date_from', '2026-08-17')
            ->where('comparison_periods.0.date_to', '2026-08-19')
            ->where('summary.net_spending_minor', '1000')
            ->where('findings.0.current_total_minor', '1000')
            ->where('monthly_context.6.date_to', '2026-08-22')
            ->where('monthly_context.6.total_minor', '1000'));

    expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))
        ->not->toContain('900000')
        ->not->toContain('Future purchase');
});

test('Trends treats an entirely future selection as unobserved', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-09-03',
        'amount_minor' => 1_000,
    ]);

    $this->actingAs($owner)
        ->get(route('trends.index', [
            'period' => 'custom',
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-07',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.date_from', '2026-09-01')
            ->where('period.date_to', '2026-09-07')
            ->where('comparison_periods', [])
            ->where('summary', null)
            ->where('findings', [])
            ->where('monthly_context', [])
            ->where('secondary.summary', null)
            ->where('secondary.findings', [])
            ->where('secondary.monthly_context', []));
});

test('Trends validates custom date ranges', function (array $query, array $errors) {
    $this->actingAs(User::factory()->create())
        ->get(route('trends.index', $query))
        ->assertSessionHasErrors($errors);
})->with([
    'missing dates' => [
        ['period' => 'custom'],
        ['date_from', 'date_to'],
    ],
    'reversed dates' => [
        [
            'period' => 'custom',
            'date_from' => '2026-08-12',
            'date_to' => '2026-08-10',
        ],
        ['date_to'],
    ],
]);
