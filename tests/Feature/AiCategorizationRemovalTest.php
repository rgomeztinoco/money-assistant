<?php

use App\Actions\Ledger\RecordManualTransaction;
use App\CategoryAssignmentProvenance;
use App\Currency;
use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('AI categorization application and scheduling surfaces are absent', function () {
    $owner = User::factory()->create();

    expect(Route::has('transactions.ai_category_proposals.confirmation.store'))->toBeFalse()
        ->and(config()->has('services.ai_classifier'))->toBeFalse()
        ->and(file_get_contents(base_path('.env.example')))->not->toContain('AI_CLASSIFIER_')
        ->and(class_exists('App\\Jobs\\ClassifyTransaction'))->toBeFalse()
        ->and(interface_exists('App\\Contracts\\AiClassifier'))->toBeFalse()
        ->and(collect(Schedule::events())->pluck('description'))
        ->not->toContain('ai-transaction-classifications');

    $this->actingAs($owner)
        ->post('/transactions/1/category-proposals/1/confirmation')
        ->assertNotFound();
});

test('AI categorization persistence and Category guidance are absent', function () {
    foreach ([
        'ai_category_proposals',
        'ai_classification_requests',
        'ai_classification_validation_contexts',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    foreach ([
        'ai_classifier_version',
        'ai_confidence',
        'ai_outcome',
        'ai_explanation',
        'ai_taxonomy_fingerprint',
        'ai_requires_review',
        'ai_reviewed_at',
        'ai_approved_unchanged',
        'ai_validation_context_revision',
    ] as $column) {
        expect(Schema::hasColumn('category_assignments', $column))->toBeFalse();
    }

    expect(Schema::hasColumn('categories', 'description'))->toBeFalse()
        ->and(Schema::hasColumn('categories', 'examples'))->toBeFalse()
        ->and(collect(CategoryAssignmentProvenance::cases())->pluck('value'))
        ->not->toContain('ai');
});

test('the removal migration clears every AI-derived Category and classifier queue record', function () {
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $ownerCategory = Category::factory()->for($owner, 'owner')->create();
    $purchase = Transaction::factory()->for($owner, 'owner')->purchase()->create([
        'category_id' => $category->id,
    ]);
    $refund = Transaction::factory()->for($owner, 'owner')->refund()->create([
        'category_id' => $category->id,
        'original_purchase_id' => $purchase->id,
    ]);
    $correctedPurchase = Transaction::factory()->for($owner, 'owner')->purchase()->create([
        'category_id' => $ownerCategory->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);
    $historicallyAiDerivedRefund = Transaction::factory()->for($owner, 'owner')->refund()->create([
        'category_id' => $category->id,
        'original_purchase_id' => $correctedPurchase->id,
    ]);
    $ownerDerivedRefund = Transaction::factory()->for($owner, 'owner')->refund()->create([
        'category_id' => $ownerCategory->id,
        'original_purchase_id' => $correctedPurchase->id,
    ]);
    $ownerAssignedTransaction = Transaction::factory()->for($owner, 'owner')->create([
        'category_id' => $category->id,
        'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
    ]);

    DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_category_assignment_complete');
    DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_category_assignment_complete CHECK ((category_id IS NULL AND category_assignment_provenance IS NULL) OR (category_id IS NOT NULL AND category_assignment_provenance IN ('owner', 'linked_refund', 'learned_rule', 'ai')))");
    DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_details_complete');
    DB::statement('ALTER TABLE category_assignments DROP CONSTRAINT category_assignments_source_supported');
    DB::statement("ALTER TABLE category_assignments ADD CONSTRAINT category_assignments_source_supported CHECK (source IN ('owner', 'linked_refund', 'learned_rule', 'ai'))");

    DB::table('transactions')->where('id', $purchase->id)->update([
        'category_assignment_provenance' => 'ai',
    ]);
    DB::table('transactions')->where('id', $refund->id)->update([
        'category_assignment_provenance' => 'linked_refund',
    ]);
    DB::table('transactions')->whereIn('id', [
        $historicallyAiDerivedRefund->id,
        $ownerDerivedRefund->id,
    ])->update([
        'category_assignment_provenance' => 'linked_refund',
    ]);

    $assignmentTimestamp = now();

    DB::table('category_assignments')->insert([
        [
            'user_id' => $owner->id,
            'transaction_id' => $purchase->id,
            'category_id' => $category->id,
            'source' => 'ai',
            'transaction_revision' => 1,
            'linked_purchase_id' => null,
            'created_at' => $assignmentTimestamp,
            'updated_at' => $assignmentTimestamp,
        ],
        [
            'user_id' => $owner->id,
            'transaction_id' => $refund->id,
            'category_id' => $category->id,
            'source' => 'linked_refund',
            'transaction_revision' => 1,
            'linked_purchase_id' => $purchase->id,
            'created_at' => $assignmentTimestamp,
            'updated_at' => $assignmentTimestamp,
        ],
        [
            'user_id' => $owner->id,
            'transaction_id' => $correctedPurchase->id,
            'category_id' => $category->id,
            'source' => 'ai',
            'transaction_revision' => 1,
            'linked_purchase_id' => null,
            'created_at' => $assignmentTimestamp,
            'updated_at' => $assignmentTimestamp,
        ],
        [
            'user_id' => $owner->id,
            'transaction_id' => $historicallyAiDerivedRefund->id,
            'category_id' => $category->id,
            'source' => 'linked_refund',
            'transaction_revision' => 1,
            'linked_purchase_id' => $correctedPurchase->id,
            'created_at' => $assignmentTimestamp,
            'updated_at' => $assignmentTimestamp,
        ],
        [
            'user_id' => $owner->id,
            'transaction_id' => $correctedPurchase->id,
            'category_id' => $ownerCategory->id,
            'source' => 'owner',
            'transaction_revision' => 2,
            'linked_purchase_id' => null,
            'created_at' => $assignmentTimestamp,
            'updated_at' => $assignmentTimestamp,
        ],
        [
            'user_id' => $owner->id,
            'transaction_id' => $ownerDerivedRefund->id,
            'category_id' => $ownerCategory->id,
            'source' => 'linked_refund',
            'transaction_revision' => 1,
            'linked_purchase_id' => $correctedPurchase->id,
            'created_at' => $assignmentTimestamp,
            'updated_at' => $assignmentTimestamp,
        ],
    ]);

    $classifierPayload = json_encode([
        'displayName' => 'App\\Jobs\\ClassifyTransaction',
    ], JSON_THROW_ON_ERROR);
    $unrelatedPayload = json_encode([
        'displayName' => 'App\\Jobs\\UnrelatedJob',
    ], JSON_THROW_ON_ERROR);

    DB::table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => $classifierPayload,
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ],
        [
            'queue' => 'default',
            'payload' => $unrelatedPayload,
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ],
    ]);
    DB::table('failed_jobs')->insert([
        [
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => $classifierPayload,
            'exception' => 'classifier failure',
            'failed_at' => now(),
        ],
        [
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => $unrelatedPayload,
            'exception' => 'unrelated failure',
            'failed_at' => now(),
        ],
    ]);

    $migration = require database_path('migrations/2026_08_10_141311_uncategorize_ai_assigned_transactions.php');
    $migration->up();

    expect($purchase->refresh())
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->revision->toBe(2)
        ->and($refund->refresh())
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->revision->toBe(2)
        ->and($historicallyAiDerivedRefund->refresh())
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull()
        ->revision->toBe(2)
        ->and($correctedPurchase->refresh())
        ->category_id->toBe($ownerCategory->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->and($ownerDerivedRefund->refresh())
        ->category_id->toBe($ownerCategory->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::LinkedRefund)
        ->and($ownerAssignedTransaction->refresh())
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::Owner)
        ->and(DB::table('category_assignments')->whereIn('transaction_id', [
            $purchase->id,
            $refund->id,
            $historicallyAiDerivedRefund->id,
        ])->exists())
        ->toBeFalse()
        ->and(DB::table('jobs')->pluck('payload')->all())
        ->toBe([$unrelatedPayload])
        ->and(DB::table('failed_jobs')->pluck('payload')->all())
        ->toBe([$unrelatedPayload]);
});

