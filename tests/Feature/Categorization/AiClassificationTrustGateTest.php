<?php

use App\Actions\Categorization\AssignCategoryToTransaction;
use App\Actions\Categorization\ClassifyTransactionWithAi;
use App\Actions\Categorization\UpdateCategory;
use App\AiClassificationInput;
use App\AiClassificationOutcome;
use App\AiClassificationResult;
use App\Contracts\AiClassifier;
use App\Models\AiClassificationRequest;
use App\Models\Category;
use App\Models\CategoryAssignment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Lottery;
use Illuminate\Support\Str;

afterEach(function () {
    Lottery::determineResultsNormally();
});

test('high confidence automation opens only after fifty qualifying owner reviews', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
        'description' => 'Ingredients and household staples.',
    ]);

    bindTrustGateClassifier($category);

    for ($reviewedPrediction = 1; $reviewedPrediction <= 49; $reviewedPrediction++) {
        $transaction = classifyHighConfidenceTransaction($owner);

        expect($transaction->hasProvisionalAiCategory())->toBeTrue();

        approveAiCategoryUnchanged($owner, $transaction, $category);
    }

    $fiftiethTransaction = classifyHighConfidenceTransaction($owner);

    expect($fiftiethTransaction->hasProvisionalAiCategory())->toBeTrue();

    approveAiCategoryUnchanged($owner, $fiftiethTransaction, $category);

    Lottery::alwaysLose();

    $validatedTransaction = classifyHighConfidenceTransaction($owner);
    $validatedAssignment = CategoryAssignment::query()
        ->whereBelongsTo($validatedTransaction)
        ->latest('id')
        ->sole();

    expect($validatedAssignment)
        ->ai_outcome->toBe(AiClassificationOutcome::High)
        ->ai_requires_review->toBeFalse()
        ->and($validatedTransaction->hasProvisionalAiCategory())->toBeFalse();
});

test('the trust gate requires at least ninety five percent unchanged approval', function () {
    $owner = User::factory()->create();
    $predictedCategory = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
    ]);
    $correctedCategory = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Restaurants',
    ]);

    bindTrustGateClassifier($predictedCategory);
    earnTrustGateEvidence(
        owner: $owner,
        predictedCategory: $predictedCategory,
        correctedCategory: $correctedCategory,
        changedReviews: 3,
    );

    $nextTransaction = classifyHighConfidenceTransaction($owner);

    expect($nextTransaction->currentCategoryAssignment)
        ->ai_outcome->toBe(AiClassificationOutcome::Medium)
        ->ai_requires_review->toBeTrue()
        ->and($nextTransaction->hasProvisionalAiCategory())->toBeTrue();
});

test('validated high confidence assignments keep a random ten percent review sample', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
    ]);

    bindTrustGateClassifier($category);
    earnTrustGateEvidence($owner, $category);
    Lottery::alwaysWin();

    $sampledTransaction = classifyHighConfidenceTransaction($owner);

    expect($sampledTransaction->currentCategoryAssignment)
        ->ai_outcome->toBe(AiClassificationOutcome::High)
        ->ai_requires_review->toBeTrue()
        ->and($sampledTransaction->hasProvisionalAiCategory())->toBeTrue();
});

test('the gate closes when fewer than ninety five percent of the latest fifty samples are unchanged', function () {
    $owner = User::factory()->create();
    $predictedCategory = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
    ]);
    $correctedCategory = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Restaurants',
    ]);

    bindTrustGateClassifier($predictedCategory);
    earnTrustGateEvidence($owner, $predictedCategory);
    Lottery::alwaysWin();

    for ($changedSample = 1; $changedSample <= 3; $changedSample++) {
        $sampledTransaction = classifyHighConfidenceTransaction($owner);

        expect($sampledTransaction->currentCategoryAssignment->ai_outcome)
            ->toBe(AiClassificationOutcome::High);

        reviewAiCategory($owner, $sampledTransaction, $correctedCategory);
    }

    $nextTransaction = classifyHighConfidenceTransaction($owner);

    expect($nextTransaction->currentCategoryAssignment)
        ->ai_outcome->toBe(AiClassificationOutcome::Medium)
        ->ai_requires_review->toBeTrue();
});

test('rename and move retain high confidence trust', function () {
    $owner = User::factory()->create();
    $parent = Category::factory()->for($owner, 'owner')->create(['name' => 'Food']);
    $renamedCategory = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
    ]);

    bindTrustGateClassifier($renamedCategory);
    earnTrustGateEvidence($owner, $renamedCategory);
    $renamedCategory->forceFill([
        'name' => 'Markets',
        'parent_id' => $parent->id,
        'revision' => 2,
    ])->save();
    bindTrustGateClassifier($renamedCategory->fresh());
    Lottery::alwaysLose();

    expect(classifyHighConfidenceTransaction($owner)->currentCategoryAssignment)
        ->ai_outcome->toBe(AiClassificationOutcome::High);
});

test('Category guidance changes reset high confidence trust', function () {
    $owner = User::factory()->create();
    $guidanceCategory = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
    ]);
    bindTrustGateClassifier($guidanceCategory);
    earnTrustGateEvidence($owner, $guidanceCategory);
    $guidanceCategory->forceFill([
        'description' => 'Updated classifier guidance.',
        'revision' => 2,
    ])->save();
    bindTrustGateClassifier($guidanceCategory->fresh());

    expect(classifyHighConfidenceTransaction($owner)->currentCategoryAssignment)
        ->ai_outcome->toBe(AiClassificationOutcome::Medium);
});

