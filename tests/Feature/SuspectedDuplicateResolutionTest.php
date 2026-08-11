<?php

use App\Actions\Ledger\MarkSuspectedDuplicate;
use App\CategoryAssignmentProvenance;
use App\Models\Category;
use App\Models\LineItem;
use App\Models\ReceiptBreakdown;
use App\Models\SpendingNotificationReference;
use App\Models\SuspectedDuplicate;
use App\Models\SuspectedDuplicateReceiptBreakdownMove;
use App\Models\SuspectedDuplicateResolution;
use App\Models\SuspectedDuplicateSourceMove;
use App\Models\Transaction;
use App\Models\User;
use App\ReceiptBreakdownFingerprint;
use App\SourceReferenceSetFingerprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function suspectedDuplicateSourceReferenceFingerprint(Transaction $transaction): string
{
    return SourceReferenceSetFingerprint::fromIds(
        $transaction->spendingNotificationReferences()
            ->orderBy('id')
            ->pluck('id'),
    );
}

function suspectedDuplicateReceiptBreakdownFingerprint(Transaction $transaction): string
{
    return ReceiptBreakdownFingerprint::fromBreakdown(
        $transaction->receiptBreakdown()->first(),
    );
}

/**
 * @return array<string, int|string>
 */
function suspectedDuplicateResolutionPayload(
    SuspectedDuplicate $suspectedDuplicate,
    Transaction $survivor,
    string $idempotencyKey,
): array {
    $suspectedDuplicate->load(['firstTransaction', 'secondTransaction']);

    return [
        'survivor_transaction_id' => $survivor->id,
        'expected_suspected_duplicate_revision' => $suspectedDuplicate->revision,
        'expected_first_transaction_revision' => $suspectedDuplicate->firstTransaction->revision,
        'expected_second_transaction_revision' => $suspectedDuplicate->secondTransaction->revision,
        'expected_first_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($suspectedDuplicate->firstTransaction),
        'expected_second_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($suspectedDuplicate->secondTransaction),
        'expected_first_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($suspectedDuplicate->firstTransaction),
        'expected_second_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($suspectedDuplicate->secondTransaction),
        'idempotency_key' => $idempotencyKey,
    ];
}

test('similar Transactions remain distinct and enter review without being merged automatically', function () {
    $owner = User::factory()->create();
    $firstTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-20',
            'amount_minor' => 2_500,
            'merchant_description' => 'Neighborhood market',
        ]);
    $secondTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'occurred_on' => '2026-07-20',
            'amount_minor' => 2_500,
            'merchant_description' => 'NEIGHBORHOOD MARKET',
        ]);
    $firstReference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($firstTransaction)
        ->create();
    $secondReference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($secondTransaction)
        ->create();

    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $firstTransaction,
        secondTransaction: $secondTransaction,
    );

    expect($firstTransaction->refresh()->voided_at)->toBeNull()
        ->and($secondTransaction->refresh()->voided_at)->toBeNull()
        ->and($firstReference->refresh()->transaction_id)->toBe($firstTransaction->id)
        ->and($secondReference->refresh()->transaction_id)->toBe($secondTransaction->id)
        ->and($suspectedDuplicate->resolved_at)->toBeNull();

    $this->actingAs($owner)
        ->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '5000')
            ->has('transactions', 2),
        );

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_suspected_duplicate_count', 1)
            ->has('suspected_duplicates', 1)
            ->where('suspected_duplicates.0.id', $suspectedDuplicate->id)
            ->where('suspected_duplicates.0.first_transaction.id', $firstTransaction->id)
            ->where('suspected_duplicates.0.second_transaction.id', $secondTransaction->id),
        );
});

