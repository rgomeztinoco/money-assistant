<?php

use App\Currency;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\SuspectedDuplicate;
use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot visit Insights', function () {
    $this->get(route('insights.index'))->assertRedirect(route('login'));
});

test('the owner can visit the detailed Insights destination', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('insights.index', [
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-18',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('insights/index'));
});

test('one or two complete months remain provisional history without a comparison', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-18 15:00:00 UTC'));
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $category = Category::factory()->for($owner, 'owner')->create();

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-06-12',
        'amount_minor' => 9_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-07-14',
        'amount_minor' => 12_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 4_500,
        'category_id' => $category->id,
    ]);

    $this->actingAs($owner)
        ->get(route('insights.index', [
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-18',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.month', '2026-08')
            ->where('period.label', 'August 2026')
            ->where('period.is_complete', false)
            ->where('period.spending_label', 'Spending to date')
            ->where('period.spending.totals.PEN', '4500')
            ->where('baseline.status', 'provisional')
            ->where('baseline.complete_month_count', 2)
            ->where('baseline.months.0.month', '2026-06')
            ->where('baseline.months.1.month', '2026-07')
            ->where('comparison', null));
});

test('ended months remain incomplete for every kind of outstanding Review Queue work', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-18 15:00:00 UTC'));
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $category = Category::factory()->for($owner, 'owner')->create();

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-01-10',
        'amount_minor' => 3_000,
        'category_id' => $category->id,
    ]);

    $receiptTransaction = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-02-10',
        'amount_minor' => 4_000,
        'category_id' => $category->id,
    ]);
    $receiptBreakdown = ReceiptBreakdown::factory()
        ->recycle($owner)
        ->for($receiptTransaction)
        ->create();
    LineItem::factory()->for($receiptBreakdown)->create([
        'category_id' => null,
        'line_total_minor' => 4_000,
    ]);

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->provisional([
        ReviewableTransactionField::MerchantDescription,
    ])->create([
        'occurred_on' => '2026-03-10',
        'amount_minor' => 4_000,
        'category_id' => $category->id,
    ]);

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-04-10',
        'amount_minor' => 4_000,
        'category_id' => $category->id,
        'refund_relationship_review_reasons' => [
            RefundRelationshipReviewReason::ReceiptBreakdownAllocationRequiresReview->value,
        ],
    ]);

    $firstDuplicate = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-05-10',
        'amount_minor' => 4_000,
        'category_id' => $category->id,
    ]);
    $secondDuplicate = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-05-11',
        'amount_minor' => 4_000,
        'category_id' => $category->id,
    ]);
    SuspectedDuplicate::factory()
        ->for($owner, 'owner')
        ->for($firstDuplicate, 'firstTransaction')
        ->for($secondDuplicate, 'secondTransaction')
        ->create();

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-06-10',
        'amount_minor' => 5_000,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-07-10',
        'amount_minor' => 6_000,
        'category_id' => $category->id,
    ]);

    $this->actingAs($owner)
        ->get(route('insights.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('baseline.status', 'established')
            ->where('baseline.complete_month_count', 3)
            ->has('baseline.months', 3)
            ->where('baseline.months.0.month', '2026-01')
            ->where('baseline.months.1.month', '2026-06')
            ->where('baseline.months.2.month', '2026-07'));
});

test('Insights query parameters are validated', function (array $query, string $field) {
    $this->actingAs(User::factory()->create())
        ->get(route('insights.index', $query))
        ->assertSessionHasErrors($field);
})->with([
    'invalid start date' => [['date_from' => 'August 1'], 'date_from'],
    'future start date' => [['date_from' => '2030-01-01'], 'date_from'],
    'range ends before it starts' => [[
        'date_from' => '2026-08-10',
        'date_to' => '2026-08-01',
    ], 'date_to'],
    'unknown section' => [['section' => 'forecast'], 'section'],
]);

