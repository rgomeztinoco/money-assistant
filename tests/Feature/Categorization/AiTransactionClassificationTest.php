<?php

use App\Actions\Categorization\AssignCategoryToTransaction;
use App\Actions\Categorization\ClassifyTransactionWithAi;
use App\AiClassificationError;
use App\AiClassificationInput;
use App\AiClassificationOutcome;
use App\AiClassificationResult;
use App\CategoryAssignmentProvenance;
use App\Contracts\AiClassifier;
use App\Exceptions\AiClassifierResponseInvalid;
use App\Exceptions\AiClassifierTimedOut;
use App\Exceptions\AiClassifierUnavailable;
use App\IntegrationFailureKind;
use App\Models\AiClassificationRequest;
use App\Models\Category;
use App\Models\CategoryAssignment;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

test('AI receives only normalized Transaction attributes and active Category paths with guidance', function () {
    $owner = User::factory()->create();
    $food = Category::factory()->for($owner, 'owner')->create([
        'name' => 'Food',
        'description' => 'Everyday food and drink.',
        'examples' => ['Markets'],
    ]);
    Category::factory()->for($owner, 'owner')->for($food, 'parent')->create([
        'name' => 'Groceries',
        'description' => 'Ingredients and household staples.',
        'examples' => ['Supermarkets', 'Produce stalls'],
    ]);
    Category::factory()->for($owner, 'owner')->create([
        'name' => 'Retired',
        'description' => 'Must not leave the application.',
        'retired_at' => now(),
    ]);
    $transaction = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create([
        'amount_minor' => 12_450,
        'merchant_description' => '  CAFÉ—Central,   Lima ',
        'payment_instrument_label' => 'Private Visa',
        'payment_instrument_last_four' => '1234',
        'occurred_on' => '2026-07-28',
    ]);
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create(['expected_transaction_revision' => 1]);
    $receivedInput = null;

    app()->instance(AiClassifier::class, new class($receivedInput) implements AiClassifier
    {
        private mixed $receivedInput;

        public function __construct(mixed &$receivedInput)
        {
            $this->receivedInput = &$receivedInput;
        }

        public function version(): string
        {
            return 'classifier-2026-07';
        }

        public function classify(AiClassificationInput $input): AiClassificationResult
        {
            $this->receivedInput = $input;

            return new AiClassificationResult(
                categoryPath: $input->categories[1]['path'],
                confidence: 87,
                explanation: 'The normalized merchant matches grocery guidance.',
            );
        }
    });

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    expect($receivedInput)->toBeInstanceOf(AiClassificationInput::class)
        ->and(get_object_vars($receivedInput))->toBe([
            'merchantDescription' => 'café central lima',
            'kind' => 'purchase',
            'amountMinor' => 12_450,
            'currency' => 'PEN',
            'categories' => [
                [
                    'path' => 'Food',
                    'description' => 'Everyday food and drink.',
                    'examples' => ['Markets'],
                ],
                [
                    'path' => 'Food > Groceries',
                    'description' => 'Ingredients and household staples.',
                    'examples' => ['Supermarkets', 'Produce stalls'],
                ],
            ],
        ]);
});

test('medium and reported high scores apply provisionally and enter review', function (int $confidence) {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->for($owner, 'owner')->purchase()->pen()->create();
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();

    bindAiClassifierResult(new AiClassificationResult(
        categoryPath: 'Groceries',
        confidence: $confidence,
        explanation: 'The merchant matches the grocery guidance.',
    ));

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    $transaction->refresh();
    $assignment = CategoryAssignment::query()->sole();

    expect($transaction)
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Ai)
        ->revision->toBe(2)
        ->and($assignment)
        ->category_id->toBe($category->id)
        ->ai_classifier_version->toBe('classifier-2026-07')
        ->ai_confidence->toBe($confidence)
        ->ai_outcome->toBe(AiClassificationOutcome::Medium)
        ->ai_explanation->toBe('The merchant matches the grocery guidance.')
        ->and($assignment->getAttributes())
        ->not->toHaveKeys(['raw_prompt', 'raw_response', 'prompt', 'response']);

    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_category_count', 1)
            ->where('review_queue.outstanding_count', 1)
            ->where('workspace_transactions', fn (Collection $transactions): bool => $transactions
                ->contains('id', $transaction->id)));
})->with([
    'lower medium boundary' => 60,
    'upper medium boundary' => 94,
    'reported high boundary' => 95,
    'maximum reported score' => 100,
]);

test('low scores remain Uncategorized in review with a distinct outcome', function (int $confidence) {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();

    bindAiClassifierResult(new AiClassificationResult(
        categoryPath: $category->name,
        confidence: $confidence,
        explanation: 'No Category is a reliable match.',
    ));

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    $transaction->refresh();
    $assignment = CategoryAssignment::query()->sole();

    expect($transaction)
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->and($assignment)
        ->category_id->toBeNull()
        ->ai_confidence->toBe($confidence)
        ->ai_outcome->toBe(AiClassificationOutcome::LowConfidence)
        ->and($classificationRequest->fresh())
        ->terminal_outcome->toBe(AiClassificationOutcome::LowConfidence);

    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_category_count', 1)
            ->where('review_queue.outstanding_count', 1)
            ->where('workspace_transactions', fn (Collection $transactions): bool => $transactions
                ->contains('id', $transaction->id)));
})->with([
    'minimum score' => 0,
    'upper low boundary' => 59,
]);

