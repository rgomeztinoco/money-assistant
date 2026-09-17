<?php

use App\IncomeSource;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\ReviewableTransactionField;
use App\TransferPurpose;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('Home gives the owner equal PEN and USD briefings', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);

    foreach ([
        '2026-05-10' => 1_000,
        '2026-06-10' => 2_000,
        '2026-07-10' => 3_000,
    ] as $occurredOn => $amountMinor) {
        Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
            'occurred_on' => $occurredOn,
            'amount_minor' => $amountMinor,
            'description' => "Food {$occurredOn}",
            'category_id' => $food->id,
        ]);
    }

    $foodSpending = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-04',
        'amount_minor' => 4_000,
        'description' => 'Neighborhood Market',
        'category_id' => $food->id,
    ]);
    $foodRefund = Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2026-08-05',
        'amount_minor' => 500,
        'description' => 'Neighborhood Market refund',
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->income()->pen()->create([
        'occurred_on' => '2026-08-06',
        'amount_minor' => 10_000,
        'income_source' => IncomeSource::Salary,
    ]);
    Transaction::factory()->for($owner, 'owner')->transfer()->pen()->create([
        'occurred_on' => '2026-08-07',
        'amount_minor' => 2_500,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->provisional([
        ReviewableTransactionField::Description,
    ])->create([
        'occurred_on' => '2026-08-08',
        'amount_minor' => 300,
        'category_id' => null,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-07-09',
        'amount_minor' => 700,
        'description' => 'Previous Dollar Market',
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-08-09',
        'amount_minor' => 1_500,
        'description' => 'Dollar Market',
    ]);

    $this->actingAs($owner)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('currency_filter', null)
            ->where('period.unit', 'month')
            ->where('period.anchor', '2026-08-01')
            ->where('period.date_from', '2026-08-01')
            ->where('period.date_to', '2026-08-22')
            ->where('today', '2026-08-22')
            ->where('primary.currency', 'PEN')
            ->where('primary.period.date_from', '2026-08-01')
            ->where('primary.period.date_to', '2026-08-22')
            ->where('primary.coverage.date_from', '2026-08-04')
            ->where('primary.coverage.date_to', '2026-08-08')
            ->where('primary.coverage.transaction_count', 5)
            ->where('primary.summary.net_spending_minor', '3800')
            ->where('primary.summary.income_minor', '10000')
            ->where('primary.summary.moved_to_savings_minor', '2500')
            ->where('primary.pulse.previous_period.date_from', '2026-07-01')
            ->where('primary.pulse.previous_period.date_to', '2026-07-22')
            ->where('primary.pulse.previous_net_spending_minor', '3000')
            ->where('primary.pulse.change_minor', '800')
            ->where('primary.pulse.percentage_change', 27)
            ->has('primary.pulse.daily_net_spending', 23)
            ->where('primary.pulse.daily_net_spending.0.day', 0)
            ->where('primary.pulse.daily_net_spending.0.current_minor', '0')
            ->where('primary.pulse.daily_net_spending.0.previous_minor', '0')
            ->where('primary.pulse.daily_net_spending.4.current_minor', '4000')
            ->where('primary.pulse.daily_net_spending.4.previous_minor', '0')
            ->where('primary.pulse.daily_net_spending.10.current_minor', '3800')
            ->where('primary.pulse.daily_net_spending.10.previous_minor', '3000')
            ->where('primary.pulse.signals.0.category.id', $food->id)
            ->where('primary.pulse.signals.0.category.name', 'Food')
            ->where('primary.pulse.signals.0.current_total_minor', '3500')
            ->where('primary.pulse.signals.0.previous_total_minor', '3000')
            ->where('primary.pulse.signals.0.change_minor', '500')
            ->where('primary.pulse.signals.0.current_transaction_count', 2)
            ->where('primary.pulse.signals.0.previous_transaction_count', 1)
            ->has('primary.pulse.signals.0.evidence', 3)
            ->where('primary.pulse.signals.0.evidence.0.id', $foodSpending->id)
            ->where('primary.pulse.signals.0.evidence.0.description', 'Neighborhood Market')
            ->where('primary.pulse.signals.0.evidence.0.amount_minor', '4000')
            ->where('primary.pulse.signals.0.evidence.0.period', 'current')
            ->where('primary.pulse.signals.0.evidence.1.description', 'Food 2026-07-10')
            ->where('primary.pulse.signals.0.evidence.1.amount_minor', '3000')
            ->where('primary.pulse.signals.0.evidence.1.period', 'previous')
            ->where('primary.pulse.signals.0.evidence.2.id', $foodRefund->id)
            ->where('primary.pulse.signals.0.evidence.2.amount_minor', '-500')
            ->where('primary.pulse.signals.0.evidence.2.period', 'current')
            ->where('primary.pulse.signals.1.category.id', null)
            ->where('primary.pulse.signals.1.current_total_minor', '300')
            ->where('primary.pulse.signals.1.previous_total_minor', '0')
            ->where('primary.input_request.transaction_count', 1)
            ->where('secondary.currency', 'USD')
            ->where('secondary.period.date_from', '2026-08-01')
            ->where('secondary.summary.net_spending_minor', '1500')
            ->where('secondary.pulse.previous_net_spending_minor', '700')
            ->where('secondary.pulse.change_minor', '800')
            ->where('secondary.pulse.daily_net_spending.9.current_minor', '1500')
            ->where('secondary.pulse.daily_net_spending.9.previous_minor', '700')
            ->where('secondary.pulse.signals.0.category.id', null)
            ->where('secondary.pulse.signals.0.current_total_minor', '1500')
            ->where('secondary.pulse.signals.0.previous_total_minor', '700')
            ->where('secondary.pulse.signals.0.evidence.0.description', 'Dollar Market')
            ->where('secondary.pulse.signals.0.evidence.1.description', 'Previous Dollar Market')
            ->where('secondary.input_request.transaction_count', 1)
            ->missing('recent_transactions')
            ->missing('review_queue')
            ->missing('gmail')
            ->missing('parser_profiles'));
});

