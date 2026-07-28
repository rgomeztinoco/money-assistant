<?php

use App\Actions\Ledger\RecordManualTransaction;
use App\Currency;
use App\Jobs\ClassifyTransaction;
use App\MerchantNormalizer;
use App\Models\AiClassificationRequest;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

test('saving an eligible Transaction persists and queues AI classification work once', function () {
    config()->set('cache.default', 'array');
    Queue::fake();
    Schedule::useCache('array');
    $owner = User::factory()->create();
    Category::factory()->for($owner, 'owner')->create();

    $transaction = recordTransactionForAi($owner, 'Needs classification');

    $classificationRequest = AiClassificationRequest::query()->sole();

    expect($classificationRequest)
        ->transaction_id->toBe($transaction->id)
        ->user_id->toBe($owner->id)
        ->expected_transaction_revision->toBe(1)
        ->attempt_count->toBe(0)
        ->queued_at->toBeNull();
    Queue::assertNothingPushed();

    $this->artisan('schedule:run')->assertSuccessful();
    $this->artisan('schedule:run')->assertSuccessful();

    expect($classificationRequest->fresh()->queued_at)->not->toBeNull();
    Queue::assertPushed(
        ClassifyTransaction::class,
        fn (ClassifyTransaction $job): bool => $job->classificationRequestId === $classificationRequest->id,
    );
    Queue::assertPushed(ClassifyTransaction::class, 1);
});

test('a Learned Rule winner prevents AI classification work', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    createAiSchedulingRule($owner, $category, 'Rule Merchant');

    $transaction = recordTransactionForAi($owner, 'Rule Merchant');

    expect($transaction->category_id)->toBe($category->id)
        ->and(AiClassificationRequest::query()->count())->toBe(0);
});

test('a Learned Rule conflict remains Uncategorized without crossing the AI boundary', function () {
    $owner = User::factory()->create();
    $firstCategory = Category::factory()->for($owner, 'owner')->create();
    $secondCategory = Category::factory()->for($owner, 'owner')->create();
    createAiSchedulingRule($owner, $firstCategory, 'Conflict Merchant');
    createAiSchedulingRule($owner, $secondCategory, 'Conflict Merchant');

    $transaction = recordTransactionForAi($owner, 'Conflict Merchant');

    expect($transaction)
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->and(AiClassificationRequest::query()->count())->toBe(0);
});

test('AI classification jobs use the configured non-releasing rate limit', function () {
    $job = new ClassifyTransaction(42);
    $middleware = $job->middleware();
    $limiter = app(RateLimiter::class)->limiter('ai-classifier');

    expect($middleware)
        ->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(RateLimited::class)
        ->shouldRelease->toBeFalse()
        ->and($limiter)->not->toBeNull();

    $limit = $limiter($job);

    expect($limit)
        ->maxAttempts->toBe(30)
        ->decaySeconds->toBe(60);
});

function recordTransactionForAi(User $owner, string $merchantDescription): Transaction
{
    return app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-07-28'),
        amountMinor: 1_250,
        currency: Currency::Pen,
        kind: TransactionKind::Purchase,
        merchantDescription: $merchantDescription,
    );
}

function createAiSchedulingRule(User $owner, Category $category, string $merchant): LearnedRule
{
    $rule = LearnedRule::factory()->for($owner, 'owner')->create();
    $rule->revisions()->create([
        'revision' => 1,
        'category_id' => $category->id,
        'merchant_pattern' => $merchant,
        'merchant_key' => app(MerchantNormalizer::class)->normalize($merchant),
        'match_mode' => 'exact',
        'transaction_kind' => 'purchase',
        'currency' => 'PEN',
    ]);

    return $rule;
}
