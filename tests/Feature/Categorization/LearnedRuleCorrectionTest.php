<?php

use App\Http\Middleware\RequirePasskeyConfirmation;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\LearnedRuleSuggestion;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

test('an owner Category Correction prepares an exact narrow rule without activating it', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Cafes']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Café—CENTRAL, Lima!',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.category.update', $transaction), [
            'expected_revision' => 1,
            'category_id' => $category->id,
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.learned_rule_candidate', [
                'transaction_id' => $transaction->id,
                'transaction_revision' => 2,
                'category_id' => $category->id,
                'category_name' => 'Cafes',
                'merchant_pattern' => 'Café—CENTRAL, Lima!',
                'merchant_key' => 'café central lima',
                'match_mode' => 'exact',
                'transaction_kind' => 'purchase',
                'currency' => 'PEN',
                'payment_instrument_label' => null,
                'payment_instrument_last_four' => null,
            ]));

    expect(LearnedRule::query()->count())->toBe(0);
});

test('the owner explicitly activates the exact narrow rule prepared from a Correction', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Mercado San José #42',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.category.update', $transaction), [
            'expected_revision' => 1,
            'category_id' => $category->id,
        ])
        ->assertSessionHasNoErrors();

    $sourceAssignment = $transaction->categoryAssignments()->sole();

    $this->post(route('learned_rules.store'), [
        'transaction_id' => $transaction->id,
        'expected_revision' => 2,
    ])->assertSessionHasNoErrors();

    $rule = LearnedRule::query()->with('revisions')->sole();
    $revision = $rule->revisions->sole();

    expect($rule)
        ->user_id->toBe($owner->id)
        ->revision->toBe(1)
        ->activated_at->not->toBeNull()
        ->retired_at->toBeNull()
        ->and($revision)
        ->category_id->toBe($category->id)
        ->merchant_pattern->toBe('Mercado San José #42')
        ->merchant_key->toBe('mercado san josé 42')
        ->match_mode->value->toBe('exact')
        ->transaction_kind->value->toBe('purchase')
        ->currency->value->toBe('PEN')
        ->payment_instrument_label->toBeNull()
        ->payment_instrument_last_four->toBeNull()
        ->source_category_assignment_id->toBe($sourceAssignment->id);
});

test('two separate consistent Corrections surface one scoped suggestion', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $firstTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Mercado—Central',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);
    $secondTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => '  MERCADO, central  ',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.category.update', $firstTransaction), [
            'expected_revision' => 1,
            'category_id' => $category->id,
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('learned_rules.index'))
        ->assertInertia(fn (Assert $page) => $page->has('suggestions', 0));

    $this->put(route('transactions.category.update', $secondTransaction), [
        'expected_revision' => 1,
        'category_id' => $category->id,
    ])->assertSessionHasNoErrors();

    $this->get(route('learned_rules.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('suggestions', 1, fn (Assert $suggestion) => $suggestion
                ->where('category_id', $category->id)
                ->where('category_name', 'Groceries')
                ->where('merchant_pattern', 'Mercado—Central')
                ->where('merchant_key', 'mercado central')
                ->where('match_mode', 'exact')
                ->where('transaction_kind', 'purchase')
                ->where('currency', 'PEN')
                ->where('payment_instrument_label', null)
                ->where('payment_instrument_last_four', null)
                ->where('evidence_count', 2)
                ->etc())
            ->has('rules', 0));

    expect(LearnedRule::query()->count())->toBe(0);
});

test('a dismissed suggestion stays suppressed until its target changes materially', function () {
    $owner = User::factory()->create();
    $groceries = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $restaurants = Category::factory()->for($owner, 'owner')->create(['name' => 'Restaurants']);

    $assign = function (Category $category) use ($owner): void {
        $transaction = Transaction::factory()->for($owner, 'owner')->create([
            'merchant_description' => 'Mercado Central',
            'kind' => 'purchase',
            'currency' => 'PEN',
        ]);

        $this->actingAs($owner)
            ->put(route('transactions.category.update', $transaction), [
                'expected_revision' => 1,
                'category_id' => $category->id,
            ])
            ->assertSessionHasNoErrors();
    };

    $assign($groceries);
    $assign($groceries);

    $suggestion = LearnedRuleSuggestion::query()->sole();

    $this->delete(route('learned_rule_suggestions.destroy', $suggestion))
        ->assertSessionHasNoErrors();

    $assign($groceries);

    $this->get(route('learned_rules.index'))
        ->assertInertia(fn (Assert $page) => $page->has('suggestions', 0));

    $assign($restaurants);
    $assign($restaurants);

    $this->get(route('learned_rules.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('suggestions', 1, fn (Assert $newSuggestion) => $newSuggestion
                ->where('category_id', $restaurants->id)
                ->where('merchant_key', 'mercado central')
                ->where('evidence_count', 2)
                ->etc()));

    expect($suggestion->fresh()->status->value)->toBe('dismissed');
});

