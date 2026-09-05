<?php

use App\CategoryAssignmentProvenance;
use App\IncomeSource;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\MerchantRule;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\TransferPurpose;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('Breakdown opens the current month with every currency kept independent', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Dining',
    ]);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-02',
        'amount_minor' => 6_000,
        'description' => 'Neighborhood market',
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-02',
        'amount_minor' => 2_000,
        'description' => 'Cafe Central',
        'category_id' => $dining->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2026-08-03',
        'amount_minor' => 1_000,
        'description' => 'Transit Refund',
        'category_id' => $transport->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->income()->pen()->create([
        'occurred_on' => '2026-08-05',
        'amount_minor' => 10_000,
        'income_source' => IncomeSource::Salary,
        'description' => 'Salary',
    ]);
    Transaction::factory()->for($owner, 'owner')->transfer()->pen()->create([
        'occurred_on' => '2026-08-06',
        'amount_minor' => 3_000,
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
        'description' => 'Savings transfer',
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-08-07',
        'amount_minor' => 99_000,
        'description' => 'USD purchase',
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2025-01-07',
        'description' => 'Archived merchant',
    ]);

    $response = $this->actingAs($owner)->get(route('breakdown.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('breakdown/index')
            ->where('currency_filter', null)
            ->where('period.unit', 'month')
            ->where('period.date_from', '2026-08-01')
            ->where('period.date_to', '2026-08-31')
            ->where('coverage.date_from', '2026-08-02')
            ->where('coverage.date_to', '2026-08-07')
            ->where('summary.PEN.net_spending_minor', '7000')
            ->where('summary.PEN.income_minor', '10000')
            ->where('summary.PEN.moved_to_savings_minor', '3000')
            ->where('summary.USD.net_spending_minor', '99000')
            ->where('categorization.PEN.transaction_count', 3)
            ->where('categorization.PEN.uncategorized_transaction_count', 0)
            ->where('categorization.USD.uncategorized_transaction_count', 1)
            ->where('categorization.USD.uncategorized_amount_minor', '99000')
            ->where('categorization.USD.uncategorized_percentage', '100')
            ->has('category_groups', 3)
            ->where('category_groups.0.category.name', 'Food')
            ->where('category_groups.0.amount_minor.PEN', '8000')
            ->where('category_groups.0.percentage.PEN', '100')
            ->where('category_groups.0.children.0.category.name', 'Dining')
            ->has('days', 31)
            ->where('days.0.date', '2026-08-01')
            ->where('days.0.date_to', '2026-08-01')
            ->where('days.0.net_spending_minor.PEN', '0')
            ->where('days.1.date', '2026-08-02')
            ->where('days.1.net_spending_minor.PEN', '8000')
            ->where('days.2.net_spending_minor.PEN', '-1000')
            ->where('days.30.date', '2026-08-31')
            ->where('days.30.net_spending_minor.PEN', '0')
            ->has('transaction_days', 5)
            ->where('transaction_days.0.date', '2026-08-07')
            ->where('transaction_days.0.transactions.0.description', 'USD purchase')
            ->missing('merchant_options'));
});

