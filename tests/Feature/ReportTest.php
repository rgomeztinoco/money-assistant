<?php

use App\Currency;
use App\IntegrationService;
use App\IntegrationWorkType;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
            ->where('monthly_history.0.total_minor', '5000')
            ->missing('combined_total')
            ->missing('totals')
            ->missing('reporting_currency')
            ->missing('exchange_rates'));

    expect(json_encode($penResponse->inertiaProps(), JSON_THROW_ON_ERROR))
        ->not->toContain('USD')
        ->not->toContain('combined_total');

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
        ->not->toContain('PEN')
        ->not->toContain('combined_total');
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

test('report filters are validated and removed Insight and Category Target routes stay absent', function (array $query, string $field) {
    $this->actingAs(User::factory()->create())
        ->get(route('reports.show', ['currency' => Currency::Pen, ...$query]))
        ->assertSessionHasErrors($field);

    expect(Route::has('insights.index'))->toBeFalse()
        ->and(Route::has('category_targets.store'))->toBeFalse()
        ->and(Route::has('category_targets.update'))->toBeFalse()
        ->and(Route::has('category_targets.retirement.store'))->toBeFalse()
        ->and(Schema::hasTable('category_targets'))->toBeFalse()
        ->and(Schema::hasTable('category_target_revisions'))->toBeFalse();
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

test('exchange-rate acquisition and Reporting Currency contracts are absent', function () {
    Artisan::call('schedule:list');

    expect(Route::has('daily_exchange_rates.index'))->toBeFalse()
        ->and(Route::has('daily_exchange_rates.store'))->toBeFalse()
        ->and(Route::has('daily_exchange_rates.update'))->toBeFalse()
        ->and(Route::has('daily_exchange_rates.retry_seed'))->toBeFalse()
        ->and(Route::has('reporting_currency.update'))->toBeFalse()
        ->and(Schema::hasTable('daily_exchange_rates'))->toBeFalse()
        ->and(Schema::hasTable('daily_exchange_rate_seed_requests'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'reporting_currency'))->toBeFalse()
        ->and(collect(IntegrationService::cases())->pluck('value')->all())
        ->not->toContain('bcrp')
        ->and(collect(IntegrationWorkType::cases())->pluck('value')->all())
        ->not->toContain('daily_exchange_rate_seed')
        ->and(Artisan::output())->not->toContain('daily-exchange-rate');
});