test('three complete months establish arithmetic-average total and Category Spending Baselines', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-18 15:00:00 UTC'));
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);

    foreach ([
        '2026-05-10' => 9_000,
        '2026-06-10' => 12_000,
        '2026-07-10' => 15_000,
    ] as $occurredOn => $amountMinor) {
        Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
            'occurred_on' => $occurredOn,
            'amount_minor' => $amountMinor,
            'category_id' => $category->id,
        ]);
    }

    $this->actingAs($owner)
        ->get(route('insights.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('baseline.status', 'established')
            ->where('baseline.complete_month_count', 3)
            ->where('baseline.average.totals.USD', '0')
            ->where('baseline.average.totals.PEN', '12000')
            ->where('baseline.average.combined_total.currency', Currency::Pen->value)
            ->where('baseline.average.combined_total.amount_minor', '12000')
            ->where('baseline.average.combined_total.unavailable_reason', null)
            ->where('baseline.average.category_totals.0.category.id', $category->id)
            ->where('baseline.average.category_totals.0.category.name', 'Groceries')
            ->where('baseline.average.category_totals.0.totals.PEN', '12000')
            ->where('baseline.average.category_totals.0.combined_total.amount_minor', '12000'));
});

test('a completed month shows factual amount and percentage differences from its preceding baseline', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-18 15:00:00 UTC'));
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);

    foreach ([
        '2026-01-10' => 9_000,
        '2026-02-10' => 12_000,
        '2026-03-10' => 15_000,
        '2026-04-10' => 15_000,
    ] as $occurredOn => $amountMinor) {
        Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
            'occurred_on' => $occurredOn,
            'amount_minor' => $amountMinor,
            'category_id' => $category->id,
        ]);
    }

    $this->actingAs($owner)
        ->get(route('insights.index', [
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.is_complete', true)
            ->where('period.spending_label', 'Completed spending')
            ->where('comparison.baseline_months', ['2026-01', '2026-02', '2026-03'])
            ->where('comparison.totals.PEN.current_amount_minor', '15000')
            ->where('comparison.totals.PEN.baseline_average_minor', '12000')
            ->where('comparison.totals.PEN.difference_amount_minor', '3000')
            ->where('comparison.totals.PEN.difference_percentage_basis_points', '2500')
            ->where('comparison.combined_total.current_amount_minor', '15000')
            ->where('comparison.combined_total.difference_amount_minor', '3000')
            ->where('comparison.combined_total.difference_percentage_basis_points', '2500')
            ->where('comparison.category_totals.0.category.id', $category->id)
            ->where('comparison.category_totals.0.totals.PEN.difference_amount_minor', '3000'));
});

test('missing rates preserve original-currency Insights and only suppress affected combined comparisons', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-18 15:00:00 UTC'));
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $groceries = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $travel = Category::factory()->for($owner, 'owner')->create(['name' => 'Travel']);

    foreach (['2026-01-10', '2026-02-10', '2026-03-10'] as $occurredOn) {
        Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
            'occurred_on' => $occurredOn,
            'amount_minor' => 9_000,
            'category_id' => $groceries->id,
        ]);
    }

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-04-10',
        'amount_minor' => 12_000,
        'category_id' => $groceries->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'occurred_on' => '2026-04-11',
        'amount_minor' => 5_000,
        'category_id' => $travel->id,
    ]);

    $this->actingAs($owner)
        ->get(route('insights.index', [
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.spending.totals.USD', '5000')
            ->where('period.spending.totals.PEN', '12000')
            ->where('comparison.totals.USD.current_amount_minor', '5000')
            ->where('comparison.totals.USD.difference_amount_minor', '5000')
            ->where('comparison.combined_total.current_amount_minor', null)
            ->where('comparison.combined_total.unavailable_reason', 'missing_exchange_rates')
            ->where('comparison.combined_total.missing_rate_dates', ['2026-04-11'])
            ->where('comparison.category_totals.0.category.id', $groceries->id)
            ->where('comparison.category_totals.0.combined_total.difference_amount_minor', '3000')
            ->where('comparison.category_totals.0.combined_total.unavailable_reason', null)
            ->where('comparison.category_totals.1.category.id', $travel->id)
            ->where('comparison.category_totals.1.totals.USD.current_amount_minor', '5000')
            ->where('comparison.category_totals.1.combined_total.difference_amount_minor', null)
            ->where('comparison.category_totals.1.combined_total.unavailable_reason', 'missing_exchange_rates'));
});
