<?php

use App\Currency;
use App\Models\Category;
use App\Models\CategoryTarget;
use App\Models\CategoryTargetRevision;
use App\Models\DailyExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('a Category Target requires an established baseline and explicit owner approval', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-18 15:00:00 UTC'));
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);

    $payload = [
        'category_id' => $category->id,
        'amount_minor' => '10000',
        'currency' => Currency::Pen->value,
        'starts_on' => '2026-09-01',
    ];

    $this->actingAs($owner)
        ->post(route('category_targets.store'), $payload)
        ->assertSessionHasErrors('category_id');

    $this->assertDatabaseCount('category_targets', 0);

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
            ->where('target_options.0.category.id', $category->id)
            ->where('target_options.0.category.name', 'Groceries')
            ->where('target_options.0.baseline_prefill.currency', Currency::Pen->value)
            ->where('target_options.0.baseline_prefill.amount_minor', '12000')
            ->has('category_targets', 0));

    $this->assertDatabaseCount('category_targets', 0);

    $this->actingAs($owner)
        ->post(route('category_targets.store'), $payload)
        ->assertRedirect();

    $this->assertDatabaseHas('category_targets', [
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'currency' => Currency::Pen->value,
        'starts_on' => '2026-09-01',
        'revision' => 1,
    ]);
    $this->assertDatabaseHas('category_target_revisions', [
        'revision' => 1,
        'effective_month' => '2026-09-01',
        'amount_minor' => 10_000,
    ]);

    $this->actingAs($owner)
        ->get(route('insights.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('target_options', 0)
            ->has('category_targets', 1)
            ->where('category_targets.0.category.id', $category->id)
            ->where('category_targets.0.status', 'scheduled')
            ->where('category_targets.0.amount_minor', '10000')
            ->where('category_targets.0.effective_month', '2026-09-01')
            ->where('category_targets.0.progress', null));

    $this->actingAs($owner)
        ->post(route('category_targets.store'), $payload)
        ->assertSessionHasErrors('category_id');

    $this->assertDatabaseCount('category_targets', 1);
});

test('parent and child Targets are independent and Refunds reduce progress in the approved currency', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-18 15:00:00 UTC'));
    $owner = User::factory()->create(['reporting_currency' => Currency::Usd]);
    $parent = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $child = Category::factory()->for($owner, 'owner')->for($parent, 'parent')->create(['name' => 'Dining']);
    $zeroCategory = Category::factory()->for($owner, 'owner')->create(['name' => 'Fun']);

    $parentTarget = CategoryTarget::factory()
        ->for($owner, 'owner')
        ->for($parent)
        ->create(['currency' => Currency::Pen, 'starts_on' => '2026-08-01']);
    CategoryTargetRevision::factory()->for($parentTarget)->create([
        'effective_month' => '2026-08-01',
        'amount_minor' => 30_000,
    ]);

    $childTarget = CategoryTarget::factory()
        ->for($owner, 'owner')
        ->for($child)
        ->create(['currency' => Currency::Pen, 'starts_on' => '2026-08-01']);
    CategoryTargetRevision::factory()->for($childTarget)->create([
        'effective_month' => '2026-08-01',
        'amount_minor' => 10_000,
    ]);

    $zeroTarget = CategoryTarget::factory()
        ->for($owner, 'owner')
        ->for($zeroCategory)
        ->create(['currency' => Currency::Pen, 'starts_on' => '2026-08-01']);
    CategoryTargetRevision::factory()->for($zeroTarget)->create([
        'effective_month' => '2026-08-01',
        'amount_minor' => 0,
    ]);

    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-02',
        'amount_minor' => 12_000,
        'category_id' => $child->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->refund()->pen()->create([
        'occurred_on' => '2026-08-03',
        'amount_minor' => 3_000,
        'category_id' => $child->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-04',
        'amount_minor' => 10_000,
        'category_id' => $parent->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-05',
        'amount_minor' => 500,
        'category_id' => $zeroCategory->id,
    ]);

    $this->actingAs($owner)
        ->get(route('insights.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('category_targets', 3)
            ->where('category_targets.0.category.name', 'Dining')
            ->where('category_targets.0.currency', Currency::Pen->value)
            ->where('category_targets.0.amount_minor', '10000')
            ->where('category_targets.0.progress.spent_minor', '9000')
            ->where('category_targets.0.progress.remaining_minor', '1000')
            ->where('category_targets.0.progress.percentage_basis_points', '9000')
            ->where('category_targets.0.progress.state', 'remaining')
            ->where('category_targets.0.progress.period_status', 'to_date')
            ->where('category_targets.1.category.name', 'Food')
            ->where('category_targets.1.progress.spent_minor', '19000')
            ->where('category_targets.1.progress.remaining_minor', '11000')
            ->where('category_targets.1.progress.percentage_basis_points', '6333')
            ->where('category_targets.2.category.name', 'Fun')
            ->where('category_targets.2.amount_minor', '0')
            ->where('category_targets.2.progress.spent_minor', '500')
            ->where('category_targets.2.progress.remaining_minor', '-500')
            ->where('category_targets.2.progress.percentage_basis_points', null)
            ->where('category_targets.2.progress.state', 'exceeded'));
});