test('a Transaction without a deterministic rule stays Uncategorized in the Review Queue', function () {
    Queue::fake();
    $owner = User::factory()->create();

    $transaction = recordTransactionWithoutAi($owner, 'Unmatched merchant');

    expect($transaction)
        ->category_id->toBeNull()
        ->category_assignment_provenance->toBeNull();
    Queue::assertNothingPushed();

    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_category_count', 1));
});

test('a deterministic Learned Rule still categorizes a future Transaction', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $category = Category::factory()->for($owner, 'owner')->create();
    $rule = LearnedRule::factory()->for($owner, 'owner')->create();
    $rule->revisions()->create([
        'revision' => 1,
        'category_id' => $category->id,
        'merchant_pattern' => 'Rule Merchant',
        'merchant_key' => app(MerchantNormalizer::class)->normalize('Rule Merchant'),
        'match_mode' => 'exact',
        'transaction_kind' => 'purchase',
        'currency' => 'PEN',
    ]);

    $transaction = recordTransactionWithoutAi($owner, 'Rule Merchant');

    expect($transaction)
        ->category_id->toBe($category->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::LearnedRule);
    Queue::assertNothingPushed();

    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_category_count', 0));
});

function recordTransactionWithoutAi(User $owner, string $merchantDescription): Transaction
{
    return app(RecordManualTransaction::class)->handle(
        owner: $owner,
        occurredOn: CarbonImmutable::parse('2026-08-10'),
        amountMinor: 1_250,
        currency: Currency::Pen,
        kind: TransactionKind::Purchase,
        merchantDescription: $merchantDescription,
    );
}