test('the owner can choose either compatible Transaction as survivor and preserve every source reference', function () {
    $owner = User::factory()->create();
    $firstTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'amount_minor' => 2_500,
            'merchant_description' => 'Market notification',
        ]);
    $secondTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create([
            'amount_minor' => 2_500,
            'merchant_description' => 'Market receipt',
        ]);
    $firstReference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($firstTransaction)
        ->create();
    $secondReference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($secondTransaction)
        ->create();
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $firstTransaction,
        secondTransaction: $secondTransaction,
    );

    $this->actingAs($owner)
        ->post(route('suspected_duplicates.resolution.store', $suspectedDuplicate), [
            'survivor_transaction_id' => $secondTransaction->id,
            'expected_suspected_duplicate_revision' => 1,
            'expected_first_transaction_revision' => 1,
            'expected_second_transaction_revision' => 1,
            'expected_first_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($firstTransaction),
            'expected_second_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($secondTransaction),
            'expected_first_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($firstTransaction),
            'expected_second_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($secondTransaction),
            'idempotency_key' => '78b252e5-fd6f-4f45-a531-edc57a700ae7',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('review_queue.index'));

    expect($firstTransaction->refresh()->voided_at)->not->toBeNull()
        ->and($firstTransaction->revision)->toBe(2)
        ->and($secondTransaction->refresh()->voided_at)->toBeNull()
        ->and($secondTransaction->revision)->toBe(2)
        ->and($firstReference->refresh()->transaction_id)->toBe($secondTransaction->id)
        ->and($secondReference->refresh()->transaction_id)->toBe($secondTransaction->id)
        ->and($suspectedDuplicate->refresh()->survivor_transaction_id)->toBe($secondTransaction->id)
        ->and($suspectedDuplicate->voided_transaction_id)->toBe($firstTransaction->id)
        ->and($suspectedDuplicate->revision)->toBe(2)
        ->and($suspectedDuplicate->resolved_at)->not->toBeNull();

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '2500')
            ->has('transactions', 1)
            ->where('transactions.0.id', $secondTransaction->id)
            ->has('voided_transactions', 1)
            ->where('voided_transactions.0.id', $firstTransaction->id)
            ->where(
                'voided_transactions.0.duplicate_resolution.id',
                $suspectedDuplicate->id,
            ),
        );

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_suspected_duplicate_count', 0)
            ->has('suspected_duplicates', 0),
        );
});

test('repeated or stale duplicate resolutions cannot move evidence or change spending twice', function () {
    $owner = User::factory()->create();
    [$firstTransaction, $secondTransaction] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create(['amount_minor' => 7_500]);
    SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($secondTransaction)
        ->create();
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $firstTransaction,
        secondTransaction: $secondTransaction,
    );
    $payload = [
        'survivor_transaction_id' => $firstTransaction->id,
        'expected_suspected_duplicate_revision' => 1,
        'expected_first_transaction_revision' => 1,
        'expected_second_transaction_revision' => 1,
        'expected_first_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($firstTransaction),
        'expected_second_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($secondTransaction),
        'expected_first_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($firstTransaction),
        'expected_second_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($secondTransaction),
        'idempotency_key' => '23979433-90e5-4fb4-8214-234988eb9aac',
    ];
    $this->actingAs($owner);

    foreach (range(1, 2) as $attempt) {
        $this->post(
            route('suspected_duplicates.resolution.store', $suspectedDuplicate),
            $payload,
        )->assertSessionHasNoErrors();
    }

    $this->from(route('review_queue.index'))
        ->post(route('suspected_duplicates.resolution.store', $suspectedDuplicate), [
            ...$payload,
            'idempotency_key' => '3faf75ba-a315-45fe-8e94-5c45aa3a717f',
        ])
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors('suspected_duplicate_resolution');

    expect($firstTransaction->refresh()->revision)->toBe(2)
        ->and($firstTransaction->voided_at)->toBeNull()
        ->and($secondTransaction->refresh()->revision)->toBe(2)
        ->and($secondTransaction->voided_at)->not->toBeNull()
        ->and($suspectedDuplicate->refresh()->revision)->toBe(2)
        ->and(SuspectedDuplicateResolution::query()->count())->toBe(1)
        ->and(SuspectedDuplicateSourceMove::query()->count())->toBe(1);

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '7500')
            ->has('transactions', 1)
            ->has('voided_transactions', 1),
        );
});