test('revisions and retirement are effective dated without rewriting completed results', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 15:00:00 UTC'));
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $target = CategoryTarget::factory()
        ->for($owner, 'owner')
        ->for($category)
        ->create([
            'currency' => Currency::Pen,
            'starts_on' => '2026-07-01',
            'revision' => 1,
        ]);
    CategoryTargetRevision::factory()->for($target)->create([
        'revision' => 1,
        'effective_month' => '2026-07-01',
        'amount_minor' => 10_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-07-10',
        'amount_minor' => 8_000,
        'category_id' => $category->id,
    ]);

    $this->actingAs($owner)
        ->put(route('category_targets.update', $target), [
            'amount_minor' => '12000',
            'effective_month' => '2026-09-01',
            'expected_revision' => 1,
        ])
        ->assertSessionHasErrors('effective_month');

    $this->actingAs($owner)
        ->put(route('category_targets.update', $target), [
            'amount_minor' => '12000',
            'effective_month' => '2026-10-01',
            'expected_revision' => 1,
        ])
        ->assertRedirect();

    $this->actingAs($owner)
        ->put(route('category_targets.update', $target), [
            'amount_minor' => '13000',
            'effective_month' => '2026-11-01',
            'expected_revision' => 1,
        ])
        ->assertSessionHasErrors('expected_revision');

    $this->actingAs($owner)
        ->post(route('category_targets.retirement.store', $target), [
            'effective_month' => '2026-11-01',
            'expected_revision' => 2,
        ])
        ->assertRedirect();

    expect($target->refresh())
        ->revision->toBe(3)
        ->currency->toBe(Currency::Pen)
        ->and($target->revisions()->orderBy('revision')->pluck('amount_minor')->all())
        ->toBe([10_000, 12_000, null]);

    $this->actingAs($owner)
        ->get(route('insights.index', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('category_targets.0.amount_minor', '10000')
            ->where('category_targets.0.progress.spent_minor', '8000')
            ->where('category_targets.0.progress.period_status', 'completed'));

    $this->actingAs($owner)
        ->get(route('insights.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('category_targets.0.amount_minor', '12000')
            ->where('category_targets.0.effective_month', '2026-10-01')
            ->where('category_targets.0.status', 'active'));

    $this->travelTo(CarbonImmutable::parse('2026-11-18 15:00:00 UTC'));

    $this->actingAs($owner)
        ->get(route('insights.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('category_targets.0.status', 'retired')
            ->where('category_targets.0.effective_month', '2026-11-01')
            ->where('category_targets.0.amount_minor', null)
            ->where('category_targets.0.progress', null));
});

test('guests cannot create revise or retire Category Targets', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $target = CategoryTarget::factory()->for($owner, 'owner')->for($category)->create();
    $effectiveMonth = CarbonImmutable::today()->startOfMonth()->toDateString();

    $this->post(route('category_targets.store'), [
        'category_id' => $category->id,
        'amount_minor' => '1000',
        'currency' => Currency::Pen->value,
        'starts_on' => $effectiveMonth,
    ])->assertRedirect(route('login'));

    $this->put(route('category_targets.update', $target), [
        'amount_minor' => '1000',
        'effective_month' => $effectiveMonth,
        'expected_revision' => 1,
    ])
        ->assertRedirect(route('login'));

    $this->post(route('category_targets.retirement.store', $target), [
        'effective_month' => $effectiveMonth,
        'expected_revision' => 1,
    ])->assertRedirect(route('login'));
});

test('fixed-currency progress is unavailable rather than guessed when an exchange rate is missing', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-18 15:00:00 UTC'));
    $owner = User::factory()->create(['reporting_currency' => Currency::Pen]);
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Travel']);
    $target = CategoryTarget::factory()
        ->for($owner, 'owner')
        ->for($category)
        ->create(['currency' => Currency::Usd, 'starts_on' => '2026-08-01']);
    CategoryTargetRevision::factory()->for($target)->create([
        'effective_month' => '2026-08-01',
        'amount_minor' => 5_000,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'occurred_on' => '2026-08-10',
        'amount_minor' => 10_000,
        'category_id' => $category->id,
    ]);

    $this->actingAs($owner)
        ->get(route('insights.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('category_targets.0.currency', Currency::Usd->value)
            ->where('category_targets.0.progress.spent_minor', null)
            ->where('category_targets.0.progress.state', 'unavailable')
            ->where('category_targets.0.progress.unavailable_reason', 'missing_exchange_rates')
            ->where('category_targets.0.progress.missing_rate_dates', ['2026-08-10']));

    DailyExchangeRate::factory()->for($owner, 'owner')->create([
        'applicable_on' => '2026-08-10',
        'pen_per_usd_scaled' => 4_000_000,
    ]);

    $this->actingAs($owner)
        ->get(route('insights.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('category_targets.0.progress.spent_minor', '2500')
            ->where('category_targets.0.progress.remaining_minor', '2500')
            ->where('category_targets.0.progress.percentage_basis_points', '5000'));
});
