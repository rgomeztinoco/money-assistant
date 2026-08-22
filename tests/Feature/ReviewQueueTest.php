<?php

use App\Actions\Categorization\CategorizeReviewTransaction;
use App\Actions\Ledger\CountOutstandingReviews;
use App\Actions\Ledger\RecordManualTransaction;
use App\CategoryAssignmentProvenance;
use App\Currency;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\MerchantRule;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use App\ReviewableTransactionField;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('the Review Queue presents an ordered sequence of Transaction and Line Item decisions', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $flaggedTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create([
            'occurred_on' => '2026-08-20',
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $receiptTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-08-19',
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $receiptBreakdown = ReceiptBreakdown::factory()->for($receiptTransaction)->create();
    $lineItem = LineItem::factory()->for($receiptBreakdown)->create([
        'description' => 'Uncategorized apples',
        'line_total_minor' => 2_500,
    ]);

    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('review-queue/index')
            ->where('queue.unresolved_count', 2)
            ->where('queue.item_count', 2)
            ->where('queue.current_item_key', "transaction:{$flaggedTransaction->id}")
            ->where('queue.current_position', 1)
            ->where('queue.items.0.type', 'transaction')
            ->where('queue.items.0.transaction.id', $flaggedTransaction->id)
            ->where('queue.items.0.reasons.0.type', 'field')
            ->where('queue.items.0.reasons.0.field.name', 'merchant_description')
            ->where('queue.items.1.type', 'line_item')
            ->where('queue.items.1.line_item.id', $lineItem->id)
            ->where('queue.items.1.reasons.0.type', 'category'));
});

test('assigning a Transaction Category saves and advances to the next Review Queue item', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $current = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-08-20',
        'merchant_description' => 'Current merchant',
    ]);
    $next = Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-08-19',
        'merchant_description' => 'Next merchant',
    ]);

    $this->actingAs($owner)
        ->from(route('review_queue.index', ['item' => "transaction:{$current->id}"]))
        ->put(route('review_queue.transactions.category.update', $current), [
            'category_id' => $category->id,
            'create_merchant_rule' => false,
            'bulk_assign' => false,
            'next_review_item' => "transaction:{$next->id}",
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('review_queue.index', ['item' => "transaction:{$next->id}"]));

    expect($current->fresh())
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->merchant_rule_id->toBeNull()
        ->and($next->fresh()->category_id)->toBeNull()
        ->and(MerchantRule::query()->count())->toBe(0);
});

test('plain Transaction categorization does not require a normalizable merchant', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => '!!!',
    ]);

    $this->actingAs($owner)
        ->put(route('review_queue.transactions.category.update', $transaction), [
            'category_id' => $category->id,
            'create_merchant_rule' => false,
            'bulk_assign' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh())
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner);
});

test('the owner can independently create a future Merchant Rule and bulk assign matching current Uncategorized Transactions', function (
    bool $createMerchantRule,
    bool $bulkAssign,
    int $expectedRuleCount,
    bool $expectMatchingAssignment,
) {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Coffee']);
    $existingCategory = Category::factory()->for($owner, 'owner')->create(['name' => 'Owner choice']);
    $current = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'merchant_description' => 'CAFÉ—Central!!!',
    ]);
    $matching = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'merchant_description' => " cafe\u{0301} central ",
    ]);
    $ownerAssigned = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'merchant_description' => 'Café Central',
        'category_id' => $existingCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $nonMatching = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'merchant_description' => 'Different merchant',
    ]);
    $unnormalizable = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'merchant_description' => '!!!',
    ]);

    $this->actingAs($owner)
        ->get(route('review_queue.index', ['item' => "transaction:{$current->id}"]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('queue.current_item_key', "transaction:{$current->id}")
            ->where('queue.items', fn ($items): bool => $items
                ->firstWhere('key', "transaction:{$current->id}")['merchant_context']['matching_uncategorized_count'] === 2));

    $this->put(route('review_queue.transactions.category.update', $current), [
        'category_id' => $category->id,
        'create_merchant_rule' => $createMerchantRule,
        'bulk_assign' => $bulkAssign,
        'rule_transaction_kind' => 'spending',
        'rule_currency' => 'PEN',
    ])->assertSessionHasNoErrors();

    expect($current->fresh())
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->and($matching->fresh()->category_id)->toBe($expectMatchingAssignment ? $category->id : null)
        ->and($ownerAssigned->fresh()->category_id)->toBe($existingCategory->id)
        ->and($ownerAssigned->fresh()->category_assignment_provenance)->toBe(CategoryAssignmentProvenance::Owner)
        ->and($nonMatching->fresh()->category_id)->toBeNull()
        ->and($unnormalizable->fresh()->category_id)->toBeNull()
        ->and(MerchantRule::query()->count())->toBe($expectedRuleCount);

    if ($createMerchantRule) {
        expect(MerchantRule::query()->sole())
            ->merchant->toBe('CAFÉ—Central!!!')
            ->merchant_key->toBe('café central')
            ->category_id->toBe($category->id)
            ->transaction_kind->toBe(TransactionKind::Spending)
            ->currency->toBe(Currency::Pen)
            ->enabled->toBeTrue();
    }
})->with([
    'future rule only' => [true, false, 1, false],
    'current bulk assignment only' => [false, true, 0, true],
    'future rule and current bulk assignment' => [true, true, 1, true],
]);