test('Breakdown uses the current month and supports calendar period units and custom ranges', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-22 15:00:00', config('app.timezone')));
    $owner = User::factory()->create();

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-06-12',
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-17',
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-08-10',
    ]);

    $this->actingAs($owner)
        ->get(route('breakdown.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.unit', 'month')
            ->where('chart_granularity', 'day')
            ->has('days', 31)
            ->where('period.date_from', '2026-08-01')
            ->where('period.date_to', '2026-08-31'));

    $this->get(route('breakdown.index', ['period' => 'week', 'anchor' => '2026-08-12']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('chart_granularity', 'day')
            ->has('days', 7)
            ->where('period.date_from', '2026-08-10')
            ->where('period.date_to', '2026-08-16'));

    $this->get(route('breakdown.index', ['period' => 'quarter', 'anchor' => '2026-05-12']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('chart_granularity', 'week')
            ->has('days', 13)
            ->where('days.0.date', '2026-04-01')
            ->where('days.0.date_to', '2026-04-07')
            ->where('days.12.date', '2026-06-24')
            ->where('days.12.date_to', '2026-06-30')
            ->where('period.date_from', '2026-04-01')
            ->where('period.date_to', '2026-06-30'));

    $this->get(route('breakdown.index', ['period' => 'year', 'anchor' => '2025-05-12']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('chart_granularity', 'month')
            ->has('days', 12)
            ->where('days.0.date', '2025-01-01')
            ->where('days.0.date_to', '2025-01-31')
            ->where('days.11.date', '2025-12-01')
            ->where('days.11.date_to', '2025-12-31')
            ->where('period.date_from', '2025-01-01')
            ->where('period.date_to', '2025-12-31'));

    $this->get(route('breakdown.index', [
        'preset' => 'custom',
        'date_from' => '2026-06-10',
        'date_to' => '2026-07-20',
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('period.unit', 'custom')
        ->where('chart_granularity', 'week')
        ->has('days', 6)
        ->where('period.date_from', '2026-06-10')
        ->where('period.date_to', '2026-07-20'));

    $this->get(route('breakdown.index', ['currency' => 'USD']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currency_filter', 'USD')
            ->has('transaction_days', 1)
            ->where('transaction_days.0.transactions.0.currency', 'USD'));

    $this->get(route('breakdown.index', ['period' => 'custom']))
        ->assertSessionHasErrors(['date_from', 'date_to']);
});

test('Category bars use each currency total spending as their denominator', function () {
    $owner = User::factory()->create();
    $insurance = Category::factory()->for($owner, 'owner')->create(['name' => 'Insurance']);
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);

    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-06-10',
        'amount_minor' => 45_600,
        'category_id' => $insurance->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'occurred_on' => '2026-06-11',
        'amount_minor' => 5_900,
        'category_id' => $food->id,
    ]);

    $this->actingAs($owner)
        ->get(route('breakdown.index', [
            'currency' => 'USD',
            'preset' => 'custom',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.USD.net_spending_minor', '51500')
            ->where('category_groups.0.category.id', $insurance->id)
            ->where('category_groups.0.amount_minor.USD', '45600')
            ->where('category_groups.0.percentage.USD', '88.54')
            ->where('category_groups.1.category.id', $food->id)
            ->where('category_groups.1.percentage.USD', '11.46'));
});

test('Category and day selections filter the same supporting Breakdown detail', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $dining = Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Dining',
    ]);
    $transport = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);
    $market = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-10',
        'amount_minor' => 5_000,
        'description' => 'Neighborhood market',
        'category_id' => $food->id,
    ]);
    $cafe = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-11',
        'amount_minor' => 3_000,
        'description' => 'Cafe Central',
        'category_id' => $transport->id,
    ]);
    $splitTransaction = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-11',
        'amount_minor' => 4_000,
        'description' => 'Department store',
        'category_id' => $transport->id,
    ]);
    $breakdown = ReceiptBreakdown::factory()->for($splitTransaction)->create();
    LineItem::factory()->for($breakdown)->create([
        'category_id' => $dining->id,
        'line_total_minor' => 2_500,
    ]);
    LineItem::factory()->for($breakdown)->create([
        'category_id' => null,
        'line_total_minor' => 1_500,
    ]);

    $this->actingAs($owner)
        ->get(route('breakdown.index', [
            'preset' => 'custom',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'category' => (string) $food->id,
            'day' => '2026-07-11',
            'selected' => $splitTransaction->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.category', (string) $food->id)
            ->where('filters.day', '2026-07-11')
            ->where('filters.selected', $splitTransaction->id)
            ->has('days', 31)
            ->where('days.0.date', '2026-07-01')
            ->where('days.0.net_spending_minor.PEN', '0')
            ->where('days.9.date', '2026-07-10')
            ->where('days.9.net_spending_minor.PEN', '5000')
            ->where('days.10.date', '2026-07-11')
            ->where('days.10.net_spending_minor.PEN', '4000')
            ->where('days.30.date', '2026-07-31')
            ->where('categorization.PEN.transaction_count', 3)
            ->where('categorization.PEN.uncategorized_transaction_count', 1)
            ->where('categorization.PEN.uncategorized_amount_minor', '1500')
            ->where('categorization.PEN.uncategorized_percentage', '12.5')
            ->has('transaction_days', 1)
            ->where('transaction_days.0.transactions.0.id', $splitTransaction->id)
            ->where('transaction_days.0.transactions.0.split.0.category.name', 'Dining')
            ->has('merchants', 1)
            ->where('merchants.0.name', 'Department store')
            ->where('merchants.0.amount_minor.PEN', '4000'));

    $this->get(route('breakdown.index', [
        'preset' => 'custom',
        'date_from' => '2026-07-01',
        'date_to' => '2026-07-31',
        'category' => 'uncategorized',
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('category_groups.2.category.name', 'Uncategorized')
        ->where('category_groups.2.percentage.PEN', '12.5')
        ->has('transaction_days', 1)
        ->where('transaction_days.0.transactions.0.id', $splitTransaction->id));

    expect($market->id)->not->toBe($cafe->id);
});

test('Category options expose parent groups and zero-spend Categories stay out of the chart', function () {
    $owner = User::factory()->create();
    $unused = Category::factory()->for($owner, 'owner')->create(['name' => 'Aardvarks']);
    $child = Category::factory()->for($owner, 'owner')->for($unused, 'parent')->create([
        'name' => 'Annual subscriptions',
    ]);
    $used = Category::factory()->for($owner, 'owner')->create(['name' => 'Zebras']);
    $netZero = Category::factory()->for($owner, 'owner')->create(['name' => 'Zero']);

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-10',
        'amount_minor' => 2_000,
        'category_id' => $used->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-07-11',
        'amount_minor' => 1_000,
        'category_id' => $netZero->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2026-07-12',
        'amount_minor' => 1_000,
        'category_id' => $netZero->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->income()->pen()->create([
        'occurred_on' => '2026-07-13',
        'income_source' => IncomeSource::Investments,
    ]);

    $this->actingAs($owner)
        ->get(route('breakdown.index', [
            'preset' => 'custom',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('category_groups', 1)
            ->where('category_groups.0.category.id', $used->id)
            ->where('category_options.0.id', $unused->id)
            ->where('category_options.0.name', 'Aardvarks')
            ->where('category_options.0.parent', null)
            ->where('category_options.1.id', $child->id)
            ->where('category_options.1.name', 'Annual subscriptions')
            ->where('category_options.1.parent.id', $unused->id)
            ->where('category_options.1.parent.name', 'Aardvarks')
            ->missing('category_options.0.used')
            ->where('income_source_options.0.value', IncomeSource::Investments->value)
            ->where('income_source_options.0.used', true));
});

test('briefing and Trend links focus Breakdown on their exact supporting Transactions', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $merchant = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-10',
        'description' => 'Café Central',
        'category_id' => $category->id,
    ]);
    $income = Transaction::factory()->for($owner, 'owner')->income()->pen()->create([
        'occurred_on' => '2026-08-11',
    ]);
    $savings = Transaction::factory()->for($owner, 'owner')->transfer()->pen()->create([
        'occurred_on' => '2026-08-12',
        'direction' => MovementDirection::Debit,
        'transfer_purpose' => TransferPurpose::Savings,
    ]);
    $attention = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-13',
        'category_id' => null,
    ]);
    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-14',
        'description' => 'Different merchant',
        'category_id' => $category->id,
    ]);
    $this->actingAs($owner);
    $period = [
        'preset' => 'custom',
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-22',
    ];

    $this->get(route('breakdown.index', [...$period, 'merchant' => 'café central']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.merchant', 'café central')
            ->has('transaction_days', 1)
            ->where('transaction_days.0.transactions.0.id', $merchant->id));

    $this->get(route('breakdown.index', [...$period, 'focus' => 'income']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.focus', 'income')
            ->has('transaction_days', 1)
            ->where('transaction_days.0.transactions.0.id', $income->id));

    $this->get(route('breakdown.index', [...$period, 'focus' => 'savings']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.focus', 'savings')
            ->has('transaction_days', 1)
            ->where('transaction_days.0.transactions.0.id', $savings->id));

    $this->get(route('breakdown.index', [...$period, 'attention' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.attention', true)
            ->has('transaction_days', 1)
            ->where('transaction_days.0.transactions.0.id', $attention->id));
});

test('the owner changes a Category once or confirms the exact merchant for history and future activity', function () {
    $owner = User::factory()->create();
    $groceries = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $oneOff = Category::factory()->for($owner, 'owner')->create(['name' => 'One-off']);
    $current = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'description' => 'Café Central',
    ]);
    $matchingHistory = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'description' => ' café central ',
        'category_id' => $oneOff->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $differentCurrency = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'description' => 'Cafe Central',
    ]);

    $this->actingAs($owner)
        ->put(route('breakdown.transactions.classification.update', $current), [
            'category_id' => $oneOff->id,
            'apply_to_matching' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('breakdown.index'));

    expect($current->refresh())
        ->category_id->toBe($oneOff->id)
        ->merchant_rule_id->toBeNull()
        ->and($matchingHistory->refresh()->category_id)->toBe($oneOff->id)
        ->and(MerchantRule::query()->doesntExist())->toBeTrue();

    $this->put(route('breakdown.transactions.classification.update', $current), [
        'category_id' => $groceries->id,
        'apply_to_matching' => true,
    ])->assertSessionHasNoErrors();

    $merchantRule = MerchantRule::query()->sole();

    expect($current->refresh())
        ->category_id->toBe($groceries->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::MerchantRule)
        ->merchant_rule_id->toBe($merchantRule->id)
        ->and($matchingHistory->refresh())
        ->category_id->toBe($groceries->id)
        ->merchant_rule_id->toBe($merchantRule->id)
        ->and($differentCurrency->refresh()->category_id)->toBeNull()
        ->and($merchantRule->transaction_kind?->value)->toBe('spending')
        ->and($merchantRule->currency?->value)->toBe('PEN');

    $this->post(route('transactions.store'), [
        'occurred_on' => '2026-08-22',
        'amount' => '12.00',
        'currency' => 'PEN',
        'kind' => 'spending',
        'description' => 'CAFÉ CENTRAL',
    ])->assertSessionHasNoErrors();

    expect(Transaction::query()->latest('id')->first())
        ->category_id->toBe($groceries->id)
        ->merchant_rule_id->toBe($merchantRule->id);

    $this->put(route('breakdown.transactions.classification.update', $current), [
        'category_id' => null,
        'apply_to_matching' => false,
    ])->assertSessionHasNoErrors();

    expect($current->refresh())
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->merchant_rule_id->toBeNull()
        ->and($matchingHistory->refresh()->category_id)->toBe($groceries->id);
});

test('the owner changes an Income Source inline without exposing a Spending Category', function () {
    $owner = User::factory()->create();
    $income = Transaction::factory()->for($owner, 'owner')->income()->pen()->create([
        'income_source' => IncomeSource::Other,
    ]);

    $this->actingAs($owner)
        ->put(route('breakdown.transactions.classification.update', $income), [
            'income_source' => IncomeSource::IndependentWork->value,
        ])
        ->assertSessionHasNoErrors();

    expect($income->refresh())
        ->income_source->toBe(IncomeSource::IndependentWork)
        ->category_id->toBeNull();
});