test('changed reuse of a duplicate resolution idempotency key is rejected', function () {
    $owner = User::factory()->create();
    [$firstTransaction, $secondTransaction] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create(['amount_minor' => 7_500]);
    $sourceReference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($secondTransaction)
        ->create();
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $firstTransaction,
        secondTransaction: $secondTransaction,
    );
    $payload = [
        'survivor_transaction_id' => $firstTransaction->id,
        'expected_suspected_duplicate_revision' => 1,
        'expected_first_transaction_revision' => 1,
        'expected_second_transaction_revision' => 1,
        'expected_first_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($firstTransaction),
        'expected_second_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($secondTransaction),
        'expected_first_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($firstTransaction),
        'expected_second_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($secondTransaction),
        'idempotency_key' => '4de328aa-f7f7-44ec-89df-d70bc2c8514f',
    ];
    $this->actingAs($owner)
        ->post(
            route('suspected_duplicates.resolution.store', $suspectedDuplicate),
            $payload,
        )
        ->assertSessionHasNoErrors();

    $this->from(route('review_queue.index'))
        ->post(route('suspected_duplicates.resolution.store', $suspectedDuplicate), [
            ...$payload,
            'survivor_transaction_id' => $secondTransaction->id,
        ])
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors('suspected_duplicate_resolution');

    expect($sourceReference->refresh()->transaction_id)->toBe($firstTransaction->id)
        ->and($firstTransaction->refresh()->voided_at)->toBeNull()
        ->and($secondTransaction->refresh()->voided_at)->not->toBeNull()
        ->and(SuspectedDuplicateResolution::query()->count())->toBe(1)
        ->and(SuspectedDuplicateSourceMove::query()->count())->toBe(1);
});

test('the owner can reopen a resolved pair and restore only the source references that resolution moved', function () {
    $owner = User::factory()->create();
    [$firstTransaction, $secondTransaction] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->purchase()
        ->pen()
        ->create(['amount_minor' => 4_000]);
    $firstReference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($firstTransaction)
        ->create();
    $secondReference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($secondTransaction)
        ->create();
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $firstTransaction,
        secondTransaction: $secondTransaction,
    );
    $this->actingAs($owner)
        ->post(route('suspected_duplicates.resolution.store', $suspectedDuplicate), [
            'survivor_transaction_id' => $firstTransaction->id,
            'expected_suspected_duplicate_revision' => 1,
            'expected_first_transaction_revision' => 1,
            'expected_second_transaction_revision' => 1,
            'expected_first_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($firstTransaction),
            'expected_second_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($secondTransaction),
            'expected_first_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($firstTransaction),
            'expected_second_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($secondTransaction),
            'idempotency_key' => '869cf416-8f57-4639-b6f5-a926b36ac204',
        ])
        ->assertSessionHasNoErrors();

    $reopenPayload = [
        'expected_suspected_duplicate_revision' => 2,
        'expected_first_transaction_revision' => 2,
        'expected_second_transaction_revision' => 2,
        'idempotency_key' => 'd37d5338-6233-4987-ab1b-5217cfc68f8d',
    ];

    $this->from(route('transactions.index'))
        ->post(route('transactions.void.store', $firstTransaction), [
            'expected_revision' => 2,
            'idempotency_key' => '8b353e17-3afb-4505-a851-e559ad2a83ef',
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('void_state');

    $this->from(route('transactions.index'))
        ->delete(route('transactions.void.destroy', $secondTransaction), [
            'expected_revision' => 2,
            'idempotency_key' => '34f8ed9f-cc9c-413d-959b-a909292a720c',
        ])
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('void_state');

    foreach (range(1, 2) as $attempt) {
        $this->delete(
            route('suspected_duplicates.resolution.destroy', $suspectedDuplicate),
            $reopenPayload,
        )
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('review_queue.index'));
    }

    expect($firstTransaction->refresh()->voided_at)->toBeNull()
        ->and($firstTransaction->revision)->toBe(3)
        ->and($secondTransaction->refresh()->voided_at)->toBeNull()
        ->and($secondTransaction->revision)->toBe(3)
        ->and($firstReference->refresh()->transaction_id)->toBe($firstTransaction->id)
        ->and($secondReference->refresh()->transaction_id)->toBe($secondTransaction->id)
        ->and($suspectedDuplicate->refresh()->resolved_at)->toBeNull()
        ->and($suspectedDuplicate->survivor_transaction_id)->toBeNull()
        ->and($suspectedDuplicate->voided_transaction_id)->toBeNull()
        ->and($suspectedDuplicate->revision)->toBe(3)
        ->and(SuspectedDuplicateResolution::query()->count())->toBe(2)
        ->and(SuspectedDuplicateSourceMove::query()->count())->toBe(1);

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.PEN', '8000')
            ->has('transactions', 2)
            ->has('voided_transactions', 0),
        );

    $this->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unresolved_suspected_duplicate_count', 1)
            ->has('suspected_duplicates', 1),
        );
});

