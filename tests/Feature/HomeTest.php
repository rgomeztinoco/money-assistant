<?php

use App\IncomeSource;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\ReviewableTransactionField;
use App\TransferPurpose;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('Home gives the owner one PEN briefing and a compact USD summary', function () {
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
            'category_id' => $food->id,
        ]);
    }

    Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-04',
        'amount_minor' => 4_000,
        'category_id' => $food->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2026-08-05',
        'amount_minor' => 500,
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
        'occurred_on' => '2026-08-09',
        'amount_minor' => 1_500,
    ]);

    $this->actingAs($owner)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('primary.currency', 'PEN')
            ->where('primary.period.date_from', '2026-08-01')
            ->where('primary.period.date_to', '2026-08-22')
            ->where('primary.coverage.date_from', '2026-08-04')
            ->where('primary.coverage.date_to', '2026-08-08')
            ->where('primary.coverage.transaction_count', 5)
            ->where('primary.summary.net_spending_minor', '3800')
            ->where('primary.summary.income_minor', '10000')
            ->where('primary.summary.moved_to_savings_minor', '2500')
            ->where('primary.material_change.category.id', $food->id)
            ->where('primary.material_change.category.name', 'Food')
            ->where('primary.material_change.current_total_minor', '3500')
            ->where('primary.material_change.typical_total_minor', '2000')
            ->where('primary.material_change.change_minor', '1500')
            ->has('primary.material_change.comparison_periods', 3)
            ->where('primary.material_change.comparison_periods.0.date_from', '2026-07-01')
            ->where('primary.material_change.comparison_periods.0.date_to', '2026-07-22')
            ->where('primary.material_change.comparison_periods.2.date_from', '2026-05-01')
            ->where('primary.material_change.comparison_periods.2.date_to', '2026-05-22')
            ->where('primary.input_request.transaction_count', 1)
            ->where('secondary.currency', 'USD')
            ->where('secondary.period.date_from', '2026-08-01')
            ->where('secondary.summary.net_spending_minor', '1500')
            ->missing('recent_transactions')
            ->missing('review_queue')
            ->missing('gmail')
            ->missing('parser_profiles'));
});

test('Home uses the latest meaningful period and omits an empty USD card', function () {
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