test('an invalid Category result remains Uncategorized regardless of its score', function (int $confidence) {
    $owner = User::factory()->create();
    $invalidCategory = Category::factory()->for($owner, 'owner')->create([
        'retired_at' => now(),
    ]);
    Category::factory()->for($owner, 'owner')->create(['name' => 'Active']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();

    bindAiClassifierResult(new AiClassificationResult(
        categoryPath: $invalidCategory->name,
        confidence: $confidence,
        explanation: 'The classifier returned an inaccessible Category.',
    ));

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    expect($transaction->fresh())
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->and(CategoryAssignment::query()->sole())
        ->category_id->toBeNull()
        ->ai_confidence->toBe($confidence)
        ->ai_outcome->toBe(AiClassificationOutcome::InvalidCategory)
        ->and($classificationRequest->fresh())
        ->terminal_outcome->toBe(AiClassificationOutcome::InvalidCategory)
        ->attempt_count->toBe(1)
        ->next_attempt_at->toBeNull();
})->with([
    'low score' => 40,
    'medium score' => 88,
]);

test('a Category retired while classification is in flight is recorded as invalid', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();

    app()->instance(AiClassifier::class, new class($category) implements AiClassifier
    {
        public function __construct(private Category $category) {}

        public function version(): string
        {
            return 'classifier-2026-07';
        }

        public function classify(AiClassificationInput $input): AiClassificationResult
        {
            $this->category->forceFill(['retired_at' => now()])->save();

            return new AiClassificationResult(
                categoryPath: $input->categories[0]['path'],
                confidence: 88,
                explanation: 'The Category was active when the request began.',
            );
        }
    });

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    expect($transaction->fresh())
        ->category_id->toBeNull()
        ->and(CategoryAssignment::query()->sole())
        ->category_id->toBeNull()
        ->ai_outcome->toBe(AiClassificationOutcome::InvalidCategory);
});

test('an owner assignment made while classification is in flight remains authoritative', function () {
    $owner = User::factory()->create();
    $ownerCategory = Category::factory()->for($owner, 'owner')->create(['name' => 'Owner choice']);
    $aiCategory = Category::factory()->for($owner, 'owner')->create(['name' => 'AI choice']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();

    app()->instance(AiClassifier::class, new class($owner, $transaction, $ownerCategory, $aiCategory) implements AiClassifier
    {
        public function __construct(
            private User $owner,
            private Transaction $transaction,
            private Category $ownerCategory,
            private Category $aiCategory,
        ) {}

        public function version(): string
        {
            return 'classifier-2026-07';
        }

        public function classify(AiClassificationInput $input): AiClassificationResult
        {
            app(AssignCategoryToTransaction::class)->handle(
                owner: $this->owner,
                transactionId: $this->transaction->id,
                expectedRevision: 1,
                categoryId: $this->ownerCategory->id,
                expectedCategoryRevision: 1,
            );

            return new AiClassificationResult(
                categoryPath: $this->aiCategory->name,
                confidence: 90,
                explanation: 'The delayed classifier chose another Category.',
            );
        }
    });

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    expect($transaction->fresh())
        ->category_id->toBe($ownerCategory->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->revision->toBe(2)
        ->and(CategoryAssignment::query()->count())->toBe(1)
        ->and(CategoryAssignment::query()->sole()->source)->toBe(CategoryAssignmentProvenance::Owner)
        ->and($classificationRequest->fresh())
        ->terminal_outcome->toBe(AiClassificationOutcome::Superseded)
        ->last_error_code->toBe(AiClassificationError::AuthoritativeAssignment);
});

test('a non-category edit discards stale AI output and classifies the current Transaction revision', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->for($owner, 'owner')->create([
        'merchant_description' => 'Original merchant',
    ]);
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();
    $classifier = new class($transaction) implements AiClassifier
    {
        /** @var list<string> */
        public array $merchants = [];

        public function __construct(private Transaction $transaction) {}

        public function version(): string
        {
            return 'classifier-2026-07';
        }

        public function classify(AiClassificationInput $input): AiClassificationResult
        {
            $this->merchants[] = $input->merchantDescription;

            if (count($this->merchants) === 1) {
                $this->transaction->forceFill([
                    'merchant_description' => 'Updated merchant',
                    'revision' => 2,
                ])->save();
            }

            return new AiClassificationResult(
                categoryPath: 'Groceries',
                confidence: 90,
                explanation: 'The merchant matches the grocery guidance.',
            );
        }
    };
    app()->instance(AiClassifier::class, $classifier);

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    expect($classificationRequest->fresh())
        ->expected_transaction_revision->toBe(2)
        ->attempt_count->toBe(0)
        ->completed_at->toBeNull()
        ->and(CategoryAssignment::query()->count())->toBe(0);

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    expect($classifier->merchants)->toBe(['original merchant', 'updated merchant'])
        ->and($transaction->fresh())
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Ai)
        ->and(CategoryAssignment::query()->count())->toBe(1);
});

