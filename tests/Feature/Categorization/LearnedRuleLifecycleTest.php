<?php

use App\CategoryAssignmentProvenance;
use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\LearnedRuleChangePreview;
use App\Models\Transaction;
use App\Models\User;

function createLifecycleRule(User $owner, Category $category, string $pattern, string $mode = 'exact'): LearnedRule
{
    $rule = LearnedRule::factory()->for($owner, 'owner')->create();
    $rule->revisions()->create([
        'revision' => 1,
        'category_id' => $category->id,
        'merchant_pattern' => $pattern,
        'merchant_key' => app(MerchantNormalizer::class)->normalize($pattern),
        'match_mode' => $mode,
    ]);

    return $rule;
}

test('the owner previews existing matches overlaps and precedence before creating a rule', function () {
    $owner = User::factory()->create();
    $existingTarget = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $proposedTarget = Category::factory()->for($owner, 'owner')->create(['name' => 'Restaurants']);
    $existingRule = createLifecycleRule($owner, $existingTarget, 'Market', 'contains');
    $transactions = Transaction::factory()->count(2)->for($owner, 'owner')->create([
        'merchant_description' => 'Market Lima',
        'kind' => 'purchase',
        'currency' => 'PEN',
    ]);

    $this->actingAs($owner)
        ->post(route('learned_rule_previews.store'), [
            'category_id' => $proposedTarget->id,
            'merchant_pattern' => 'Market Lima',
            'match_mode' => 'exact',
            'transaction_kind' => 'purchase',
            'currency' => 'PEN',
        ])
        ->assertRedirect(route('learned_rules.index'))
        ->assertInertiaFlash('rule_change_preview.existing_match_count', 2)
        ->assertInertiaFlash('rule_change_preview.blocked', false)
        ->assertInertiaFlash('rule_change_preview.overlaps.0.rule_id', $existingRule->id)
        ->assertInertiaFlash('rule_change_preview.overlaps.0.precedence', 'proposed_wins');

    $preview = LearnedRuleChangePreview::query()->whereBelongsTo($owner, 'owner')->sole();

    $this->post(route('learned_rules.store'), [
        'preview_id' => $preview->id,
    ])->assertSessionHasNoErrors();

    $createdRule = LearnedRule::query()->whereBelongsTo($owner, 'owner')->whereKeyNot($existingRule->id)->sole();

    expect($transactions->pluck('category_id')->all())->toBe([null, null])
        ->and($createdRule->currentRevision->category_id)->toBe($proposedTarget->id);

    $this->post(route('transactions.store'), [
        'occurred_on' => '2026-07-27',
        'amount_minor' => 1_000,
        'currency' => 'PEN',
        'kind' => 'purchase',
        'merchant_description' => 'Market Lima',
    ])->assertSessionHasNoErrors();

    $futureTransaction = Transaction::query()->whereBelongsTo($owner, 'owner')->latest('id')->firstOrFail();

    expect($futureTransaction->category_id)->toBe($proposedTarget->id);
});

test('revision retirement and reactivation affect future matching only and retain producing revisions', function () {
    $owner = User::factory()->create();
    $firstTarget = Category::factory()->for($owner, 'owner')->create();
    $secondTarget = Category::factory()->for($owner, 'owner')->create();
    $rule = createLifecycleRule($owner, $firstTarget, 'Old Merchant');
    $this->actingAs($owner);

    $record = function (string $merchant) {
        return $this->post(route('transactions.store'), [
            'occurred_on' => '2026-07-27',
            'amount_minor' => 1_000,
            'currency' => 'PEN',
            'kind' => 'purchase',
            'merchant_description' => $merchant,
        ]);
    };

    $record('Old Merchant')->assertSessionHasNoErrors();
    $historicalTransaction = Transaction::query()->whereBelongsTo($owner, 'owner')->sole();

    $this->post(route('learned_rule_previews.store'), [
        'learned_rule_id' => $rule->id,
        'expected_revision' => 1,
        'category_id' => $secondTarget->id,
        'merchant_pattern' => 'New Merchant',
        'match_mode' => 'exact',
    ])->assertInertiaFlash('rule_change_preview.blocked', false)
        ->assertInertiaFlash('rule_change_preview.new_match_count', 0)
        ->assertInertiaFlash('rule_change_preview.lost_match_count', 1)
        ->assertInertiaFlash('rule_change_preview.lost_matches.0.id', $historicalTransaction->id);

    $preview = LearnedRuleChangePreview::query()->whereBelongsTo($owner, 'owner')->sole();

    $this->patch(route('learned_rules.update', $rule), [
        'preview_id' => $preview->id,
    ])->assertSessionHasNoErrors();

    expect($rule->fresh())
        ->revision->toBe(2)
        ->retired_at->toBeNull()
        ->and($historicalTransaction->fresh())
        ->category_id->toBe($firstTarget->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::LearnedRule)
        ->and($historicalTransaction->currentCategoryAssignment->learned_rule_revision)->toBe(1);

    $record('New Merchant')->assertSessionHasNoErrors();
    $revisionTwoTransaction = Transaction::query()->where('merchant_description', 'New Merchant')->latest('id')->firstOrFail();

    expect($revisionTwoTransaction->category_id)->toBe($secondTarget->id)
        ->and($revisionTwoTransaction->currentCategoryAssignment->learned_rule_revision)->toBe(2);

    $this->post(route('learned_rules.retirement.store', $rule), [
        'expected_revision' => 2,
    ])->assertSessionHasNoErrors();
    $record('New Merchant')->assertSessionHasNoErrors();

    expect(Transaction::query()->latest('id')->firstOrFail()->category_id)->toBeNull()
        ->and($revisionTwoTransaction->fresh()->category_id)->toBe($secondTarget->id);

    $this->delete(route('learned_rules.retirement.destroy', $rule), [
        'expected_revision' => 2,
    ])->assertSessionHasNoErrors();
    $record('New Merchant')->assertSessionHasNoErrors();

    expect(Transaction::query()->latest('id')->firstOrFail()->category_id)->toBe($secondTarget->id)
        ->and(Transaction::query()->latest('id')->firstOrFail()->currentCategoryAssignment->learned_rule_revision)->toBe(2);
});

test('an equally specific conflicting preview is visible but cannot be confirmed', function () {
    $owner = User::factory()->create();
    $existingTarget = Category::factory()->for($owner, 'owner')->create();
    $conflictingTarget = Category::factory()->for($owner, 'owner')->create();
    createLifecycleRule($owner, $existingTarget, 'Equal Conflict');

    $this->actingAs($owner)
        ->post(route('learned_rule_previews.store'), [
            'category_id' => $conflictingTarget->id,
            'merchant_pattern' => 'Equal Conflict',
            'match_mode' => 'exact',
        ])
        ->assertInertiaFlash('rule_change_preview.blocked', true)
        ->assertInertiaFlash('rule_change_preview.overlaps.0.precedence', 'equal_conflict');

    $preview = LearnedRuleChangePreview::query()->whereBelongsTo($owner, 'owner')->sole();

    $this->post(route('learned_rules.store'), ['preview_id' => $preview->id])
        ->assertSessionHasErrors('preview_id');

    expect(LearnedRule::query()->whereBelongsTo($owner, 'owner')->count())->toBe(1);
});