test('duplicate resolution moves a compatible Receipt Breakdown whole and reopening restores it', function () {
    $owner = User::factory()->create();
    $survivorCategory = Category::factory()->recycle($owner)->create(['name' => 'Shopping']);
    $lineItemCategory = Category::factory()->recycle($owner)->create(['name' => 'Groceries']);
    [$survivor, $transactionToVoid] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->purchase()
        ->pen()
        ->create([
            'amount_minor' => 4_000,
            'category_id' => $survivorCategory->id,
            'category_assignment_provenance' => CategoryAssignmentProvenance::Owner,
        ]);
    $breakdown = ReceiptBreakdown::factory()
        ->recycle($owner)
        ->for($transactionToVoid)
        ->create();
    $lineItem = LineItem::factory()->for($breakdown)->create([
        'category_id' => $lineItemCategory->id,
        'line_total_minor' => 4_000,
    ]);
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $survivor,
        secondTransaction: $transactionToVoid,
    );
    $this->actingAs($owner)
        ->post(
            route('suspected_duplicates.resolution.store', $suspectedDuplicate),
            suspectedDuplicateResolutionPayload(
                $suspectedDuplicate,
                $survivor,
                '27fab0c1-5e9c-4751-b351-6c34f51d4225',
            ),
        )
        ->assertSessionHasNoErrors();

    expect($breakdown->refresh()->transaction_id)->toBe($survivor->id)
        ->and($lineItem->refresh()->receipt_breakdown_id)->toBe($breakdown->id)
        ->and(SuspectedDuplicateReceiptBreakdownMove::query()->count())->toBe(1);

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.PEN', '4000')
            ->where('category_totals.0.category.name', 'Groceries')
            ->where('category_totals.0.totals.PEN', '4000')
            ->has('transactions', 1));

    $this->delete(
        route('suspected_duplicates.resolution.destroy', $suspectedDuplicate),
        [
            'expected_suspected_duplicate_revision' => 2,
            'expected_first_transaction_revision' => 2,
            'expected_second_transaction_revision' => 2,
            'idempotency_key' => '642828bb-3d7a-4db4-ae74-f1334bfbf110',
        ],
    )->assertSessionHasNoErrors();

    expect($breakdown->refresh()->transaction_id)->toBe($transactionToVoid->id)
        ->and($lineItem->refresh()->receipt_breakdown_id)->toBe($breakdown->id);

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.PEN', '8000')
            ->has('transactions', 2));
});

test('duplicate resolution rejects conflicting Receipt Breakdowns without merging Line Items', function () {
    $owner = User::factory()->create();
    [$survivor, $transactionToVoid] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->purchase()
        ->pen()
        ->create(['amount_minor' => 4_000]);
    $survivorBreakdown = ReceiptBreakdown::factory()
        ->recycle($owner)
        ->for($survivor)
        ->create();
    $voidedBreakdown = ReceiptBreakdown::factory()
        ->recycle($owner)
        ->for($transactionToVoid)
        ->create();
    $survivorItem = LineItem::factory()->for($survivorBreakdown)->create([
        'description' => 'Survivor work',
        'line_total_minor' => 4_000,
    ]);
    $voidedItem = LineItem::factory()->for($voidedBreakdown)->create([
        'description' => 'Other work',
        'line_total_minor' => 4_000,
    ]);
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $survivor,
        secondTransaction: $transactionToVoid,
    );

    $this->actingAs($owner)
        ->from(route('review_queue.index'))
        ->post(
            route('suspected_duplicates.resolution.store', $suspectedDuplicate),
            suspectedDuplicateResolutionPayload(
                $suspectedDuplicate,
                $survivor,
                '376ca064-7388-462a-955b-30535500a740',
            ),
        )
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors('suspected_duplicate_resolution');

    expect($survivor->refresh()->voided_at)->toBeNull()
        ->and($transactionToVoid->refresh()->voided_at)->toBeNull()
        ->and($survivorBreakdown->refresh()->transaction_id)->toBe($survivor->id)
        ->and($voidedBreakdown->refresh()->transaction_id)->toBe($transactionToVoid->id)
        ->and($survivorItem->refresh()->description)->toBe('Survivor work')
        ->and($voidedItem->refresh()->description)->toBe('Other work')
        ->and(SuspectedDuplicateReceiptBreakdownMove::query()->count())->toBe(0);
});