test('future Merchant Rule creation rejects an existing exact disabled scope without changing current work', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'merchant_description' => 'Scoped merchant',
    ]);
    MerchantRule::factory()->for($owner, 'owner')->for($category)->disabled()->create([
        'merchant' => 'Scoped merchant',
        'merchant_key' => 'scoped merchant',
        'transaction_kind' => TransactionKind::Spending,
        'currency' => Currency::Pen,
    ]);

    $this->actingAs($owner)
        ->put(route('review_queue.transactions.category.update', $transaction), [
            'category_id' => $category->id,
            'create_merchant_rule' => true,
            'bulk_assign' => false,
            'rule_transaction_kind' => 'spending',
            'rule_currency' => 'PEN',
        ])
        ->assertSessionHasErrors('create_merchant_rule');

    expect($transaction->fresh()->category_id)->toBeNull()
        ->and(MerchantRule::query()->count())->toBe(1);
});

test('categorization rolls back started bulk assignments when Merchant Rule creation fails', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $current = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'merchant_description' => 'Rollback merchant',
    ]);
    $matching = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'merchant_description' => 'rollback merchant',
    ]);
    MerchantRule::factory()->for($owner, 'owner')->for($category)->disabled()->create([
        'merchant' => 'Rollback merchant',
        'merchant_key' => 'rollback merchant',
        'transaction_kind' => TransactionKind::Spending,
        'currency' => Currency::Pen,
    ]);

    expect(fn () => app(CategorizeReviewTransaction::class)->handle(
        owner: $owner,
        transaction: $current,
        categoryId: $category->id,
        createMerchantRule: true,
        bulkAssign: true,
        ruleTransactionKind: TransactionKind::Spending,
        ruleCurrency: Currency::Pen,
    ))->toThrow(QueryException::class);

    expect($current->fresh()->category_id)->toBeNull()
        ->and($matching->fresh()->category_id)->toBeNull()
        ->and(MerchantRule::query()->count())->toBe(1);
});

test('Review Queue categorization validates ownership and leaves all Transactions unchanged on failure', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Atomic merchant',
    ]);
    $matching = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Atomic merchant',
    ]);
    $otherCategory = Category::factory()->for($otherOwner, 'owner')->create();

    $this->actingAs($owner)
        ->put(route('review_queue.transactions.category.update', $transaction), [
            'category_id' => $otherCategory->id,
            'create_merchant_rule' => true,
            'bulk_assign' => true,
            'rule_transaction_kind' => null,
            'rule_currency' => null,
        ])
        ->assertSessionHasErrors('category_id');

    expect($transaction->fresh()->category_id)->toBeNull()
        ->and($matching->fresh()->category_id)->toBeNull()
        ->and(MerchantRule::query()->count())->toBe(0);

    $this->actingAs($otherOwner)
        ->put(route('review_queue.transactions.category.update', $transaction), [
            'category_id' => $otherCategory->id,
            'create_merchant_rule' => false,
            'bulk_assign' => false,
        ])
        ->assertForbidden();
});

test('assigning a Line Item Category saves and advances to the next Review Queue item', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $receiptBreakdown = ReceiptBreakdown::factory()->for($transaction)->create();
    $lineItem = LineItem::factory()->for($receiptBreakdown)->create();
    $next = Transaction::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)
        ->put(route('review_queue.line_items.category.update', $lineItem), [
            'category_id' => $category->id,
            'next_review_item' => "transaction:{$next->id}",
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('review_queue.index', ['item' => "transaction:{$next->id}"]));

    expect($lineItem->fresh()->category_id)->toBe($category->id);
});

test('Line Item categorization validates Category ownership and Line Item ownership', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $receiptBreakdown = ReceiptBreakdown::factory()->for($transaction)->create();
    $lineItem = LineItem::factory()->for($receiptBreakdown)->create();
    $otherCategory = Category::factory()->for($otherOwner, 'owner')->create();

    $this->actingAs($owner)
        ->put(route('review_queue.line_items.category.update', $lineItem), [
            'category_id' => $otherCategory->id,
        ])
        ->assertSessionHasErrors('category_id');

    expect($lineItem->fresh()->category_id)->toBeNull();

    $this->actingAs($otherOwner)
        ->put(route('review_queue.line_items.category.update', $lineItem), [
            'category_id' => $otherCategory->id,
        ])
        ->assertForbidden();
});