test('accepting a suggestion explicitly activates its visible deterministic rule', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Transport']);

    foreach (['Taxi Satelital', 'TAXI—SATELITAL'] as $merchant) {
        $transaction = Transaction::factory()->for($owner, 'owner')->create([
            'merchant_description' => $merchant,
            'kind' => 'purchase',
            'currency' => 'USD',
        ]);

        $this->actingAs($owner)
            ->put(route('transactions.category.update', $transaction), [
                'expected_revision' => 1,
                'category_id' => $category->id,
            ])
            ->assertSessionHasNoErrors();
    }

    $suggestion = LearnedRuleSuggestion::query()->sole();

    $this->post(route('learned_rule_suggestions.acceptance.store', $suggestion))
        ->assertSessionHasNoErrors();

    $this->get(route('learned_rules.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('suggestions', 0)
            ->has('rules', 1, fn (Assert $rule) => $rule
                ->where('category_id', $category->id)
                ->where('category_name', 'Transport')
                ->where('merchant_pattern', 'Taxi Satelital')
                ->where('merchant_key', 'taxi satelital')
                ->where('match_mode', 'exact')
                ->where('transaction_kind', 'purchase')
                ->where('currency', 'USD')
                ->where('payment_instrument_label', null)
                ->where('payment_instrument_last_four', null)
                ->where('revision', 1)
                ->etc()));

    expect(LearnedRule::query()->count())->toBe(1)
        ->and($suggestion->fresh()->status->value)->toBe('accepted')
        ->and($suggestion->fresh()->accepted_rule_id)->toBe(LearnedRule::query()->sole()->id);
});

test('the suggestion threshold is met independently inside each optional scope', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();

    foreach ([
        ['purchase', 'PEN'],
        ['refund', 'PEN'],
        ['purchase', 'USD'],
    ] as [$kind, $currency]) {
        $transaction = Transaction::factory()->for($owner, 'owner')->create([
            'merchant_description' => 'Scoped Merchant',
            'kind' => $kind,
            'currency' => $currency,
        ]);

        $this->actingAs($owner)->put(route('transactions.category.update', $transaction), [
            'expected_revision' => 1,
            'category_id' => $category->id,
        ])->assertSessionHasNoErrors();
    }

    $this->get(route('learned_rules.index'))
        ->assertInertia(fn (Assert $page) => $page->has('suggestions', 0));

    $matchingTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'SCOPED—MERCHANT',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);

    $this->put(route('transactions.category.update', $matchingTransaction), [
        'expected_revision' => 1,
        'category_id' => $category->id,
    ])->assertSessionHasNoErrors();

    $this->get(route('learned_rules.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('suggestions', 1, fn (Assert $suggestion) => $suggestion
                ->where('transaction_kind', 'purchase')
                ->where('currency', 'PEN')
                ->where('evidence_count', 2)
                ->etc()));
});

test('Correction activation rejects caller-defined and prohibited matching inputs', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)->put(route('transactions.category.update', $transaction), [
        'expected_revision' => 1,
        'category_id' => $category->id,
    ])->assertSessionHasNoErrors();

    $prohibitedInput = [
        'merchant_pattern' => '.*',
        'merchant_key' => 'opaque-key',
        'match_mode' => 'contains',
        'transaction_kind' => 'refund',
        'currency' => 'USD',
        'payment_instrument_label' => 'Card',
        'payment_instrument_last_four' => '1234',
        'occurred_on' => '2026-07-27',
        'date' => '2026-07-27',
        'amount' => '10.00',
        'amount_minor' => 1000,
        'institution_reference' => 'bank-42',
        'regex' => true,
        'fuzzy' => true,
        'similarity' => 0.9,
    ];

    $this->post(route('learned_rules.store'), [
        'transaction_id' => $transaction->id,
        'expected_revision' => 2,
        ...$prohibitedInput,
    ])->assertSessionHasErrors(array_keys($prohibitedInput));

    expect(LearnedRule::query()->count())->toBe(0);
});