test('reopening duplicate resolution cannot overwrite a newer Receipt Breakdown', function () {
    $owner = User::factory()->create();
    [$survivor, $transactionToVoid] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->purchase()
        ->pen()
        ->create(['amount_minor' => 4_000]);
    $movedBreakdown = ReceiptBreakdown::factory()
        ->recycle($owner)
        ->for($transactionToVoid)
        ->create();
    LineItem::factory()->for($movedBreakdown)->create(['line_total_minor' => 4_000]);
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $survivor,
        secondTransaction: $transactionToVoid,
    );
    $this->actingAs($owner)
        ->post(
            route('suspected_duplicates.resolution.store', $suspectedDuplicate),
            suspectedDuplicateResolutionPayload(
                $suspectedDuplicate,
                $survivor,
                '9bf82fb1-bbb8-4bf8-9098-2cdfa720b381',
            ),
        )
        ->assertSessionHasNoErrors();
    $newerBreakdown = ReceiptBreakdown::factory()
        ->recycle($owner)
        ->for($transactionToVoid)
        ->create();
    LineItem::factory()->for($newerBreakdown)->create(['line_total_minor' => 4_000]);

    $this->from(route('transactions.index'))
        ->delete(
            route('suspected_duplicates.resolution.destroy', $suspectedDuplicate),
            [
                'expected_suspected_duplicate_revision' => 2,
                'expected_first_transaction_revision' => 2,
                'expected_second_transaction_revision' => 2,
                'idempotency_key' => 'a11b6d40-1aa6-488a-95df-5d94daec7adf',
            ],
        )
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasErrors('suspected_duplicate_resolution');

    expect($movedBreakdown->refresh()->transaction_id)->toBe($survivor->id)
        ->and($newerBreakdown->refresh()->transaction_id)->toBe($transactionToVoid->id)
        ->and($survivor->refresh()->voided_at)->toBeNull()
        ->and($transactionToVoid->refresh()->voided_at)->not->toBeNull()
        ->and($suspectedDuplicate->refresh()->resolved_at)->not->toBeNull();
});

test('incompatible Transactions cannot be resolved as duplicates', function () {
    $owner = User::factory()->create();
    $firstTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create(['amount_minor' => 4_000]);
    $secondTransaction = Transaction::factory()
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create(['amount_minor' => 6_000]);
    $sourceReference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($secondTransaction)
        ->create();
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $firstTransaction,
        secondTransaction: $secondTransaction,
    );

    $this->actingAs($owner)
        ->from(route('review_queue.index'))
        ->post(route('suspected_duplicates.resolution.store', $suspectedDuplicate), [
            'survivor_transaction_id' => $firstTransaction->id,
            'expected_suspected_duplicate_revision' => 1,
            'expected_first_transaction_revision' => 1,
            'expected_second_transaction_revision' => 1,
            'expected_first_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($firstTransaction),
            'expected_second_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($secondTransaction),
            'expected_first_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($firstTransaction),
            'expected_second_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($secondTransaction),
            'idempotency_key' => '3345406c-e8b1-483a-b68c-84fe1dd0b864',
        ])
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors('suspected_duplicate_resolution');

    expect($firstTransaction->refresh()->revision)->toBe(1)
        ->and($firstTransaction->voided_at)->toBeNull()
        ->and($secondTransaction->refresh()->revision)->toBe(1)
        ->and($secondTransaction->voided_at)->toBeNull()
        ->and($sourceReference->refresh()->transaction_id)->toBe($secondTransaction->id)
        ->and($suspectedDuplicate->refresh()->revision)->toBe(1)
        ->and(SuspectedDuplicateResolution::query()->count())->toBe(0);
});

