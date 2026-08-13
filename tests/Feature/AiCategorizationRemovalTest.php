<?php

use App\Actions\Ledger\RecordManualTransaction;
use App\CategoryAssignmentProvenance;
use App\Currency;
use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
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

test('AI categorization persistence, assignment history, and Category guidance are absent', function () {
    foreach ([
        'ai_category_proposals',
        'ai_classification_requests',
        'ai_classification_validation_contexts',
        'category_assignments',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Schema::hasColumn('categories', 'description'))->toBeFalse()
        ->and(Schema::hasColumn('categories', 'examples'))->toBeFalse()
        ->and(collect(CategoryAssignmentProvenance::cases())->pluck('value'))
        ->not->toContain('ai');
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

test('a deterministic Merchant Rule still categorizes a future Transaction', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $category = Category::factory()->create();
    $merchantRule = MerchantRule::factory()->for($category)->create([
        'category_id' => $category->id,
        'merchant' => 'Rule Merchant',
        'merchant_key' => app(MerchantNormalizer::class)->normalize('Rule Merchant'),
        'transaction_kind' => 'purchase',
        'currency' => 'PEN',
    ]);

    $transaction = recordTransactionWithoutAi($owner, 'Rule Merchant');

    expect($transaction)
        ->category_id->toBe($category->id)
        ->merchant_rule_id->toBe($merchantRule->id)
        ->category_assignment_provenance->toBe(CategoryAssignmentProvenance::MerchantRule);
    Queue::assertNothingPushed();

    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_category_count', 0));
});

function recordTransactionWithoutAi(User $owner, string $merchantDescription): Transaction
{
    return app(RecordManualTransaction::class)->handle(
        occurredOn: CarbonImmutable::parse('2026-08-10'),
        amountMinor: 1_250,
        currency: Currency::Pen,
        kind: TransactionKind::Purchase,
        merchantDescription: $merchantDescription,
    );
}