test('visible Learned Rules represent only the decided modes and optional scopes', function (
    string $matchMode,
    ?string $kind,
    ?string $currency,
    ?string $paymentInstrumentLabel,
    ?string $paymentInstrumentLastFour,
) {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Visible target']);
    $rule = LearnedRule::factory()->for($owner, 'owner')->create();

    $rule->revisions()->create([
        'revision' => 1,
        'category_id' => $category->id,
        'merchant_pattern' => 'Visible Merchant',
        'merchant_key' => 'visible merchant',
        'match_mode' => $matchMode,
        'transaction_kind' => $kind,
        'currency' => $currency,
        'payment_instrument_label' => $paymentInstrumentLabel,
        'payment_instrument_last_four' => $paymentInstrumentLastFour,
    ]);

    $this->actingAs($owner)
        ->get(route('learned_rules.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rules', 1, fn (Assert $visibleRule) => $visibleRule
                ->where('match_mode', $matchMode)
                ->where('transaction_kind', $kind)
                ->where('currency', $currency)
                ->where('payment_instrument_label', $paymentInstrumentLabel)
                ->where('payment_instrument_last_four', $paymentInstrumentLastFour)
                ->etc()));
})->with([
    'exact with no optional scope' => ['exact', null, null, null, null],
    'starts with kind scope' => ['starts_with', 'refund', null, null, null],
    'contains with currency and payment instrument scopes' => ['contains', null, 'USD', 'Visa', '1234'],
]);

test('an active Learned Rule blocks retirement and its revision blocks deletion of the target Category', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $rule = LearnedRule::factory()->for($owner, 'owner')->create();
    $rule->revisions()->create([
        'revision' => 1,
        'category_id' => $category->id,
        'merchant_pattern' => 'Protected Merchant',
        'merchant_key' => 'protected merchant',
        'match_mode' => 'exact',
    ]);

    $this->actingAs($owner)
        ->post(route('categories.retirement.store', $category), ['expected_revision' => 1])
        ->assertSessionHasErrors('category');

    $this->withSession([RequirePasskeyConfirmation::SESSION_KEY => Date::now()->unix()])
        ->delete(route('categories.destroy', $category), ['expected_revision' => 1])
        ->assertSessionHasErrors('category');

    expect($category->fresh()->retired_at)->toBeNull();
});

test('rule activation rejects a stale candidate instead of activating changed conditions', function () {
    $owner = User::factory()->create();
    $firstCategory = Category::factory()->for($owner, 'owner')->create();
    $secondCategory = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)->put(route('transactions.category.update', $transaction), [
        'expected_revision' => 1,
        'category_id' => $firstCategory->id,
    ])->assertSessionHasNoErrors();

    $this->put(route('transactions.category.update', $transaction), [
        'expected_revision' => 2,
        'category_id' => $secondCategory->id,
    ])->assertSessionHasNoErrors();

    $this->post(route('learned_rules.store'), [
        'transaction_id' => $transaction->id,
        'expected_revision' => 2,
    ])->assertSessionHasErrors('expected_revision');

    expect(LearnedRule::query()->count())->toBe(0);
});

test('superseded Corrections stop contributing to a suggestion threshold', function () {
    $owner = User::factory()->create();
    $firstCategory = Category::factory()->for($owner, 'owner')->create();
    $secondCategory = Category::factory()->for($owner, 'owner')->create();
    $firstTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Changing Merchant',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);
    $secondTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'CHANGING—MERCHANT',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);

    foreach ([$firstTransaction, $secondTransaction] as $transaction) {
        $this->actingAs($owner)->put(route('transactions.category.update', $transaction), [
            'expected_revision' => 1,
            'category_id' => $firstCategory->id,
        ])->assertSessionHasNoErrors();
    }

    $this->put(route('transactions.category.update', $secondTransaction), [
        'expected_revision' => 2,
        'category_id' => $secondCategory->id,
    ])->assertSessionHasNoErrors();

    $this->get(route('learned_rules.index'))
        ->assertInertia(fn (Assert $page) => $page->has('suggestions', 0));

    $firstSuggestion = LearnedRuleSuggestion::query()
        ->where('category_id', $firstCategory->id)
        ->sole();

    expect($firstSuggestion->evidence_count)->toBe(1)
        ->and($firstSuggestion->status->value)->toBe('collecting');
});

