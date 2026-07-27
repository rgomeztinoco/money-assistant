<?php

use App\CategoryAssignmentProvenance;
use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

function createPrecedenceRule(
    User $owner,
    Category $category,
    string $merchantPattern,
    string $matchMode,
    ?string $kind = null,
    ?string $currency = null,
): LearnedRule {
    $rule = LearnedRule::factory()->for($owner, 'owner')->create();
    $rule->revisions()->create([
        'revision' => 1,
        'category_id' => $category->id,
        'merchant_pattern' => $merchantPattern,
        'merchant_key' => app(MerchantNormalizer::class)->normalize($merchantPattern),
        'match_mode' => $matchMode,
        'transaction_kind' => $kind,
        'currency' => $currency,
    ]);

    return $rule;
}

test('future matching resolves by constraint count then match mode then merchant pattern length', function () {
    $owner = User::factory()->create();
    $lessConstrained = Category::factory()->for($owner, 'owner')->create();
    $moreConstrained = Category::factory()->for($owner, 'owner')->create();
    $startsWith = Category::factory()->for($owner, 'owner')->create();
    $exact = Category::factory()->for($owner, 'owner')->create();
    $shorter = Category::factory()->for($owner, 'owner')->create();
    $longer = Category::factory()->for($owner, 'owner')->create();

    createPrecedenceRule($owner, $lessConstrained, 'Metro Central Lima', 'exact');
    $constraintWinner = createPrecedenceRule($owner, $moreConstrained, 'Central', 'contains', 'purchase');
    createPrecedenceRule($owner, $startsWith, 'Coffee', 'starts_with', 'purchase');
    $modeWinner = createPrecedenceRule($owner, $exact, 'Coffee Lab', 'exact', 'purchase');
    createPrecedenceRule($owner, $shorter, 'Airport', 'starts_with', 'purchase');
    $lengthWinner = createPrecedenceRule($owner, $longer, 'Airport Taxi', 'starts_with', 'purchase');

    $this->actingAs($owner);

    foreach ([
        ['Metro Central Lima', $moreConstrained, $constraintWinner],
        ['Coffee Lab', $exact, $modeWinner],
        ['Airport Taxi Lima', $longer, $lengthWinner],
    ] as [$merchant, $expectedCategory, $expectedRule]) {
        $this->post(route('transactions.store'), [
            'occurred_on' => '2026-07-27',
            'amount_minor' => 1_000,
            'currency' => 'PEN',
            'kind' => 'purchase',
            'merchant_description' => $merchant,
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('merchant_description', $merchant)
            ->with('currentCategoryAssignment')
            ->sole();

        expect($transaction)
            ->category_id->toBe($expectedCategory->id)
            ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::LearnedRule)
            ->and($transaction->currentCategoryAssignment)
            ->learned_rule_id->toBe($expectedRule->id)
            ->learned_rule_revision->toBe(1);
    }
});

test('equally specific conflicting targets leave a future Transaction Uncategorized for review', function () {
    $owner = User::factory()->create();
    $firstCategory = Category::factory()->for($owner, 'owner')->create();
    $secondCategory = Category::factory()->for($owner, 'owner')->create();

    createPrecedenceRule($owner, $firstCategory, 'Conflicted Merchant', 'exact', 'purchase', 'PEN');
    createPrecedenceRule($owner, $secondCategory, 'Conflicted Merchant', 'exact', 'purchase', 'PEN');

    $this->actingAs($owner)
        ->post(route('transactions.store'), [
            'occurred_on' => '2026-07-27',
            'amount_minor' => 1_000,
            'currency' => 'PEN',
            'kind' => 'purchase',
            'merchant_description' => 'Conflicted Merchant',
        ])
        ->assertSessionHasNoErrors();

    $transaction = Transaction::query()->whereBelongsTo($owner, 'owner')->sole();

    expect($transaction)
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->revision->toBe(1);

    $this->get(route('review_queue.index'))
        ->assertInertia(fn ($page) => $page
            ->where('unresolved_category_count', 1)
            ->where('workspace_transactions', fn (Collection $transactions): bool => $transactions->contains('id', $transaction->id)));
});

test('matching payment instrument scopes contribute independently to specificity', function () {
    $owner = User::factory()->create();
    $currencyTarget = Category::factory()->for($owner, 'owner')->create();
    $instrumentTarget = Category::factory()->for($owner, 'owner')->create();
    createPrecedenceRule($owner, $currencyTarget, 'Scoped Card', 'exact', null, 'PEN');
    $instrumentRule = createPrecedenceRule($owner, $instrumentTarget, 'Scoped Card', 'exact');
    $instrumentRule->currentRevision()->update([
        'payment_instrument_label' => 'Visa',
        'payment_instrument_last_four' => '1234',
    ]);

    $this->actingAs($owner)->post(route('transactions.store'), [
        'occurred_on' => '2026-07-27',
        'amount_minor' => 1_000,
        'kind' => 'purchase',
        'merchant_description' => 'Scoped Card',
        'currency' => 'PEN',
        'payment_instrument_label' => 'VISA',
        'payment_instrument_last_four' => '1234',
    ])->assertSessionHasNoErrors();

    $resolved = Transaction::query()->whereBelongsTo($owner, 'owner')->sole();

    expect($resolved->category_id)->toBe($instrumentTarget->id)
        ->and($resolved->currentCategoryAssignment->learned_rule_id)->toBe($instrumentRule->id);
});