test('Home keeps previous-only activity for both currencies in All', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 1_000,
    ]);
    $previousUsd = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-07-10',
        'amount_minor' => 2_500,
        'description' => 'Previous USD purchase',
    ]);

    $this->actingAs($owner)
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('primary.currency', 'PEN')
            ->where('secondary.currency', 'USD')
            ->where('secondary.coverage.transaction_count', 0)
            ->where('secondary.summary.net_spending_minor', '0')
            ->where('secondary.pulse.previous_net_spending_minor', '2500')
            ->where('secondary.pulse.change_minor', '-2500')
            ->where('secondary.pulse.daily_net_spending.10.previous_minor', '2500')
            ->where('secondary.pulse.signals.0.evidence.0.id', $previousUsd->id)
            ->where('secondary.pulse.signals.0.evidence.0.period', 'previous')
            ->where('secondary.input_request', null));
});

test('Home compares an empty selected period with previous Net Spending', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $previousTransaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-10',
        'amount_minor' => 3_000,
        'description' => 'Previous grocery shop',
        'category_id' => $food->id,
    ]);

    $this->actingAs($owner)
        ->get(route('home', [
            'period' => 'month',
            'anchor' => '2026-08-01',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('primary.coverage.date_from', '2026-08-01')
            ->where('primary.coverage.date_to', '2026-08-22')
            ->where('primary.coverage.transaction_count', 0)
            ->where('primary.summary.net_spending_minor', '0')
            ->where('primary.pulse.previous_net_spending_minor', '3000')
            ->where('primary.pulse.change_minor', '-3000')
            ->where('primary.pulse.percentage_change', -100)
            ->where('primary.pulse.daily_net_spending.10.current_minor', '0')
            ->where('primary.pulse.daily_net_spending.10.previous_minor', '3000')
            ->where('primary.pulse.signals.0.category.id', $food->id)
            ->where('primary.pulse.signals.0.evidence.0.id', $previousTransaction->id)
            ->where('primary.pulse.signals.0.evidence.0.period', 'previous'));
});

test('Home uses the latest meaningful period and omits an empty USD summary', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-06-12',
        'amount_minor' => 1_000,
    ]);

    $this->actingAs($owner)
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('primary.period.date_from', '2026-06-01')
            ->where('primary.period.date_to', '2026-06-30')
            ->where('primary.summary.net_spending_minor', '1000')
            ->where('secondary', null)
            ->where('primary.input_request.transaction_count', 1));
});

test('Home uses receipt splits instead of the Transaction category for pulse signals', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create(['name' => 'Dining']);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);

    foreach (['2026-05-10', '2026-06-10', '2026-07-10'] as $occurredOn) {
        $transaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
            'occurred_on' => $occurredOn,
            'amount_minor' => 2_000,
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

    $this->actingAs($owner)
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('primary.summary.net_spending_minor', '4000')
            ->where('primary.pulse.signals.0.category.id', $food->id)
            ->where('primary.pulse.signals.0.category.name', 'Food')
            ->where('primary.pulse.signals.0.current_total_minor', '3000')
            ->where('primary.pulse.signals.0.previous_total_minor', '1000')
            ->where('primary.pulse.signals.0.change_minor', '2000')
            ->where('primary.pulse.signals.0.evidence.0.id', $currentTransaction->id)
            ->where('primary.pulse.signals.0.evidence.0.amount_minor', '3000'));
});

test('Home does not invent empty totals when the owner has no Transactions', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('primary', null)
            ->where('secondary', null));
});

test('guests are redirected from Home to login', function () {
    $this->get(route('home'))
        ->assertRedirectToRoute('login');
});

test('Home applies the shared currency and period filters', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-10',
        'amount_minor' => 90_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-07-10',
        'amount_minor' => 2_500,
    ]);

    $response = $this->actingAs($owner)
        ->get(route('home', [
            'currency' => 'USD',
            'period' => 'month',
            'anchor' => '2026-07-12',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currency_filter', 'USD')
            ->where('period.unit', 'month')
            ->where('period.anchor', '2026-07-01')
            ->where('period.date_from', '2026-07-01')
            ->where('period.date_to', '2026-07-31')
            ->where('primary.currency', 'USD')
            ->where('primary.summary.net_spending_minor', '2500')
            ->where('secondary', null));

    expect(json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR))
        ->not->toContain('90000');
});

test('Home validates custom date ranges', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home', ['period' => 'custom']))
        ->assertSessionHasErrors(['date_from', 'date_to']);
});
