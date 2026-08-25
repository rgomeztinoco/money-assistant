<?php

use App\Actions\Ledger\RecordManualTransaction;
use App\CategoryAssignmentProvenance;
use App\Currency;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\ReceiptBreakdown;
use App\Models\Transaction;
use App\Models\User;
use App\RefundRelationshipReviewReason;
use App\ReviewableTransactionField;
use App\TransactionKind;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

test('the owner navigates the guided queue and categorizes matching current and future Transactions', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Coffee']);
    $resolvedCategory = Category::factory()->for($owner, 'owner')->create(['name' => 'Resolved']);
    $current = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-20',
        'description' => 'CAFÉ—Central!!!',
    ]);
    $matching = Transaction::factory()->for($owner, 'owner')->spending()->pen()->create([
        'occurred_on' => '2026-08-19',
        'description' => " cafe\u{0301} central ",
    ]);
    $flagged = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::Description])
        ->create([
            'occurred_on' => '2026-08-18',
            'description' => 'Flagged merchant',
            'category_id' => $resolvedCategory->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);

    $page = visit('/review-queue');

    $page
        ->assertSee('Item 1 of 3')
        ->assertSee('CAFÉ—Central!!!')
        ->click('Skip')
        ->assertSee('Item 2 of 3')
        ->assertSee('café central')
        ->click('Back')
        ->assertSee('Item 1 of 3')
        ->click('Overview')
        ->assertSee('Review Queue overview')
        ->assertSee('Flagged merchant')
        ->click('Continue guided review')
        ->select('Assign a Category', 'Coffee')
        ->select('[name="create_merchant_rule"]', '1')
        ->select('[name="bulk_assign"]', '1')
        ->press('Assign Category and continue')
        ->assertSee('Item 1 of 1')
        ->assertSee('Flagged merchant')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($current->fresh())
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->and($matching->fresh()->category_id)->toBe($category->id)
        ->and($flagged->fresh()->category_id)->toBe($resolvedCategory->id)
        ->and(MerchantRule::query()->sole()->merchant_key)->toBe('café central');
});

test('the owner resolves flagged Transaction fields without returning to the ledger', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $transaction = app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-22'),
        amountMinor: 12345,
        currency: Currency::Usd,
        kind: TransactionKind::Spending,
        description: 'Provisional market',
        provisionalFields: [
            ReviewableTransactionField::OccurredOn,
            ReviewableTransactionField::AmountMinor,
            ReviewableTransactionField::Description,
        ],
    );
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction->update([
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);

    $page = visit('/review-queue');

    $page
        ->assertSee('3 reasons')
        ->assertSee('Why this needs attention')
        ->fill('Correct occurrence date', '2026-07-23')
        ->press('Save occurrence date')
        ->assertSee('2 reasons')
        ->fill('Correct amount in minor units', '9000')
        ->press('Save amount in minor units')
        ->assertSee('1 reason')
        ->fill('Correct merchant or description', 'Neighborhood market')
        ->press('Save merchant or description')
        ->assertSee('Review Queue is clear')
        ->click('View Transactions')
        ->assertSee('$ 90.00')
        ->assertSee('Neighborhood market')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('categorizing a Transaction with another review reason keeps the owner on that item', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $current = Transaction::factory()
        ->for($owner, 'owner')
        ->provisional([ReviewableTransactionField::OccurredOn])
        ->create([
            'occurred_on' => '2026-08-20',
            'description' => 'Two decisions market',
        ]);
    Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-08-19',
        'description' => 'Next market',
    ]);

    $page = visit("/review-queue?item=transaction:{$current->id}");

    $page
        ->assertSee('2 reasons')
        ->select('Assign a Category', 'Groceries')
        ->press('Assign Category and continue')
        ->assertSee('Two decisions market')
        ->assertSee('1 reason')
        ->assertSee('Occurrence date needs confirmation')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the owner corrects a Refund relationship and continues inside the guided queue', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $category = Category::factory()->for($owner, 'owner')->create();
    $spending = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create([
        'amount_minor' => 10_000,
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    ReceiptBreakdown::factory()->for($spending)->create();
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->usd()->create([
        'occurred_on' => '2026-08-20',
        'description' => 'Refund needing allocation',
        'original_spending_id' => $spending->id,
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        'refund_relationship_review_reasons' => [
            RefundRelationshipReviewReason::ReceiptBreakdownAllocationRequiresReview->value,
        ],
    ]);
    Transaction::factory()->for($owner, 'owner')->create([
        'occurred_on' => '2026-08-19',
        'description' => 'Next queue item',
    ]);

    $page = visit("/review-queue?item=transaction:{$refund->id}");

    $page
        ->assertSee('Receipt Breakdown allocation requires review')
        ->click('Correct this relationship')
        ->assertSee('Edit current Transaction')
        ->select('Edit original Spending Transaction', 'No reimbursement link')
        ->press('Save Transaction')
        ->assertSee('Next queue item')
        ->assertDontSee('Refund needing allocation')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($refund->fresh())
        ->original_spending_id->toBeNull()
        ->refund_relationship_review_reasons->toBe([]);
});
