<?php

use App\CategoryAssignmentProvenance;
use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\LearnedRuleBulkAction;
use App\Models\Transaction;
use App\Models\User;

function createHistoricalRule(User $owner, Category $category, string $pattern): LearnedRule
{
    $rule = LearnedRule::factory()->for($owner, 'owner')->create();
    $rule->revisions()->create([
        'revision' => 1,
        'category_id' => $category->id,
        'merchant_pattern' => $pattern,
        'merchant_key' => app(MerchantNormalizer::class)->normalize($pattern),
        'match_mode' => 'exact',
    ]);

    return $rule;
}

test('a separately confirmed finite historical application can be undone for unchanged Transactions only', function () {
    $owner = User::factory()->create();
    $previousCategory = Category::factory()->for($owner, 'owner')->create(['name' => 'Previous']);
    $ruleCategory = Category::factory()->for($owner, 'owner')->create(['name' => 'Rule target']);
    $changedLaterCategory = Category::factory()->for($owner, 'owner')->create(['name' => 'Changed later']);
    $rule = createHistoricalRule($owner, $ruleCategory, 'Historical Merchant');
    $categorized = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Historical Merchant',
        'category_id' => $previousCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $uncategorized = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Historical Merchant',
    ]);

    $this->actingAs($owner)
        ->post(route('learned_rules.historical_applications.store', $rule), [
            'expected_revision' => 1,
        ])
        ->assertInertiaFlash('historical_application_preview.transaction_count', 2)
        ->assertInertiaFlash('historical_application_preview.items.0.transaction_id', $categorized->id)
        ->assertInertiaFlash('historical_application_preview.items.0.previous_category_name', 'Previous')
        ->assertInertiaFlash('historical_application_preview.items.1.transaction_id', $uncategorized->id)
        ->assertInertiaFlash('historical_application_preview.items.1.previous_category_name', null);

    $bulkAction = LearnedRuleBulkAction::query()->whereBelongsTo($owner, 'owner')->sole();

    $this->post(route('learned_rule_bulk_actions.confirmation.store', $bulkAction))
        ->assertSessionHasNoErrors();

    foreach ([$categorized, $uncategorized] as $transaction) {
        $assignment = $transaction->fresh()->currentCategoryAssignment;

        expect($transaction->fresh())
            ->category_id->toBe($ruleCategory->id)
            ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
            ->and($assignment)
            ->source->toBe(CategoryAssignmentProvenance::Owner)
            ->is_correction->toBeTrue()
            ->learned_rule_id->toBe($rule->id)
            ->learned_rule_revision->toBe(1)
            ->learned_rule_bulk_action_id->toBe($bulkAction->id);
    }

    $this->put(route('transactions.category.update', $categorized), [
        'expected_revision' => 2,
        'category_id' => $changedLaterCategory->id,
    ])->assertSessionHasNoErrors();

    $this->delete(route('learned_rule_bulk_actions.destroy', $bulkAction))
        ->assertSessionHasNoErrors();

    expect($categorized->fresh()->category_id)->toBe($changedLaterCategory->id)
        ->and($uncategorized->fresh())
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->and($bulkAction->fresh()->items()->where('status', 'restored')->count())->toBe(1)
        ->and($bulkAction->fresh()->items()->where('status', 'skipped')->count())->toBe(1);

    $revisionsAfterUndo = [$categorized->fresh()->revision, $uncategorized->fresh()->revision];

    $this->delete(route('learned_rule_bulk_actions.destroy', $bulkAction))
        ->assertSessionHasNoErrors();

    expect([$categorized->fresh()->revision, $uncategorized->fresh()->revision])->toBe($revisionsAfterUndo);
});

test('historical confirmation is atomic when any previewed Transaction changed', function () {
    $owner = User::factory()->create();
    $ruleCategory = Category::factory()->for($owner, 'owner')->create();
    $otherCategory = Category::factory()->for($owner, 'owner')->create();
    $rule = createHistoricalRule($owner, $ruleCategory, 'Stale Historical Merchant');
    $transactions = Transaction::factory()->count(2)->for($owner, 'owner')->create([
        'merchant_description' => 'Stale Historical Merchant',
    ]);
    $this->actingAs($owner)
        ->post(route('learned_rules.historical_applications.store', $rule), [
            'expected_revision' => 1,
        ])
        ->assertSessionHasNoErrors();
    $bulkAction = LearnedRuleBulkAction::query()->whereBelongsTo($owner, 'owner')->sole();

    $this->put(route('transactions.category.update', $transactions->first()), [
        'expected_revision' => 1,
        'category_id' => $otherCategory->id,
    ])->assertSessionHasNoErrors();

    $this->post(route('learned_rule_bulk_actions.confirmation.store', $bulkAction))
        ->assertSessionHasErrors('historical_application');

    expect($transactions->last()->fresh())
        ->category_id->toBeNull()
        ->revision->toBe(1)
        ->and($bulkAction->fresh()->status)->toBe('previewed');
});

test('historical confirmation expires when active rule precedence changes', function () {
    $owner = User::factory()->create();
    $ruleCategory = Category::factory()->for($owner, 'owner')->create();
    $competingCategory = Category::factory()->for($owner, 'owner')->create();
    $rule = createHistoricalRule($owner, $ruleCategory, 'Changing Precedence');
    Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Changing Precedence',
    ]);
    $this->actingAs($owner)
        ->post(route('learned_rules.historical_applications.store', $rule), [
            'expected_revision' => 1,
        ])
        ->assertSessionHasNoErrors();
    $bulkAction = LearnedRuleBulkAction::query()->whereBelongsTo($owner, 'owner')->sole();
    createHistoricalRule($owner, $competingCategory, 'Changing Precedence');

    $this->post(route('learned_rule_bulk_actions.confirmation.store', $bulkAction))
        ->assertSessionHasErrors('historical_application');

    expect(Transaction::query()->whereBelongsTo($owner, 'owner')->sole())
        ->category_id->toBeNull()
        ->revision->toBe(1);
});