test('a confirmed Transaction with provisional fields remains in totals and appears once in the Review Queue', function () {
    $owner = User::factory()->create();
    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-22'),
        amountMinor: 12345,
        currency: Currency::Usd,
        kind: TransactionKind::Spending,
        merchantDescription: 'Neighborhood market',
        provisionalFields: [
            ReviewableTransactionField::OccurredOn,
            ReviewableTransactionField::MerchantDescription,
        ],
    );

    expect($transaction->confirmed_at)->not->toBeNull();

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('totals')
            ->where('transactions.0.id', $transaction->id));

    $this->get(route('review_queue.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('review-queue/index')
            ->where('unresolved_field_count', 2)
            ->has('transactions', 1)
            ->where('transactions.0.id', $transaction->id)
            ->where('transactions.0.confirmed_at', fn (mixed $confirmedAt) => is_string($confirmedAt))
            ->where('transactions.0.fields.0.name', 'occurred_on')
            ->where('transactions.0.fields.0.value', '2026-07-22')
            ->where('transactions.0.fields.1.name', 'merchant_description')
            ->where('transactions.0.fields.1.value', 'Neighborhood market'));
});

test('the shared navigation count uses the Review Queue workload breakdown', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    Transaction::factory()->for($owner, 'owner')->purchase()->provisional([
        ReviewableTransactionField::OccurredOn,
        ReviewableTransactionField::MerchantDescription,
    ])->create([
        'category_id' => $category->id,
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->create();

    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_field_count', 2)
            ->where('unresolved_category_count', 1)
            ->where('unresolved_refund_relationship_count', 0)
            ->where('navigation.review_queue_count', 3));
});

test('the Review Queue workload is aggregated once per request', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    Transaction::factory()->for($owner, 'owner')->purchase()->provisional([
        ReviewableTransactionField::OccurredOn,
    ])->create([
        'category_id' => $category->id,
    ]);

    $countOutstandingReviews = app(CountOutstandingReviews::class);

    DB::enableQueryLog();
    $breakdown = $countOutstandingReviews->breakdown($owner);
    $outstandingCount = app(CountOutstandingReviews::class)->handle($owner);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($breakdown)
        ->toBe([
            'categories' => 0,
            'fields' => 1,
            'refund_relationships' => 0,
        ])
        ->and($outstandingCount)->toBe(1)
        ->and($queries)->toHaveCount(2);
});

test('an Uncategorized Transaction remains in totals, reports in its system bucket, and enters the Review Queue', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $uncategorized = Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'amount_minor' => 10_000,
        'merchant_description' => 'Needs a Category',
    ]);
    Transaction::factory()->for($owner, 'owner')->purchase()->usd()->create([
        'amount_minor' => 5_000,
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('totals')
            ->missing('category_totals'));

    $report = $this->get(route('reports.show', [
        'currency' => Currency::Usd,
        'date_from' => '2000-01-01',
        'date_to' => now()->toDateString(),
    ]));
    $categoryTotals = collect($report->inertiaProps('category_groups'))
        ->flatMap(fn (array $group): array => [$group, ...$group['children']]);

    expect($categoryTotals->firstWhere('category.id', $category->id)['amount_minor'])
        ->toBe('5000')
        ->and($categoryTotals->firstWhere('category.id', null)['amount_minor'])
        ->toBe('10000');

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_category_count', 1)
            ->has('transactions', 1)
            ->where('transactions.0.id', $uncategorized->id)
            ->loadDeferredProps(fn (Assert $inspector) => $inspector
                ->where('selected_transaction.id', $uncategorized->id)));
});

test('the owner can accept one provisional field and replace another value', function () {
    $owner = User::factory()->create();
    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-22'),
        amountMinor: 12345,
        currency: Currency::Usd,
        kind: TransactionKind::Spending,
        merchantDescription: 'Neighborhood market',
        provisionalFields: [
            ReviewableTransactionField::MerchantDescription,
            ReviewableTransactionField::AmountMinor,
        ],
    );

    $this->actingAs($owner)
        ->patch(route('review_queue.fields.update', [
            'transaction' => $transaction,
            'field' => ReviewableTransactionField::MerchantDescription,
        ]), ['resolution' => 'accept'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('review_queue.index'));

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_field_count', 1)
            ->where('transactions.0.fields.0.name', 'amount_minor'));

    $this->patch(route('review_queue.fields.update', [
        'transaction' => $transaction,
        'field' => ReviewableTransactionField::AmountMinor,
    ]), [
        'resolution' => 'correct',
        'value' => '9000',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('review_queue.index'));

    expect($transaction->refresh())
        ->amount_minor->toBe(9000)
        ->provisional_fields->toBe([]);
});