test('timeout and unavailability park after one day as distinct Uncategorized outcomes', function (
    string $exceptionClass,
    AiClassificationOutcome $expectedOutcome,
) {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00:00 UTC'));
    $owner = User::factory()->create();
    Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();

    app()->instance(AiClassifier::class, new class($exceptionClass) implements AiClassifier
    {
        /** @param class-string<Throwable> $exceptionClass */
        public function __construct(private string $exceptionClass) {}

        public function version(): string
        {
            return 'classifier-2026-07';
        }

        public function classify(AiClassificationInput $input): AiClassificationResult
        {
            throw new $this->exceptionClass('The classifier did not return a result.');
        }
    });

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);
    $classificationRequest->refresh();
    $incident = $owner->integrationIncidents()->sole();

    expect($classificationRequest)
        ->attempt_count->toBe(1)
        ->completed_at->toBeNull()
        ->next_attempt_at->not->toBeNull()
        ->and($incident->failure_kind)->toBe(IntegrationFailureKind::Transient);

    $this->travelTo($incident->retry_until);
    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);
    $classificationRequest->refresh();

    $assignment = CategoryAssignment::query()->sole();

    expect($transaction->fresh())
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->and($assignment)
        ->category_id->toBeNull()
        ->ai_classifier_version->toBe('classifier-2026-07')
        ->ai_confidence->toBeNull()
        ->ai_outcome->toBe($expectedOutcome)
        ->and($classificationRequest)
        ->attempt_count->toBe(2)
        ->terminal_outcome->toBe($expectedOutcome)
        ->completed_at->not->toBeNull()
        ->next_attempt_at->toBeNull()
        ->and($incident->fresh()->parked_at?->toIso8601String())->toBe(now()->toIso8601String());
})->with([
    'timeout' => [AiClassifierTimedOut::class, AiClassificationOutcome::Timeout],
    'unavailable' => [AiClassifierUnavailable::class, AiClassificationOutcome::Unavailable],
]);

test('an invalid classifier schema parks without a transient retry', function () {
    $owner = User::factory()->create();
    Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();
    app()->instance(AiClassifier::class, new class implements AiClassifier
    {
        public function version(): string
        {
            return 'classifier-2026-07';
        }

        public function classify(AiClassificationInput $input): never
        {
            throw new AiClassifierResponseInvalid('The classifier returned an invalid schema.');
        }
    });

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    expect($classificationRequest->fresh())
        ->attempt_count->toBe(1)
        ->terminal_outcome->toBe(AiClassificationOutcome::Unavailable)
        ->completed_at->not->toBeNull()
        ->next_attempt_at->toBeNull()
        ->and($owner->integrationIncidents()->sole()->failure_kind)
        ->toBe(IntegrationFailureKind::Schema);
});

test('a bounded retry cannot replace a Category supplied by the owner', function () {
    $owner = User::factory()->create();
    $ownerCategory = Category::factory()->for($owner, 'owner')->create();
    $transaction = Transaction::factory()->for($owner, 'owner')->create();
    $classificationRequest = AiClassificationRequest::factory()
        ->for($owner, 'owner')
        ->for($transaction)
        ->create();
    $classifier = new class implements AiClassifier
    {
        public int $calls = 0;

        public function version(): string
        {
            return 'classifier-2026-07';
        }

        public function classify(AiClassificationInput $input): AiClassificationResult
        {
            $this->calls++;

            throw new AiClassifierUnavailable('The classifier is temporarily unavailable.');
        }
    };
    app()->instance(AiClassifier::class, $classifier);

    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);
    $classificationRequest->refresh();

    app(AssignCategoryToTransaction::class)->handle(
        owner: $owner,
        transactionId: $transaction->id,
        expectedRevision: 1,
        categoryId: $ownerCategory->id,
        expectedCategoryRevision: 1,
    );

    $this->travelTo($classificationRequest->next_attempt_at);
    app(ClassifyTransactionWithAi::class)->handle($classificationRequest->id);

    expect($classifier->calls)->toBe(1)
        ->and($transaction->fresh())
        ->category_id->toBe($ownerCategory->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->and(CategoryAssignment::query()->count())->toBe(1)
        ->and($classificationRequest->fresh())
        ->terminal_outcome->toBe(AiClassificationOutcome::Superseded)
        ->last_error_code->toBe(AiClassificationError::AuthoritativeAssignment)
        ->and($owner->integrationIncidents()->sole()->recovered_at)->not->toBeNull();
});

function bindAiClassifierResult(AiClassificationResult $result): void
{
    app()->instance(AiClassifier::class, new class($result) implements AiClassifier
    {
        public function __construct(private AiClassificationResult $result) {}

        public function version(): string
        {
            return 'classifier-2026-07';
        }

        public function classify(AiClassificationInput $input): AiClassificationResult
        {
            return $this->result;
        }
    });
}
