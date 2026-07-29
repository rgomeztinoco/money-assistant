<?php

use App\Actions\Categorization\ClassifyTransactionWithAi;
use App\AiCategoryProposalResult;
use App\AiClassificationInput;
use App\AiClassificationOutcome;
use App\AiClassificationResult;
use App\CategoryAssignmentProvenance;
use App\Contracts\AiClassifier;
use App\Models\AiCategoryProposal;
use App\Models\AiClassificationRequest;
use App\Models\Category;
use App\Models\CategoryAssignment;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('AI can propose a missing Category without creating or assigning it', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Food',
    ]);
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();

    bindMissingCategoryProposal($parent);

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    $proposal = AiCategoryProposal::query()->sole();

    expect(Category::query()->count())->toBe(1)
        ->and($transaction->fresh())
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->and(CategoryAssignment::query()->sole())
        ->ai_outcome->toBe(AiClassificationOutcome::MissingCategory)
        ->and($proposal)
        ->transaction_id->toBe($transaction->id)
        ->parent_id->toBe($parent->id)
        ->name->toBe('Bakeries')
        ->description->toBe('Bread, pastries, and baked goods.')
        ->examples->toBe(['Neighborhood bakery'])
        ->confirmed_at->toBeNull();

    $this->actingAs($owner)
        ->get(route('transactions.index', ['selected' => $transaction->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_transaction.ai_category_proposal.id', $proposal->id)
            ->where('selected_transaction.ai_category_proposal.revision', 1)
            ->where('selected_transaction.ai_category_proposal.name', 'Bakeries')
            ->where('selected_transaction.ai_category_proposal.parent_path', 'Food')
            ->where('selected_transaction.ai_category_proposal.description', 'Bread, pastries, and baked goods.')
            ->where('selected_transaction.ai_category_proposal.examples', ['Neighborhood bakery']));
});

test('only explicit owner confirmation creates the proposed Category and assigns the current Transaction', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Food',
    ]);
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $otherTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => $transaction->merchant_description,
    ]);
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();

    bindMissingCategoryProposal($parent);
    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);
    $proposal = AiCategoryProposal::query()->sole();
    $transaction->refresh();

    $this->actingAs($owner)
        ->post(route('transactions.ai_category_proposals.confirmation.store', [
            'transaction' => $transaction,
            'ai_category_proposal' => $proposal,
        ]), [
            'expected_transaction_revision' => $transaction->revision,
            'expected_proposal_revision' => $proposal->revision,
        ])
        ->assertRedirect();

    $createdCategory = Category::query()->where('name', 'Bakeries')->sole();

    expect($createdCategory)
        ->parent_id->toBe($parent->id)
        ->description->toBe('Bread, pastries, and baked goods.')
        ->examples->toBe(['Neighborhood bakery'])
        ->and($transaction->fresh())
        ->category_id->toBe($createdCategory->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->and($otherTransaction->fresh())
        ->category_id->toBeNull()
        ->and($proposal->fresh())
        ->confirmed_category_id->toBe($createdCategory->id)
        ->confirmed_at->not->toBeNull();
});

test('proposal confirmation is transaction scoped and rejects stale state without creating a Category', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $otherTransaction = Transaction::factory()->for($owner, 'owner')->create();
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();

    bindMissingCategoryProposal($parent);
    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);
    $proposal = AiCategoryProposal::query()->sole();
    $transaction->refresh();

    $this->actingAs($owner)
        ->post(route('transactions.ai_category_proposals.confirmation.store', [
            'transaction' => $otherTransaction,
            'ai_category_proposal' => $proposal,
        ]), [
            'expected_transaction_revision' => $otherTransaction->revision,
            'expected_proposal_revision' => $proposal->revision,
        ])
        ->assertForbidden();

    $this->post(route('transactions.ai_category_proposals.confirmation.store', [
        'transaction' => $transaction,
        'ai_category_proposal' => $proposal,
    ]), [
        'expected_transaction_revision' => $transaction->revision - 1,
        'expected_proposal_revision' => $proposal->revision,
    ])
        ->assertSessionHasErrors('expected_transaction_revision');

    expect(Category::query()->where('name', 'Bakeries')->exists())->toBeFalse()
        ->and($transaction->fresh()->category_id)->toBeNull()
        ->and($proposal->fresh()->confirmed_at)->toBeNull();
});

function bindMissingCategoryProposal(Category $parent): void
{
    app()->instance(AiClassifier::class, new class($parent) implements AiClassifier
    {
        public function __construct(private Category $parent) {}

        public function version(): string
        {
            return 'classifier-2026-07';
        }

        public function classify(AiClassificationInput $input): AiClassificationResult
        {
            return new AiClassificationResult(
                categoryPath: null,
                confidence: 91,
                explanation: 'No active Category adequately fits this merchant.',
                categoryProposal: new AiCategoryProposalResult(
                    name: 'Bakeries',
                    parentCategoryPath: $this->parent->name,
                    description: 'Bread, pastries, and baked goods.',
                    examples: ['Neighborhood bakery'],
                ),
            );
        }
    });
}