test('a merchant without searchable text does not prevent its Category Correction', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => '---',
    ]);

    $this->actingAs($owner)
        ->put(route('transactions.category.update', $transaction), [
            'expected_revision' => 1,
            'category_id' => $category->id,
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.category.id', $category->id)
            ->where('selected_transaction.learned_rule_candidate', null));

    expect(LearnedRuleSuggestion::query()->count())->toBe(0);
});

test('direct activation resolves an equivalent pending suggestion', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transactions = Transaction::factory()->count(2)->for($owner, 'owner')->create([
        'merchant_description' => 'Resolved Suggestion',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);

    foreach ($transactions as $transaction) {
        $this->actingAs($owner)->put(route('transactions.category.update', $transaction), [
            'expected_revision' => 1,
            'category_id' => $category->id,
        ])->assertSessionHasNoErrors();
    }

    $this->post(route('learned_rules.store'), [
        'transaction_id' => $transactions->first()->id,
        'expected_revision' => 2,
    ])->assertSessionHasNoErrors();

    $suggestion = LearnedRuleSuggestion::query()->sole();

    expect($suggestion->status->value)->toBe('accepted')
        ->and($suggestion->accepted_rule_id)->toBe(LearnedRule::query()->sole()->id);
});

test('material merchant changes move Correction evidence to a new suggestion definition', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transactions = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create([
            'merchant_description' => 'Dismissed Merchant',
            'kind' => 'purchase',
            'currency' => 'PEN',
        ]);

    foreach ($transactions as $transaction) {
        $this->actingAs($owner)->put(route('transactions.category.update', $transaction), [
            'expected_revision' => 1,
            'category_id' => $category->id,
        ])->assertSessionHasNoErrors();
    }

    $dismissedSuggestion = LearnedRuleSuggestion::query()->sole();
    $this->delete(route('learned_rule_suggestions.destroy', $dismissedSuggestion))
        ->assertSessionHasNoErrors();

    foreach ($transactions as $transaction) {
        $this->patch(route('review_queue.fields.update', [
            'transaction' => $transaction,
            'field' => ReviewableTransactionField::MerchantDescription,
        ]), [
            'expected_revision' => 2,
            'resolution' => 'correct',
            'value' => 'New Merchant',
        ])->assertSessionHasNoErrors();
    }

    $this->get(route('learned_rules.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('suggestions', 1, fn (Assert $suggestion) => $suggestion
                ->where('merchant_key', 'new merchant')
                ->where('evidence_count', 2)
                ->etc()));

    expect($dismissedSuggestion->fresh()->status->value)->toBe('dismissed');
});

test('an unchanged Category approval is not treated as a Correction rule candidate', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $category->id,
        'category_assignment_provenance' => 'ai',
    ]);

    $this->actingAs($owner)->put(route('transactions.category.update', $transaction), [
        'expected_revision' => 1,
        'category_id' => $category->id,
    ])->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.category.id', $category->id)
            ->where('selected_transaction.learned_rule_candidate', null));

    expect($transaction->categoryAssignments()->sole()->is_correction)->toBeFalse()
        ->and(LearnedRuleSuggestion::query()->count())->toBe(0);
});

test('merchant normalization preserves meaningful symbols while standardizing punctuation', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'C++ Academy—Lima',
    ]);

    $this->actingAs($owner)->put(route('transactions.category.update', $transaction), [
        'expected_revision' => 1,
        'category_id' => $category->id,
    ])->assertSessionHasNoErrors();

    $this->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.learned_rule_candidate.merchant_pattern', 'C++ Academy—Lima')
            ->where('selected_transaction.learned_rule_candidate.merchant_key', 'c++ academy lima'));
});