test('active taxonomy membership changes reset high confidence trust', function () {
    $owner = User::factory()->create();
    $membershipCategory = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
    ]);
    bindTrustGateClassifier($membershipCategory);
    earnTrustGateEvidence($owner, $membershipCategory);
    Category::factory()->for($owner, 'owner')->create(['name' => 'Travel']);

    expect(classifyHighConfidenceTransaction($owner)->currentCategoryAssignment)
        ->ai_outcome->toBe(AiClassificationOutcome::Medium);
});

test('classifier version changes reset high confidence trust', function () {
    $owner = User::factory()->create();
    $versionCategory = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
    ]);
    bindTrustGateClassifier($versionCategory);
    earnTrustGateEvidence($owner, $versionCategory);
    bindTrustGateClassifier($versionCategory, 'classifier-2026-08');

    expect(classifyHighConfidenceTransaction($owner)->currentCategoryAssignment)
        ->ai_outcome->toBe(AiClassificationOutcome::Medium);
});

test('reverting classifier version or Category guidance does not restore old trust', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
        'description' => 'Original guidance.',
    ]);

    bindTrustGateClassifier($category);
    earnTrustGateEvidence($owner, $category);

    bindTrustGateClassifier($category, 'classifier-2026-08');
    expect(classifyHighConfidenceTransaction($owner)->currentCategoryAssignment)
        ->ai_outcome->toBe(AiClassificationOutcome::Medium);

    bindTrustGateClassifier($category, 'classifier-2026-07');
    expect(classifyHighConfidenceTransaction($owner)->currentCategoryAssignment)
        ->ai_outcome->toBe(AiClassificationOutcome::Medium);

    app(UpdateCategory::class)->handle(
        owner: $owner,
        categoryId: $category->id,
        expectedRevision: $category->revision,
        name: $category->name,
        parentId: null,
        description: 'Changed guidance.',
        examples: [],
    );
    $category->refresh();
    app(UpdateCategory::class)->handle(
        owner: $owner,
        categoryId: $category->id,
        expectedRevision: $category->revision,
        name: $category->name,
        parentId: null,
        description: 'Original guidance.',
        examples: [],
    );
    bindTrustGateClassifier($category->fresh());

    expect(classifyHighConfidenceTransaction($owner)->currentCategoryAssignment)
        ->ai_outcome->toBe(AiClassificationOutcome::Medium);
});

test('a taxonomy change while classification is in flight downgrades stale validated output to review', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Groceries',
        'description' => 'Original guidance.',
    ]);

    bindTrustGateClassifier($category);
    earnTrustGateEvidence($owner, $category);

    app()->instance(AiClassifier::class, new class($owner, $category) implements AiClassifier
    {
        public function __construct(
            private User $owner,
            private Category $category,
        ) {}

        public function version(): string
        {
            return 'classifier-2026-07';
        }

        public function classify(AiClassificationInput $input): AiClassificationResult
        {
            app(UpdateCategory::class)->handle(
                owner: $this->owner,
                categoryId: $this->category->id,
                expectedRevision: $this->category->revision,
                name: $this->category->name,
                parentId: null,
                description: 'Changed while classification was in flight.',
                examples: [],
            );

            return new AiClassificationResult(
                categoryPath: $this->category->name,
                confidence: 99,
                explanation: 'This output used stale guidance.',
            );
        }
    });

    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    expect($transaction->fresh())
        ->category_id->toBe($category->id)
        ->and($transaction->fresh()->hasProvisionalAiCategory())->toBeTrue()
        ->and(CategoryAssignment::query()
            ->whereBelongsTo($transaction)
            ->sole())
        ->ai_outcome->toBe(AiClassificationOutcome::Medium)
        ->ai_requires_review->toBeTrue()
        ->and($classificationRequest->fresh())
        ->completed_at->not->toBeNull();
});

function bindTrustGateClassifier(Category $category, string $version = 'classifier-2026-07'): void
{
    app()->instance(AiClassifier::class, new class($category, $version) implements AiClassifier
    {
        public function __construct(
            private Category $category,
            private string $classifierVersion,
        ) {}

        public function version(): string
        {
            return $this->classifierVersion;
        }

        public function classify(AiClassificationInput $input): AiClassificationResult
        {
            $categoryPath = collect($input->categories)
                ->first(fn (array $guidance): bool => $guidance['path'] === $this->category->name
                    || Str::endsWith($guidance['path'], ' > '.$this->category->name))['path'];

            return new AiClassificationResult(
                categoryPath: $categoryPath,
                confidence: 99,
                explanation: 'The merchant is a strong match.',
            );
        }
    });
}

function classifyHighConfidenceTransaction(User $owner): Transaction
{
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    return $transaction->fresh();
}

function approveAiCategoryUnchanged(User $owner, Transaction $transaction, Category $category): void
{
    reviewAiCategory($owner, $transaction, $category);
}

function reviewAiCategory(User $owner, Transaction $transaction, Category $category): void
{
    app(AssignCategoryToTransaction::class)->handle(
        owner: $owner,
        transactionId: $transaction->id,
        expectedRevision: $transaction->revision,
        categoryId: $category->id,
        expectedCategoryRevision: $category->revision,
    );
}

function earnTrustGateEvidence(
    User $owner,
    Category $predictedCategory,
    ?Category $correctedCategory = null,
    int $changedReviews = 0,
): void {
    for ($reviewedPrediction = 1; $reviewedPrediction <= 50; $reviewedPrediction++) {
        $transaction = classifyHighConfidenceTransaction($owner);
        $reviewCategory = $reviewedPrediction <= $changedReviews
            ? $correctedCategory
            : $predictedCategory;

        reviewAiCategory($owner, $transaction, $reviewCategory ?? $predictedCategory);
    }
}