test('stale or unauthorized duplicate resolution requests leave both records and evidence unchanged', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->make(['id' => $owner->id + 1]);
    [$firstTransaction, $secondTransaction] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create(['amount_minor' => 1_200]);
    $sourceReference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($secondTransaction)
        ->create();
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $firstTransaction,
        secondTransaction: $secondTransaction,
    );
    $payload = [
        'survivor_transaction_id' => $firstTransaction->id,
        'expected_suspected_duplicate_revision' => 1,
        'expected_first_transaction_revision' => 1,
        'expected_second_transaction_revision' => 1,
        'expected_first_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($firstTransaction),
        'expected_second_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($secondTransaction),
        'expected_first_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($firstTransaction),
        'expected_second_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($secondTransaction),
        'idempotency_key' => '7df38684-dc5e-4992-a9a2-54d6bcff6275',
    ];

    $this->actingAs($otherOwner)
        ->post(
            route('suspected_duplicates.resolution.store', $suspectedDuplicate),
            $payload,
        )
        ->assertForbidden();

    $firstTransaction->update(['revision' => 2]);

    $this->actingAs($owner)
        ->from(route('review_queue.index'))
        ->post(
            route('suspected_duplicates.resolution.store', $suspectedDuplicate),
            $payload,
        )
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors('suspected_duplicate_resolution');

    expect($firstTransaction->refresh()->revision)->toBe(2)
        ->and($firstTransaction->voided_at)->toBeNull()
        ->and($secondTransaction->refresh()->revision)->toBe(1)
        ->and($secondTransaction->voided_at)->toBeNull()
        ->and($sourceReference->refresh()->transaction_id)->toBe($secondTransaction->id)
        ->and($suspectedDuplicate->refresh()->revision)->toBe(1)
        ->and(SuspectedDuplicateResolution::query()->count())->toBe(0)
        ->and(SuspectedDuplicateSourceMove::query()->count())->toBe(0);
});

test('source reference changes invalidate the reviewed duplicate resolution effect', function () {
    $owner = User::factory()->create();
    [$firstTransaction, $secondTransaction] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create(['amount_minor' => 3_300]);
    SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($secondTransaction)
        ->create();
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $firstTransaction,
        secondTransaction: $secondTransaction,
    );
    $reviewedPayload = [
        'survivor_transaction_id' => $firstTransaction->id,
        'expected_suspected_duplicate_revision' => 1,
        'expected_first_transaction_revision' => 1,
        'expected_second_transaction_revision' => 1,
        'expected_first_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($firstTransaction),
        'expected_second_source_reference_fingerprint' => suspectedDuplicateSourceReferenceFingerprint($secondTransaction),
        'expected_first_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($firstTransaction),
        'expected_second_receipt_breakdown_fingerprint' => suspectedDuplicateReceiptBreakdownFingerprint($secondTransaction),
        'idempotency_key' => '918c6397-d325-4494-9cad-512a299a791d',
    ];
    $newSourceReference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($secondTransaction)
        ->create();

    $this->actingAs($owner)
        ->from(route('review_queue.index'))
        ->post(
            route('suspected_duplicates.resolution.store', $suspectedDuplicate),
            $reviewedPayload,
        )
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors('suspected_duplicate_resolution');

    expect($firstTransaction->refresh()->revision)->toBe(1)
        ->and($firstTransaction->voided_at)->toBeNull()
        ->and($secondTransaction->refresh()->revision)->toBe(1)
        ->and($secondTransaction->voided_at)->toBeNull()
        ->and($newSourceReference->refresh()->transaction_id)->toBe($secondTransaction->id)
        ->and($suspectedDuplicate->refresh()->revision)->toBe(1)
        ->and(SuspectedDuplicateResolution::query()->count())->toBe(0)
        ->and(SuspectedDuplicateSourceMove::query()->count())->toBe(0);
});

