<?php

use App\Models\Category;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('Trends compares month to date with three equivalent months and ranks financial impact', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);

    foreach (['2026-05-10', '2026-06-10', '2026-07-10'] as $occurredOn) {
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

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-03-10',
        'amount_minor' => 300,
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-04-10',
        'amount_minor' => 400,
        'category_id' => $food->id,
    ]);
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

    $this->actingAs($owner)
        ->get(route('trends.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('trends/index')
            ->where('currency', 'PEN')
            ->where('period.date_from', '2026-08-01')
            ->where('period.date_to', '2026-08-22')
            ->has('comparison_periods', 3)
            ->where('comparison_periods.0.date_from', '2026-07-01')
            ->where('comparison_periods.0.date_to', '2026-07-22')
            ->where('comparison_periods.2.date_from', '2026-05-01')
            ->where('comparison_periods.2.date_to', '2026-05-22')
            ->where('summary.net_spending_minor', '8000')
            ->has('monthly_context', 6)
            ->where('monthly_context.0.month', '2026-03')
            ->where('monthly_context.0.total_minor', '300')
            ->where('monthly_context.5.month', '2026-08')
            ->where('monthly_context.5.total_minor', '8000')
            ->where('findings.0.kind', 'category')
            ->where('findings.0.category.id', $food->id)
            ->where('findings.0.current_total_minor', '7000')
            ->where('findings.0.typical_total_minor', '1000')
            ->where('findings.0.change_minor', '6000')
            ->where('findings.0.current_transaction_count', 2)
            ->where('findings.0.typical_transaction_count', 1)
            ->where('findings.0.unusual_transaction.id', $unusualTransaction->id)
            ->where('findings.0.unusual_transaction.amount_minor', '4000')
            ->where('findings.0.scenario.difference_minor', '6000')
            ->where('findings.1.kind', 'merchant')
            ->where('findings.1.merchant', 'Central Market')
            ->where('findings.1.change_minor', '6000')
            ->missing('comparison_builder'));
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
            ->where('currency', 'USD')
            ->where('available_currencies', ['PEN', 'USD'])
            ->where('summary.net_spending_minor', '2500')
            ->where('findings.0.currency', 'USD'));

    expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))
        ->not->toContain('90000');
});

test('Trends uses receipt splits instead of the transaction category for findings', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Dining']);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);

    foreach (['2026-05-10', '2026-06-10', '2026-07-10'] as $occurredOn) {
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
            ->where('available_currencies', ['PEN', 'USD'])
            ->where('summary', null)
            ->where('findings', [])
            ->has('monthly_context', 6)
            ->where('monthly_context.0.month', '2026-03')
            ->where('monthly_context.0.total_minor', null)
            ->where('monthly_context.2.month', '2026-05')
            ->where('monthly_context.2.total_minor', '1000')
            ->where('monthly_context.3.month', '2026-06')
            ->where('monthly_context.3.total_minor', '0')
            ->where('monthly_context.5.month', '2026-08')
            ->where('monthly_context.5.total_minor', null));
});

test('Trends has no currency context when the owner has no Transactions', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));

    $this->actingAs(User::factory()->create())
        ->get(route('trends.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('available_currencies', [])
            ->where('summary', null)
            ->where('findings', [])
            ->where('monthly_context', []));
});

test('Trends rejects unsupported currency filters', function () {
    $this->actingAs(User::factory()->create())
        ->get('/trends?currency=EUR')
        ->assertSessionHasErrors('currency');
});