test('resolving the last flagged Transaction field advances to the next Review Queue item', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $current = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create([
            'category_id' => $category->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $next = Transaction::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)
        ->patch(route('review_queue.fields.update', [
            'transaction' => $current,
            'field' => ReviewableTransactionField::MerchantDescription,
        ]), [
            'resolution' => 'accept',
            'next_review_item' => "transaction:{$next->id}",
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('review_queue.index', ['item' => "transaction:{$next->id}"]));

    expect($current->fresh()->provisional_fields)->toBe([]);
});

test('direct edits replace the current value for every reviewable Transaction field', function (
    ReviewableTransactionField $field,
    string $replacementValue,
    string $expectedValue,
) {
    $owner = User::factory()->create();
    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-22'),
        amountMinor: 12345,
        currency: Currency::Usd,
        kind: TransactionKind::Spending,
        merchantDescription: 'Provisional market',
        provisionalFields: [$field],
    );

    $this->actingAs($owner)
        ->patch(route('review_queue.fields.update', [
            'transaction' => $transaction,
            'field' => $field,
        ]), [
            'resolution' => 'correct',
            'value' => $replacementValue,
        ])
        ->assertSessionHasNoErrors();

    $transaction->refresh();

    expect($field->valueFor($transaction))->toBe($expectedValue)
        ->and($transaction->provisional_fields)->toBe([]);
})->with([
    'occurrence date' => [ReviewableTransactionField::OccurredOn, '2026-07-23', '2026-07-23'],
    'amount' => [ReviewableTransactionField::AmountMinor, '9000', '9000'],
    'currency' => [ReviewableTransactionField::Currency, 'PEN', 'PEN'],
    'kind' => [ReviewableTransactionField::Kind, 'refund', 'refund'],
    'merchant or description' => [ReviewableTransactionField::MerchantDescription, 'Neighborhood market', 'Neighborhood market'],
]);

test('Review Queue routes require an authenticated owner', function () {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create();

    $this->get(route('review_queue.index'))->assertRedirect(route('login'));
    $this->patch(route('review_queue.fields.update', [
        'transaction' => $transaction,
        'field' => ReviewableTransactionField::MerchantDescription,
    ]), ['resolution' => 'accept'])->assertRedirect(route('login'));
});

test('invalid field review input leaves the confirmed Transaction unchanged', function (
    ReviewableTransactionField $field,
    array $payload,
    string $invalidField,
) {
    $owner = User::factory()->create();
    $transaction = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([$field])
        ->create();

    $this->actingAs($owner)
        ->from(route('review_queue.index'))
        ->patch(route('review_queue.fields.update', [
            'transaction' => $transaction,
            'field' => $field,
        ]), [
            'resolution' => 'correct',
            'value' => 'Valid replacement',
            ...$payload,
        ])
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors($invalidField);

    expect($transaction->refresh()->provisional_fields)->toBe([$field->value]);
})->with([
    'missing corrected value' => [ReviewableTransactionField::MerchantDescription, ['value' => null], 'value'],
    'invalid occurrence date' => [ReviewableTransactionField::OccurredOn, ['value' => '2026-02-30'], 'value'],
    'fractional amount' => [ReviewableTransactionField::AmountMinor, ['value' => '1.5'], 'value'],
    'unsupported currency' => [ReviewableTransactionField::Currency, ['value' => 'EUR'], 'value'],
    'unsupported kind' => [ReviewableTransactionField::Kind, ['value' => 'unsupported'], 'value'],
    'kind requiring full movement details' => [ReviewableTransactionField::Kind, ['value' => 'transfer'], 'value'],
    'merchant above maximum length' => [ReviewableTransactionField::MerchantDescription, ['value' => str_repeat('a', 256)], 'value'],
    'unsupported resolution' => [ReviewableTransactionField::MerchantDescription, ['resolution' => 'revise'], 'resolution'],
]);

test('the Review Queue exposes every unresolved Transaction field', function () {
    $owner = User::factory()->create();
    Transaction::factory()
        ->count(101)
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::MerchantDescription])
        ->create();

    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_field_count', 101)
            ->has('transactions', 25)
            ->where('pagination.total', 101));
});

test('the Review Queue rejects non-reviewable Transaction fields', function () {
    $transaction = Transaction::factory()->create();

    $this->actingAs($transaction->owner)
        ->patch(route('review_queue.fields.update', [
            'transaction' => $transaction,
            'field' => 'unreviewable',
        ]), [
            'resolution' => 'accept',
        ])
        ->assertNotFound();
});