test('Receipt Breakdown changes invalidate the reviewed duplicate resolution effect', function () {
    $owner = User::factory()->create();
    [$firstTransaction, $secondTransaction] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->purchase()
        ->pen()
        ->create(['amount_minor' => 3_300]);
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $firstTransaction,
        secondTransaction: $secondTransaction,
    );
    $reviewedPayload = suspectedDuplicateResolutionPayload(
        $suspectedDuplicate,
        $firstTransaction,
        'd3217948-c26a-45c6-9a9c-1f5ff8d7864e',
    );
    $newerBreakdown = ReceiptBreakdown::factory()
        ->recycle($owner)
        ->for($secondTransaction)
        ->create();
    LineItem::factory()->for($newerBreakdown)->create(['line_total_minor' => 3_300]);

    $this->actingAs($owner)
        ->from(route('review_queue.index'))
        ->post(
            route('suspected_duplicates.resolution.store', $suspectedDuplicate),
            $reviewedPayload,
        )
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors('suspected_duplicate_resolution');

    expect($firstTransaction->refresh()->voided_at)->toBeNull()
        ->and($secondTransaction->refresh()->voided_at)->toBeNull()
        ->and($newerBreakdown->refresh()->transaction_id)->toBe($secondTransaction->id)
        ->and($suspectedDuplicate->refresh()->resolved_at)->toBeNull()
        ->and(SuspectedDuplicateReceiptBreakdownMove::query()->count())->toBe(0);
});

test('a duplicate resolution cannot void the survivor of another resolved pair', function () {
    $owner = User::factory()->create();
    [$firstTransaction, $sharedTransaction, $thirdTransaction] = Transaction::factory()
        ->count(3)
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create(['amount_minor' => 5_000]);
    foreach ([$firstTransaction, $sharedTransaction, $thirdTransaction] as $transaction) {
        SpendingNotificationReference::factory()
            ->for($owner, 'owner')
            ->for($transaction)
            ->create();
    }
    $firstPair = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $firstTransaction,
        secondTransaction: $sharedTransaction,
    );
    $secondPair = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $sharedTransaction,
        secondTransaction: $thirdTransaction,
    );
    $this->actingAs($owner)
        ->post(
            route('suspected_duplicates.resolution.store', $firstPair),
            suspectedDuplicateResolutionPayload(
                $firstPair,
                $sharedTransaction,
                'abf2011d-d510-423c-91b6-1f95def71bbb',
            ),
        )
        ->assertSessionHasNoErrors();

    $this->from(route('review_queue.index'))
        ->post(
            route('suspected_duplicates.resolution.store', $secondPair),
            suspectedDuplicateResolutionPayload(
                $secondPair->refresh(),
                $thirdTransaction->refresh(),
                'f6fc6eb4-e3cf-49d0-8fea-6eec3376617c',
            ),
        )
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors('suspected_duplicate_resolution');

    expect($firstTransaction->refresh()->voided_at)->not->toBeNull()
        ->and($sharedTransaction->refresh()->voided_at)->toBeNull()
        ->and($thirdTransaction->refresh()->voided_at)->toBeNull()
        ->and($firstPair->refresh()->survivor_transaction_id)->toBe($sharedTransaction->id)
        ->and($secondPair->refresh()->resolved_at)->toBeNull();

    $this->post(
        route('suspected_duplicates.resolution.store', $secondPair),
        suspectedDuplicateResolutionPayload(
            $secondPair,
            $sharedTransaction,
            '97a613b8-d121-4826-9aa8-41bf97e287cf',
        ),
    )->assertSessionHasNoErrors();

    expect($thirdTransaction->refresh()->voided_at)->not->toBeNull()
        ->and($secondPair->refresh()->survivor_transaction_id)->toBe($sharedTransaction->id)
        ->and(SpendingNotificationReference::query()
            ->where('transaction_id', $sharedTransaction->id)
            ->count())->toBe(3);

    $this->get(route('transactions.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.USD', '5000')
            ->has('transactions', 1)
            ->has('voided_transactions', 2),
        );
});

test('dependent Refund relationships make only safe survivor choices available', function () {
    $owner = User::factory()->create();
    [$firstPurchase, $purchaseWithRefund] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create(['amount_minor' => 8_000]);
    $refund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'amount_minor' => 1_000,
            'original_purchase_id' => $purchaseWithRefund->id,
        ]);
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $firstPurchase,
        secondTransaction: $purchaseWithRefund,
    );
    $this->actingAs($owner)
        ->get(route('review_queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('suspected_duplicates.0.first_transaction.has_linked_refunds', false)
            ->where('suspected_duplicates.0.second_transaction.has_linked_refunds', true),
        );

    $this->from(route('review_queue.index'))
        ->post(
            route('suspected_duplicates.resolution.store', $suspectedDuplicate),
            suspectedDuplicateResolutionPayload(
                $suspectedDuplicate,
                $firstPurchase,
                'dfd58403-c347-4daa-983f-1f9cf75dd5fe',
            ),
        )
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors('suspected_duplicate_resolution');

    $this->post(
        route('suspected_duplicates.resolution.store', $suspectedDuplicate),
        suspectedDuplicateResolutionPayload(
            $suspectedDuplicate->refresh(),
            $purchaseWithRefund,
            'e501223a-4399-4ad5-8d34-6e66e4f34311',
        ),
    )->assertSessionHasNoErrors();

    expect($firstPurchase->refresh()->voided_at)->not->toBeNull()
        ->and($purchaseWithRefund->refresh()->voided_at)->toBeNull()
        ->and($refund->refresh()->original_purchase_id)->toBe($purchaseWithRefund->id);
});

test('Refunds with different original purchase relationships are incompatible duplicates', function () {
    $owner = User::factory()->create();
    [$firstPurchase, $secondPurchase] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->purchase()
        ->usd()
        ->create();
    $firstRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'amount_minor' => 2_000,
            'original_purchase_id' => $firstPurchase->id,
        ]);
    $secondRefund = Transaction::factory()
        ->for($owner, 'owner')
        ->refund()
        ->usd()
        ->create([
            'amount_minor' => 2_000,
            'original_purchase_id' => $secondPurchase->id,
        ]);
    $suspectedDuplicate = app(MarkSuspectedDuplicate::class)->handle(
        owner: $owner,
        firstTransaction: $firstRefund,
        secondTransaction: $secondRefund,
    );

    $this->actingAs($owner)
        ->from(route('review_queue.index'))
        ->post(
            route('suspected_duplicates.resolution.store', $suspectedDuplicate),
            suspectedDuplicateResolutionPayload(
                $suspectedDuplicate,
                $firstRefund,
                '72244d68-9eaa-4eaf-871b-a3297053e77a',
            ),
        )
        ->assertRedirect(route('review_queue.index'))
        ->assertSessionHasErrors('suspected_duplicate_resolution');

    expect($firstRefund->refresh()->voided_at)->toBeNull()
        ->and($secondRefund->refresh()->voided_at)->toBeNull()
        ->and($suspectedDuplicate->refresh()->resolved_at)->toBeNull();
});

test('PostgreSQL protects source identity and canonical Suspected Duplicate pairs', function () {
    $owner = User::factory()->create();
    [$firstTransaction, $secondTransaction] = Transaction::factory()
        ->count(2)
        ->for($owner, 'owner')
        ->create();
    $reference = SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($firstTransaction)
        ->create([
            'gmail_account_identity' => 'owner@example.test',
            'message_id' => 'immutable-message-id',
        ]);

    expect(fn () => DB::transaction(fn () => SpendingNotificationReference::factory()
        ->for($owner, 'owner')
        ->for($secondTransaction)
        ->create([
            'gmail_account_identity' => $reference->gmail_account_identity,
            'message_id' => $reference->message_id,
        ])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => SuspectedDuplicate::create([
            'user_id' => $owner->id,
            'first_transaction_id' => $secondTransaction->id,
            'second_transaction_id' => $firstTransaction->id,
        ])))->toThrow(QueryException::class);
});
